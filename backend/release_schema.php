<?php
// Database changes for: pay-on-release, manual release date, cancellation, e-signature, email log, audit log, semester archive.
// Safe to run many times: every step checks first. Called by database/install.php and (once, marker file) by backend/database.php.
// The same changes are written as plain SQL in database/migrations/001_release_and_archive.sql.

function release_schema_apply(PDO $pdo) {
    $has = function ($sql, $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    };
    $tableExists = fn($t) => $has('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    $columnExists = fn($t, $c) => $has('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    $indexExists = fn($t, $i) => $has('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $i]);
    $columnType = function ($t, $c) use ($pdo) {
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$t, $c]);
        return (string)$stmt->fetchColumn();
    };
    $addColumn = function ($t, $c, $definition) use ($pdo, $tableExists, $columnExists) {
        if ($tableExists($t) && !$columnExists($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $definition");
    };

    if (!$tableExists('requests')) return;       // not installed yet: install.php creates the tables first, then calls this again

    // ---- requests ----
    $type = $columnType('requests', 'status');
    if ($type !== '' && strpos($type, "'CANCELLED'") === false) {
        $pdo->exec("ALTER TABLE requests MODIFY status ENUM('SUBMITTED','AI_VERIFYING','FOR_REVIEW','NEEDS_CORRECTION','PROCESSING','READY','COMPLETED','REJECTED','CANCELLED') NOT NULL DEFAULT 'SUBMITTED'");
    }
    $type = $columnType('requests', 'payment_status');
    if ($type !== '' && strpos($type, "'Refunded'") === false) {
        $pdo->exec("ALTER TABLE requests MODIFY payment_status ENUM('Unpaid','Paid','Refunded') NOT NULL DEFAULT 'Unpaid'");
    }
    // UPON_RELEASE = pay when claiming (new default). ADVANCE = the old receipt-first flow; old requests keep it so nothing changes for them.
    if (!$columnExists('requests', 'payment_term')) {
        $pdo->exec("ALTER TABLE requests ADD COLUMN payment_term ENUM('UPON_RELEASE','ADVANCE') NOT NULL DEFAULT 'ADVANCE' AFTER payment_method");
        $pdo->exec("ALTER TABLE requests MODIFY payment_term ENUM('UPON_RELEASE','ADVANCE') NOT NULL DEFAULT 'UPON_RELEASE'");
    }
    $addColumn('requests', 'expected_release_date', 'DATE NULL AFTER target_end');
    $addColumn('requests', 'ready_at', 'DATETIME NULL AFTER completed_at');
    $addColumn('requests', 'cancelled_at', 'DATETIME NULL AFTER ready_at');
    $addColumn('requests', 'cancel_reason', 'VARCHAR(255) NULL AFTER cancelled_at');
    $addColumn('requests', 'submission_token', 'VARCHAR(64) NULL AFTER tracking_no');
    if (!$indexExists('requests', 'uq_requests_submission')) $pdo->exec('ALTER TABLE requests ADD UNIQUE KEY uq_requests_submission (submission_token)');
    if (!$indexExists('requests', 'idx_requests_ready')) $pdo->exec('ALTER TABLE requests ADD KEY idx_requests_ready (status, payment_status, ready_at)');
    // Requests that were already READY before this upgrade: the history says when they became ready.
    $pdo->exec("UPDATE requests r SET r.ready_at = (SELECT MAX(h.created_at) FROM request_history h WHERE h.request_id = r.id AND h.new_status = 'READY')
                WHERE r.status IN ('READY','COMPLETED') AND r.ready_at IS NULL");

    // ---- payments: refund information (the payment row is never deleted) ----
    $addColumn('payments', 'refunded_at', 'DATETIME NULL');
    $addColumn('payments', 'refunded_by', 'INT UNSIGNED NULL');
    $addColumn('payments', 'refund_reason', 'VARCHAR(255) NULL');

    // ---- one drawn signature per request. No update/delete code exists for it, and request_id is UNIQUE. ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS request_signatures (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        signed_name VARCHAR(160) NOT NULL,
        signed_ip VARCHAR(45) NULL,
        signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_signature_request (request_id),
        CONSTRAINT fk_signature_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- audit log: who did what to which record ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(40) NOT NULL,
        entity VARCHAR(30) NOT NULL,
        entity_id INT UNSIGNED NULL,
        request_id INT UNSIGNED NULL,
        user_id INT UNSIGNED NULL,
        old_value VARCHAR(255) NULL,
        new_value VARCHAR(255) NULL,
        details VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_request (request_id, id),
        KEY idx_audit_entity (entity, entity_id),
        KEY idx_audit_action (action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- email history. UNIQUE(request_id, kind, event_key) is what stops a second "ready for pickup" email. ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        kind VARCHAR(30) NOT NULL,
        event_key VARCHAR(40) NOT NULL,
        to_email VARCHAR(190) NOT NULL,
        status ENUM('PENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        error_message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        UNIQUE KEY uq_email_event (request_id, kind, event_key),
        CONSTRAINT fk_email_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- semester master lists and their validation records (active vs archived) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS master_list_imports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        academic_year VARCHAR(9) NOT NULL,
        semester TINYINT UNSIGNED NOT NULL,
        period_key INT UNSIGNED NOT NULL,
        file_sha256 CHAR(64) NOT NULL,
        row_count INT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
        imported_by INT UNSIGNED NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        archived_by INT UNSIGNED NULL,
        archive_note VARCHAR(255) NULL,
        UNIQUE KEY uq_master_period (period_key),
        UNIQUE KEY uq_master_file (file_sha256),
        KEY idx_master_status (status),
        CONSTRAINT fk_master_importer FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_master_archiver FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS validation_records (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        import_id INT UNSIGNED NOT NULL,
        student_no VARCHAR(30) NOT NULL,
        student_name VARCHAR(150) NOT NULL,
        course VARCHAR(100) NULL,
        year_level VARCHAR(30) NULL,
        section VARCHAR(30) NULL,
        validation_status ENUM('VALIDATED','NOT_REGISTERED','MISMATCH','INVALID') NOT NULL,
        validation_note VARCHAR(255) NULL,
        validated_at DATETIME NOT NULL,
        UNIQUE KEY uq_validation_student (import_id, student_no),
        KEY idx_validation_student_no (student_no),
        KEY idx_validation_status (validation_status),
        CONSTRAINT fk_validation_import FOREIGN KEY (import_id) REFERENCES master_list_imports (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- email codes (OTP) that prove a guest owns the mailbox ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  student_no VARCHAR(30) NULL,
  code_hash CHAR(64) NOT NULL,
  payload_json TEXT NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp_email (email, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- college only: hide non-college levels without deleting anything (backend/college_only.php) ----
    require_once __DIR__ . '/college_only.php';
    college_only_apply($pdo);

    // ---- rate limiting (tracking lookups, guessing protection) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        bucket VARCHAR(120) NOT NULL PRIMARY KEY,
        window_start DATETIME NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- Optional extra protection: the database itself refuses to change a signature or an archived validation record. ----
    // Needs the TRIGGER privilege. Some shared hosts do not allow it: then the application code is the only protection, and that is fine.
    $triggers = [
        'trg_signature_no_update'  => "CREATE TRIGGER trg_signature_no_update BEFORE UPDATE ON request_signatures FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A submitted signature cannot be changed.'",
        'trg_validation_no_update' => "CREATE TRIGGER trg_validation_no_update BEFORE UPDATE ON validation_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Validation records are read-only.'",
        'trg_validation_no_delete' => "CREATE TRIGGER trg_validation_no_delete BEFORE DELETE ON validation_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Validation records cannot be deleted.'",
    ];
    foreach ($triggers as $name => $sql) {
        try {
            if (!$has('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$name])) $pdo->exec($sql);
        } catch (Throwable $e) {
            write_log("Trigger $name was not created (" . $e->getMessage() . '). The application code still protects the data.');
        }
    }
}
