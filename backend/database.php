<?php
// One shared database connection (PDO) for the whole project.
require_once __DIR__ . '/helpers.php';

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306')
         . ';dbname=' . env('DB_NAME', 'colm_registrar') . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        write_log('Database connection failed: ' . $e->getMessage());
        fail('Database connection failed. Check the DB_* values in your .env file.', 500);
    }
    // MySQL must use the same timezone as PHP, so NOW() and PHP dates agree (the 14-day rule depends on it).
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    ensure_schema_upgrades($pdo);
    ensure_verification_table($pdo);
    ensure_item_purpose_column($pdo);
    ensure_release_schema($pdo);
    return $pdo;
}

// An OLD database (installed before the newest version) gets the new tables/columns automatically the first time the site is used,
// so nobody is locked out when the files are replaced without running "php database/install.php" again. Safe to run many times.
// When everything is in place a small marker file is written and this does nothing any more.
function ensure_schema_upgrades($pdo) {
    $marker = __DIR__ . '/../storage/.schema_v10';
    if (is_file($marker)) return;
    try {
        $markerDirectory = dirname($marker);
        if (!is_dir($markerDirectory) && !mkdir($markerDirectory, 0775, true) && !is_dir($markerDirectory)) {
            throw new RuntimeException('Could not create the storage directory for schema upgrade markers.');
        }
        $has = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn() > 0; };
        if (!$has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")) return;   // not installed yet
        if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at'")) {
            $pdo->exec('ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash');
        }
        if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_approved'")) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active');
        }
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'")
            && !$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'credential_form_json'")) {
            $pdo->exec('ALTER TABLE requests ADD COLUMN credential_form_json LONGTEXT NULL AFTER year_graduated');
        }
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'")
            && !$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'student_verification_form_json'")) {
            $pdo->exec('ALTER TABLE requests ADD COLUMN student_verification_form_json LONGTEXT NULL AFTER credential_form_json');
        }
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'")
            && $has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requirements'")) {
            $certifications = $pdo->query("SELECT id, name FROM documents WHERE category = 'Certification'")->fetchAll(PDO::FETCH_ASSOC);
            $hasStudentIdRequirement = $pdo->prepare("SELECT COUNT(*) FROM document_requirements
                WHERE document_id = ? AND name LIKE '%Student%' AND (name LIKE '%Verification%' OR name LIKE '%Identification%')");
            $addStudentIdRequirement = $pdo->prepare("INSERT INTO document_requirements
                (document_id, name, description, ai_hint, keywords, sort_order)
                VALUES (?, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0)");
            foreach ($certifications as $document) {
                $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', $document['name']));
                if (strpos($normalizedName, 'transcriptofrecords') === 0 || $normalizedName === 'honorabledismissal'
                    || strpos($normalizedName, 'form137') === 0) continue;
                $hasStudentIdRequirement->execute([$document['id']]);
                if (!(int)$hasStudentIdRequirement->fetchColumn()) $addStudentIdRequirement->execute([$document['id']]);
            }
        }
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'")
            && !$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'academic_year'")) {
            $pdo->exec('ALTER TABLE students ADD COLUMN academic_year VARCHAR(9) NULL AFTER status');
        }
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'")) {
            if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'target_type'")) {
                $pdo->exec('ALTER TABLE notifications ADD COLUMN target_type VARCHAR(30) NULL AFTER request_id');
            }
            if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'target_id'")) {
                $pdo->exec('ALTER TABLE notifications ADD COLUMN target_id INT UNSIGNED NULL AFTER target_type');
            }
            $pdo->exec("UPDATE notifications n
                        JOIN users u ON n.message LIKE CONCAT('%(', u.username, ') registered%')
                        SET n.target_type = 'student', n.target_id = u.id
                        WHERE n.target_type IS NULL AND n.title = 'Student account awaiting approval' AND u.role = 'student'");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, username VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_login_ip (ip, created_at), KEY idx_login_user (ip, username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            user_id INT UNSIGNED PRIMARY KEY, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_reset_token_hash (token_hash), KEY idx_password_reset_expiry (expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_password_reset_requests_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS upload_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_upload_attempts (user_id, created_at),
            CONSTRAINT fk_upload_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'")) {
            if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'reference_no'")) {
                $pdo->exec('ALTER TABLE payments ADD COLUMN reference_no VARCHAR(40) NULL AFTER method');
            }
            if (!$has("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'uq_payments_reference'")) {
                $pdo->exec('ALTER TABLE payments ADD UNIQUE KEY uq_payments_reference (reference_no)');
            }
        }
        if (file_put_contents($marker, date('c')) === false) {
            throw new RuntimeException('Could not write the schema upgrade marker.');
        }
    } catch (Throwable $e) {
        write_log('Automatic database upgrade failed: ' . $e->getMessage() . ' (run: php database/install.php)');
    }
}

