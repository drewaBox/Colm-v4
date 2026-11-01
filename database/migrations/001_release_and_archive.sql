-- Migration 001: pay on release, manual release date, 14-day cancellation, e-signature, audit + email log, semester archive.
-- Needs MariaDB 10.2+ (XAMPP / Hostinger). On MySQL 8 run `php database/install.php` instead: it makes the same changes with checks.
-- Safe to run twice. Existing rows are kept: old requests get payment_term = 'ADVANCE' so their receipt flow does not change.
-- BACK UP THE DATABASE FIRST.

ALTER TABLE requests MODIFY status ENUM('SUBMITTED','AI_VERIFYING','FOR_REVIEW','NEEDS_CORRECTION','PROCESSING','READY','COMPLETED','REJECTED','CANCELLED') NOT NULL DEFAULT 'SUBMITTED';
ALTER TABLE requests MODIFY payment_status ENUM('Unpaid','Paid','Refunded') NOT NULL DEFAULT 'Unpaid';
ALTER TABLE requests ADD COLUMN IF NOT EXISTS payment_term ENUM('UPON_RELEASE','ADVANCE') NOT NULL DEFAULT 'ADVANCE' AFTER payment_method;
ALTER TABLE requests MODIFY payment_term ENUM('UPON_RELEASE','ADVANCE') NOT NULL DEFAULT 'UPON_RELEASE';
ALTER TABLE requests ADD COLUMN IF NOT EXISTS expected_release_date DATE NULL AFTER target_end;
ALTER TABLE requests ADD COLUMN IF NOT EXISTS ready_at DATETIME NULL AFTER completed_at;
ALTER TABLE requests ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER ready_at;
ALTER TABLE requests ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL AFTER cancelled_at;
ALTER TABLE requests ADD COLUMN IF NOT EXISTS submission_token VARCHAR(64) NULL AFTER tracking_no;
ALTER TABLE requests ADD UNIQUE KEY IF NOT EXISTS uq_requests_submission (submission_token);
ALTER TABLE requests ADD KEY IF NOT EXISTS idx_requests_ready (status, payment_status, ready_at);
UPDATE requests r SET r.ready_at = (SELECT MAX(h.created_at) FROM request_history h WHERE h.request_id = r.id AND h.new_status = 'READY')
  WHERE r.status IN ('READY','COMPLETED') AND r.ready_at IS NULL;

ALTER TABLE payments ADD COLUMN IF NOT EXISTS refunded_at DATETIME NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS refunded_by INT UNSIGNED NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS refund_reason VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS request_signatures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  signed_name VARCHAR(160) NOT NULL,
  signed_ip VARCHAR(45) NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_signature_request (request_id),
  CONSTRAINT fk_signature_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS master_list_imports (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validation_records (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(120) NOT NULL PRIMARY KEY,
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_otps (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
