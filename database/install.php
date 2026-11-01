<?php
// Creates (or updates) the database, the tables, the document catalog and the first accounts.
// Run it:   php database/install.php      (or open it in the browser while APP_ENV=development)
// It is safe to run again and again: it never deletes data, and it upgrades an older database
// (for example it disables old cashier/personnel accounts and adds the new columns).
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/students.php';      // for default_school_structure() (the starting list of levels, courses, years, sections)

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    if (is_production()) { http_response_code(403); exit('Run this from the command line in production.'); }
    header('Content-Type: text/plain; charset=utf-8');
}

function say($text) { echo $text . "\n"; }

// Runs every statement of an SQL text (statements end with ";" at the end of a line).
function run_sql_script($server, $script) {
    foreach (preg_split('/;\s*\n/', $script) as $statement) {
        $lines = array_filter(explode("\n", $statement), fn($l) => !str_starts_with(trim($l), '--'));
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') $server->exec($statement);
    }
}

function column_exists($server, $dbName, $table, $column) {
    $stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$dbName, $table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function table_exists($server, $dbName, $table) {
    $stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$dbName, $table]);
    return (int)$stmt->fetchColumn() > 0;
}

// Changes an older database to the current structure. Every step checks first, so it can run twice.
function upgrade_old_database($server, $dbName) {
    $addColumn = function ($table, $column, $definition) use ($server, $dbName) {
        if (table_exists($server, $dbName, $table) && !column_exists($server, $dbName, $table, $column)) {
            $server->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            say("Added column $table.$column");
        }
    };
    $addColumn('users', 'password_changed_at', 'DATETIME NULL AFTER password_hash');
    $addColumn('users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    $addColumn('users', 'is_approved', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active');
    $addColumn('documents', 'category', "VARCHAR(60) NOT NULL DEFAULT 'Certification' AFTER description");
    $addColumn('document_requirements', 'requires_name', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required');
    $addColumn('document_requirements', 'keywords', 'VARCHAR(255) NULL AFTER ai_hint');
    $addColumn('ai_verifications', 'name_on_document', 'VARCHAR(150) NULL AFTER confidence');
    $addColumn('ai_verifications', 'name_match_score', 'TINYINT UNSIGNED NULL AFTER name_on_document');
    $addColumn('ai_verifications', 'auto_declined', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER name_match_score');
    $addColumn('students', 'school_level', 'VARCHAR(30) NULL AFTER user_id');
    $addColumn('students', 'status', "ENUM('currently_enrolled','alumni','undergrad') NOT NULL DEFAULT 'currently_enrolled' AFTER section");
    $addColumn('students', 'academic_year', 'VARCHAR(9) NULL AFTER status');
    $addColumn('payments', 'reference_no', 'VARCHAR(40) NULL AFTER method');
    $addColumn('requests', 'academic_year', 'VARCHAR(20) NULL AFTER total_amount');
    $addColumn('requests', 'semester', 'VARCHAR(20) NULL AFTER academic_year');
    $addColumn('requests', 'program', 'VARCHAR(100) NULL AFTER semester');
    $addColumn('requests', 'year_graduated', 'SMALLINT UNSIGNED NULL AFTER program');
    $addColumn('requests', 'credential_form_json', 'LONGTEXT NULL AFTER year_graduated');
    $addColumn('requests', 'student_verification_form_json', 'LONGTEXT NULL AFTER credential_form_json');
    $addColumn('payment_receipts', 'official_receipt_no', 'VARCHAR(40) NULL');
    $addColumn('payment_receipts', 'amount_paid', 'DECIMAL(10,2) NULL');
    $addColumn('payment_receipts', 'date_paid', 'DATE NULL');
    $addColumn('payment_receipts', 'file_hash', 'CHAR(64) NULL');
    $addColumn('payment_receipts', 'security_flags', 'TEXT NULL');
    $addColumn('notifications', 'target_type', 'VARCHAR(30) NULL AFTER request_id');
    $addColumn('notifications', 'target_id', 'INT UNSIGNED NULL AFTER target_type');
    if (table_exists($server, $dbName, 'payments') && column_exists($server, $dbName, 'payments', 'reference_no')) {
        $has = $server->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'payments' AND INDEX_NAME = 'uq_payments_reference'")->fetchColumn();
        if (!$has) $server->exec('ALTER TABLE payments ADD UNIQUE KEY uq_payments_reference (reference_no)');
    }
    $addColumn('requests', 'qr_code', 'VARCHAR(255) NULL AFTER total_amount');
    if (table_exists($server, $dbName, 'requests') && column_exists($server, $dbName, 'requests', 'qr_code')) {
        $has = $server->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'requests' AND INDEX_NAME = 'uq_requests_qr'")->fetchColumn();
        if (!$has) $server->exec('ALTER TABLE requests ADD UNIQUE KEY uq_requests_qr (qr_code)');
    }

    // Softcopy (PDF) release: new columns on request_items, and "Both" as a document format.
    $addColumn('request_items', 'purpose', 'VARCHAR(255) NULL AFTER unit_price');
    $addColumn('request_items', 'softcopy_status', "ENUM('NONE','UPLOADED','READY') NOT NULL DEFAULT 'NONE'");
    $addColumn('request_items', 'softcopy_uploaded_by', 'INT UNSIGNED NULL');
    $addColumn('request_items', 'softcopy_uploaded_at', 'DATETIME NULL');
    $addColumn('request_items', 'softcopy_ready_at', 'DATETIME NULL');
    $addColumn('request_items', 'download_limit', 'TINYINT UNSIGNED NOT NULL DEFAULT 1');
    $addColumn('request_items', 'download_count', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $addColumn('request_items', 'last_downloaded_at', 'DATETIME NULL');
    if (table_exists($server, $dbName, 'request_items')) {
        $has = $server->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'request_items' AND CONSTRAINT_NAME = 'fk_items_uploader'")->fetchColumn();
        if (!$has) $server->exec('ALTER TABLE request_items ADD CONSTRAINT fk_items_uploader FOREIGN KEY (softcopy_uploaded_by) REFERENCES users (id) ON DELETE SET NULL');
    }
    if (table_exists($server, $dbName, 'requests')) {
        $type = (string)$server->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'release_method'")->fetchColumn();
        if ($type !== '' && strpos($type, "'Both'") === false) {
            $server->exec("ALTER TABLE requests MODIFY release_method ENUM('Hardcopy','Softcopy','Both') NOT NULL DEFAULT 'Hardcopy'");
            say('Document format "Both" added.');
        }
    }

    // Cashier accounts are disabled before removing the role; they are not silently granted registrar access.
    if (table_exists($server, $dbName, 'users')) {
        $type = (string)$server->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
        if (strpos($type, "'cashier'") !== false || strpos($type, "'personnel'") !== false) {
            $server->exec("ALTER TABLE users MODIFY role ENUM('student','personnel','cashier','registrar','admin') NOT NULL");
            $server->exec("UPDATE users SET is_active = 0, role = 'registrar' WHERE role IN ('cashier','personnel')");
            $server->exec("ALTER TABLE users MODIFY role ENUM('student','registrar','admin') NOT NULL");
            say('Cashier/personnel accounts disabled and the roles removed.');
        }
    }

    // The upload check statuses changed (VERIFIED / DECLINED / NEEDS_CORRECTION / PENDING). Unused old checks are just deleted.
    if (table_exists($server, $dbName, 'upload_checks')) {
        $type = (string)$server->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'upload_checks' AND COLUMN_NAME = 'status'")->fetchColumn();
        if (strpos($type, "'ACCEPTED'") !== false) {
            $server->exec("DELETE FROM upload_checks WHERE used_file_id IS NULL");
            $server->exec("ALTER TABLE upload_checks MODIFY status ENUM('ACCEPTED','RECEIVED','DECLINED','VERIFIED','NEEDS_CORRECTION','PENDING') NOT NULL");
            $server->exec("UPDATE upload_checks SET status = 'VERIFIED' WHERE status IN ('ACCEPTED','RECEIVED')");
            $server->exec("ALTER TABLE upload_checks MODIFY status ENUM('VERIFIED','DECLINED','NEEDS_CORRECTION','PENDING') NOT NULL");
            say('Upload check statuses updated.');
        }
    }
}

// Softcopies that were attached with the older (unlimited download) version: they keep working, but now follow the new
// rules (download once, more with the registrar's approval). Runs only for items that are still "NONE".
function migrate_old_softcopies($server) {
    $changed = $server->exec("UPDATE request_items i
        JOIN request_files f ON f.item_id = i.id AND f.kind = 'release' AND f.is_current = 1
        JOIN requests r ON r.id = i.request_id
        SET i.softcopy_status = IF(r.status IN ('READY','COMPLETED'), 'READY', 'UPLOADED'),
            i.softcopy_uploaded_at = f.uploaded_at,
            i.softcopy_ready_at = IF(r.status IN ('READY','COMPLETED'), f.uploaded_at, NULL)
        WHERE i.softcopy_status = 'NONE'");
    if ($changed) say("Updated $changed older softcopy file(s) to the new download rules.");
}

// Fills the Academic Management tables the first time (levels, courses, year levels and sections of the school).
function seed_school_structure($server) {
    if ((int)$server->query('SELECT COUNT(*) FROM school_levels')->fetchColumn() > 0) return;
    $levelInsert = $server->prepare('INSERT INTO school_levels (name, sort_order) VALUES (?, ?)');
    $courseInsert = $server->prepare('INSERT INTO school_courses (level_id, name, sort_order) VALUES (?, ?, ?)');
    $yearInsert = $server->prepare('INSERT INTO school_year_levels (course_id, year_number, label) VALUES (?, ?, ?)');
    $sectionInsert = $server->prepare('INSERT INTO school_sections (year_level_id, name) VALUES (?, ?)');
    $levelOrder = 0;
    foreach (default_school_structure() as $level => $courses) {
        $levelInsert->execute([$level, ++$levelOrder]);
        $levelId = (int)$server->lastInsertId();
        $courseOrder = 0;
        foreach ($courses as $course => $years) {
            $courseInsert->execute([$levelId, $course, ++$courseOrder]);
            $courseId = (int)$server->lastInsertId();
            foreach ($years as $year => $sections) {
                $yearInsert->execute([$courseId, $year, default_year_label($level, $year)]);
                $yearId = (int)$server->lastInsertId();
                foreach ($sections as $section) $sectionInsert->execute([$yearId, $section]);
            }
        }
    }
    say('Academic Management lists added (levels, courses, year levels, sections).');
}

// Add newly listed College programs when upgrading a database that already has academic lists.
function seed_missing_college_courses($server) {
    $level = $server->prepare("SELECT id FROM school_levels WHERE name = 'COLLEGE' LIMIT 1");
    $level->execute();
    $levelId = $level->fetchColumn();
    if (!$levelId) return;

    $find = $server->prepare('SELECT id FROM school_courses WHERE name = ? LIMIT 1');
    $nextOrder = (int)$server->query('SELECT COALESCE(MAX(sort_order), 0) FROM school_courses WHERE level_id = ' . (int)$levelId)->fetchColumn() + 1;
    $insert = $server->prepare('INSERT INTO school_courses (level_id, name, sort_order) VALUES (?, ?, ?)');
    foreach (array_keys(default_school_structure()['COLLEGE']) as $name) {
        $find->execute([$name]);
        if ($find->fetchColumn()) continue;
        $insert->execute([$levelId, $name, $nextOrder++]);
        say("Added College course: $name");
    }
}

// Fill in missing College year levels and sections without overwriting admin-managed labels or entries.
function seed_college_course_structure($server) {
    $courses = $server->query("SELECT c.id FROM school_courses c JOIN school_levels l ON l.id = c.level_id
                               WHERE l.name = 'COLLEGE' ORDER BY (c.name = 'BSTM') DESC, c.id")
        ->fetchAll(PDO::FETCH_COLUMN);
    $inserted = 0;
    foreach ($courses as $courseId) $inserted += ensure_college_course_structure($server, (int)$courseId);
    if ($inserted) say('Aligned College year levels and sections with BSTM.');
}

// Retain old Junior High data for historical records, but remove it from active student lists.
function deactivate_junior_high($server) {
    $server->exec("UPDATE school_sections SET is_active = 0 WHERE year_level_id IN (
        SELECT id FROM school_year_levels WHERE course_id IN (
            SELECT id FROM school_courses WHERE level_id IN (
                SELECT id FROM school_levels WHERE name = 'HIGH SCHOOL'
            )
        )
    )");
    $server->exec("UPDATE school_year_levels SET is_active = 0 WHERE course_id IN (
        SELECT id FROM school_courses WHERE level_id IN (
            SELECT id FROM school_levels WHERE name = 'HIGH SCHOOL'
        )
    )");
    $server->exec("UPDATE school_courses SET is_active = 0 WHERE level_id IN (
        SELECT id FROM school_levels WHERE name = 'HIGH SCHOOL'
    )");
    $changed = $server->exec("UPDATE school_levels SET is_active = 0 WHERE name = 'HIGH SCHOOL' AND is_active = 1");
    if ($changed) say('Junior High deactivated; existing records were retained.');
}

// Words the document should contain, per requirement (used by the free document check). Only filled when empty,
// so anything the admin typed in the Catalog is never overwritten.
function fill_default_keywords($server) {
    $keywords = [
        'Accomplished request'                          => 'request,form,purpose,transcript',
        'Clearance/completion of requirements'          => 'clearance,cleared,signature,requirements',
        'Request/application'                           => 'request,application,form',
        'Student verification'                          => 'student,id,school,college',
        'Student identification/verification'           => 'student,id,school,college',
        'Student information/verification'              => 'student,id,school,enrollment',
        'Verified graduate record'                      => 'graduate,graduation,diploma,degree',
        'Required clearances/verification as applicable' => 'clearance,cleared,signature',
        'Request and record verification'               => 'request,form,student,record',
        'Verification of academic records'              => 'request,record,student,grades',
        'Required clearance and institutional requirements' => 'clearance,cleared,signature',
        'Original record/request and verification'     => 'record,request,certified,grades',
        'Appropriate request and authorization'         => 'request,authorization,letter,authorize',
        'Verification of institutional record'          => 'certificate,training,internship,ojt',
        'Record verification'                           => 'nstp,cwts,serial,record',
        'Appropriate request and supporting documents'  => 'request,letter,certification,supporting',
    ];
    $update = $server->prepare("UPDATE document_requirements SET keywords = ? WHERE name = ? AND (keywords IS NULL OR keywords = '')");
    foreach ($keywords as $name => $words) $update->execute([$words, $name]);
}

function seed_diploma_document($server) {
    $find = $server->prepare('SELECT id FROM documents WHERE name = ? LIMIT 1');
    $find->execute(['Diploma']);
    if ($find->fetchColumn()) return;
    $server->prepare('INSERT INTO documents (name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['Diploma', 'Official hard-copy diploma. Bring the physical payment receipt when claiming your document.', 'Academic Records', 500.00, 5, 7, 'Hardcopy', 16]);
    say('Diploma added to the document catalog.');
}

try {
    $dbName = env('DB_NAME', 'colm_registrar');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) exit("DB_NAME may only contain letters, numbers and underscores.\n");

    // 1. Connect to the database. On shared hosting (Hostinger) the database is created in the control panel and the MySQL user is not
    //    allowed to create databases, so we first try to connect to it directly. Only when it does not exist yet (XAMPP) we create it.
    $host = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    try {
        $server = new PDO($host . ';dbname=' . $dbName . ';charset=utf8mb4', env('DB_USER', 'root'), env('DB_PASS', ''), $options);
    } catch (PDOException $e) {
        $server = new PDO($host . ';charset=utf8mb4', env('DB_USER', 'root'), env('DB_PASS', ''), $options);
        $server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `$dbName`");
    }

    // 2. Upgrade an old database first, then create whatever is still missing.
    upgrade_old_database($server, $dbName);
    $sql = file_get_contents(__DIR__ . '/install.sql');
    [$schemaSql, $seedSql] = explode('-- Document catalog', $sql, 2);
    $seedSql = substr($seedSql, (int)strpos($seedSql, "\n")); // drop the rest of the comment line
    run_sql_script($server, $schemaSql);
    say('Tables are ready.');

    if ((int)$server->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 0) {
        run_sql_script($server, $seedSql);
        say('Document catalog added.');
    }
    fill_default_keywords($server);
    seed_school_structure($server);
    seed_missing_college_courses($server);
    seed_college_course_structure($server);
    seed_diploma_document($server);
    deactivate_junior_high($server);
    migrate_old_softcopies($server);
    require_once __DIR__ . '/../backend/release_schema.php';
    release_schema_apply($server);          // pay on release, release date, cancellation, e-signature, audit/email log, semester archive
    say('Release and archive tables are ready.');

    // Every student account needs a row in "students" (course, year, section, photo ...).
    $created = $server->exec("INSERT INTO students (user_id) SELECT u.id FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.role = 'student' AND s.id IS NULL");
    if ($created) say("Created $created missing student profile(s).");

    // 3. First accounts. Demo accounts only in development; in production only one admin with your own password.
    if ((int)$server->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $insert = $server->prepare('INSERT INTO users (username, password_hash, password_changed_at, full_name, email, role, student_no) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?)');
        if (is_production()) {
            $password = env('INITIAL_ADMIN_PASSWORD', '');
            if (strlen($password) < 10) exit("Set INITIAL_ADMIN_PASSWORD (at least 10 characters) in .env first.\n");
            $insert->execute(['admin', password_hash($password, PASSWORD_DEFAULT), 'Admin', null, 'admin', null]);
            say('Admin account "admin" created with your INITIAL_ADMIN_PASSWORD.');
        } else {
            $hash = password_hash('Password123!', PASSWORD_DEFAULT);
            // Admin = everything, Registrar = student and document management.
            $demo = [
                ['2026-00001', 'Juan Dela Cruz', 'student',   '2026-00001'],
                ['registrar',  'Registrar',      'registrar', null],
                ['admin',      'Admin',          'admin',     null],
            ];
            foreach ($demo as [$username, $name, $role, $studentNo]) {
                $insert->execute([$username, $hash, $name, null, $role, $studentNo]);
            }
            $server->exec("INSERT INTO students (user_id, school_level, course, year_level, section, contact_no)
                           SELECT id, 'COLLEGE', 'BSTM', 3, 'Section 1', '09171234567' FROM users WHERE username = '2026-00001'");
            say('Demo accounts created (password: Password123!): 2026-00001 (student), registrar, admin.');
            say('Change these passwords before you deploy.');
        }
    } elseif (!is_production()) {
        $student = $server->prepare('SELECT id FROM users WHERE username = ?');
        $student->execute(['2026-00001']);
        if (!$student->fetchColumn()) {
            $insert = $server->prepare('INSERT INTO users (username, password_hash, password_changed_at, full_name, email, role, student_no) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?)');
            $insert->execute(['2026-00001', password_hash('Password123!', PASSWORD_DEFAULT), 'Juan Dela Cruz', null, 'student', '2026-00001']);
            $server->prepare("INSERT INTO students (user_id, school_level, course, year_level, section, contact_no) VALUES (?, 'COLLEGE', 'BSTM', 3, 'Section 1', '09171234567')")
                ->execute([$server->lastInsertId()]);
            say('Missing demo student account added (password: Password123!).');
        } else {
            say('Demo student account already exists; existing account credentials were left unchanged.');
        }
        say('Other accounts already exist, so none were added.');
    } else {
        say('Accounts already exist, so none were added.');
    }
    say('Done.');
} catch (Throwable $e) {
    say('Install failed: ' . $e->getMessage());
    exit(1);
}