// Run a query with ? placeholders and return the statement.
function query($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_one($sql, $params = []) {
    $row = query($sql, $params)->fetch();
    return $row ?: null;
}

function fetch_all($sql, $params = []) {
    return query($sql, $params)->fetchAll();
}


// Table for the request-level AI verification (backend/verification.php). Created automatically on an existing database,
// so nobody has to run "php database/install.php" again. A marker file stops it from running on every page.
function ensure_verification_table($pdo) {
    $marker = __DIR__ . '/../storage/.schema_verification_v1';
    if (is_file($marker)) return;
    try {
        $has = function ($table) use ($pdo) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        };
        if (!$has('requests')) return;      // not installed yet; the installer creates the table
        $pdo->exec("CREATE TABLE IF NOT EXISTS request_verifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            route ENUM('REGISTRAR','AUTO') NOT NULL,
            verdict ENUM('PASS','REVIEW','NEEDS_CORRECTION') NOT NULL,
            summary VARCHAR(500) NOT NULL,
            checks_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_verifications_request (request_id, id),
            CONSTRAINT fk_verifications_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!is_dir(dirname($marker))) mkdir(dirname($marker), 0775, true);
        file_put_contents($marker, date('c'));
    } catch (Throwable $e) {
        write_log('Could not create request_verifications: ' . $e->getMessage());
    }
}


// Each requested document has its own purpose (request_items.purpose). Added automatically on an existing database.
function ensure_item_purpose_column($pdo) {
    $marker = __DIR__ . '/../storage/.schema_item_purpose_v1';
    if (is_file($marker)) return;
    try {
        $count = function ($table, $column = null) use ($pdo) {
            $sql = 'SELECT COUNT(*) FROM information_schema.' . ($column === null ? 'TABLES' : 'COLUMNS')
                 . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?' . ($column === null ? '' : ' AND COLUMN_NAME = ?');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($column === null ? [$table] : [$table, $column]);
            return (int)$stmt->fetchColumn();
        };
        if (!$count('request_items')) return;      // not installed yet; the installer creates the column
        if (!$count('request_items', 'purpose')) {
            $pdo->exec('ALTER TABLE request_items ADD COLUMN purpose VARCHAR(255) NULL AFTER unit_price');
        }
        if (!is_dir(dirname($marker))) mkdir(dirname($marker), 0775, true);
        file_put_contents($marker, date('c'));
    } catch (Throwable $e) {
        write_log('Could not add request_items.purpose: ' . $e->getMessage());
    }
}

// Pay-on-release, manual release date, cancellation, e-signature, audit/email log and semester archive tables (backend/release_schema.php).
// Created automatically on an existing database; a marker file stops it from running on every page.
function ensure_release_schema($pdo) {
    $marker = __DIR__ . '/../storage/.schema_release_v1';
    if (is_file($marker)) return;
    try {
        require_once __DIR__ . '/release_schema.php';
        release_schema_apply($pdo);
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'validation_records'")->fetchColumn();
        if ($tables === 0) return;          // the site is not installed yet: try again next time
        if (!is_dir(dirname($marker))) mkdir(dirname($marker), 0775, true);
        file_put_contents($marker, date('c'));
    } catch (Throwable $e) {
        write_log('Could not apply the release/archive schema: ' . $e->getMessage() . ' (run: php database/install.php)');
    }
}
