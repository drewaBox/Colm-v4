-- COLM Registrar: database structure + document catalog.
-- Run once with:  php database/install.php   (it also creates the database and the first accounts)
-- Every statement ends with a semicolon at the end of the line.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  password_changed_at DATETIME NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120) NULL,
  role ENUM('student','registrar','admin') NOT NULL,
  student_no VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_approved TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_student_no (student_no),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  school_level VARCHAR(30) NULL,
  course VARCHAR(80) NULL,
  year_level TINYINT UNSIGNED NULL,
  section VARCHAR(20) NULL,
  status ENUM('currently_enrolled','alumni','undergrad') NOT NULL DEFAULT 'currently_enrolled',
  academic_year VARCHAR(9) NULL,
  contact_no VARCHAR(30) NULL,
  photo_stored VARCHAR(255) NULL,
  photo_mime VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_students_user (user_id),
  KEY idx_students_course (course),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'Certification',
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  processing_min_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  processing_max_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  release_methods VARCHAR(50) NOT NULL DEFAULT 'Hardcopy',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_documents_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_requirements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  ai_hint TEXT NULL,
  keywords VARCHAR(255) NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  requires_name TINYINT(1) NOT NULL DEFAULT 1,
  allowed_types VARCHAR(50) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
  max_size_mb TINYINT UNSIGNED NOT NULL DEFAULT 3,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_req_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  KEY idx_req_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_no VARCHAR(30) NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  release_method ENUM('Hardcopy','Softcopy','Both') NOT NULL DEFAULT 'Hardcopy',   -- the "document format" the student chose
  payment_method ENUM('Cash','GCash') NOT NULL DEFAULT 'Cash',
  status ENUM('SUBMITTED','AI_VERIFYING','FOR_REVIEW','NEEDS_CORRECTION','PROCESSING','READY','COMPLETED','REJECTED') NOT NULL DEFAULT 'SUBMITTED',
  payment_status ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  academic_year VARCHAR(20) NULL,
  semester VARCHAR(20) NULL,
  program VARCHAR(100) NULL,
  year_graduated SMALLINT UNSIGNED NULL,
  credential_form_json LONGTEXT NULL,
  student_verification_form_json LONGTEXT NULL,
  qr_code VARCHAR(255) NULL,
  target_start DATE NULL,
  target_end DATE NULL,
  registrar_remarks TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_requests_tracking (tracking_no),
  UNIQUE KEY uq_requests_qr (qr_code),
  KEY idx_requests_student (student_id),
  KEY idx_requests_status (status),
  CONSTRAINT fk_requests_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  purpose VARCHAR(255) NULL,                 -- the specific purpose for this document
  -- softcopy (PDF) release: the PDF itself is a request_files row (kind = 'release'); these columns say if the student may download it
  softcopy_status ENUM('NONE','UPLOADED','READY') NOT NULL DEFAULT 'NONE',
  softcopy_uploaded_by INT UNSIGNED NULL,
  softcopy_uploaded_at DATETIME NULL,
  softcopy_ready_at DATETIME NULL,
  download_limit TINYINT UNSIGNED NOT NULL DEFAULT 1,
  download_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_downloaded_at DATETIME NULL,
  CONSTRAINT fk_items_uploader FOREIGN KEY (softcopy_uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_items_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_items_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE RESTRICT,
  KEY idx_items_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  requirement_id INT UNSIGNED NULL,
  kind ENUM('requirement','release') NOT NULL DEFAULT 'requirement',
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  review_status ENUM('Pending','Verified','Rejected','Resubmit') NOT NULL DEFAULT 'Pending',
  review_remarks VARCHAR(500) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_files_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_files_item FOREIGN KEY (item_id) REFERENCES request_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_files_requirement FOREIGN KEY (requirement_id) REFERENCES document_requirements (id) ON DELETE RESTRICT,
  CONSTRAINT fk_files_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  KEY idx_files_request (request_id),
  KEY idx_files_hash (request_id, sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_verifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  file_id INT UNSIGNED NOT NULL,
  status ENUM('PASS','REVIEW','FAIL','UNAVAILABLE') NOT NULL,
  reason VARCHAR(500) NULL,
  extracted_text TEXT NULL,
  confidence DECIMAL(4,3) NULL,
  name_on_document VARCHAR(150) NULL,
  name_match_score TINYINT UNSIGNED NULL,
  auto_declined TINYINT(1) NOT NULL DEFAULT 0,
  model VARCHAR(80) NULL,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_file FOREIGN KEY (file_id) REFERENCES request_files (id) ON DELETE CASCADE,
  KEY idx_ai_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every file a student uploads is checked right away (before the request is submitted). The result is kept here
-- until the request is created; then the file moves into request_files and the result is copied to ai_verifications.
CREATE TABLE IF NOT EXISTS upload_checks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  requirement_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  status ENUM('VERIFIED','DECLINED','NEEDS_CORRECTION','PENDING') NOT NULL,
  student_message VARCHAR(500) NOT NULL,
  result_status ENUM('PASS','REVIEW','FAIL','UNAVAILABLE') NOT NULL,
  reason VARCHAR(500) NULL,
  extracted_text TEXT NULL,
  confidence DECIMAL(4,3) NULL,
  name_on_document VARCHAR(150) NULL,
  name_match_score TINYINT UNSIGNED NULL,
  auto_declined TINYINT(1) NOT NULL DEFAULT 0,
  model VARCHAR(80) NULL,
  error_message VARCHAR(500) NULL,
  used_file_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_checks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_checks_requirement FOREIGN KEY (requirement_id) REFERENCES document_requirements (id) ON DELETE CASCADE,
  CONSTRAINT fk_checks_file FOREIGN KEY (used_file_id) REFERENCES request_files (id) ON DELETE SET NULL,
  KEY idx_checks_user (user_id, created_at),
  KEY idx_checks_unused (used_file_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student asks for ANOTHER download of a softcopy (after the first one was used). The registrar/admin approves or rejects it.
CREATE TABLE IF NOT EXISTS softcopy_download_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  reason_type VARCHAR(60) NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  status ENUM('PENDING','APPROVED','REJECTED','USED') NOT NULL DEFAULT 'PENDING',
  rejection_reason VARCHAR(500) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dlreq_item (item_id, status),
  KEY idx_dlreq_status (status, created_at),
  KEY idx_dlreq_student (student_id),
  CONSTRAINT fk_dlreq_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_dlreq_item FOREIGN KEY (item_id) REFERENCES request_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_dlreq_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_dlreq_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Download history (audit log): one row for every time somebody opened the softcopy.
-- download_type: ORIGINAL = the first download, ADDITIONAL = an approved extra download, STAFF = registrar/admin looked at it (not counted).
CREATE TABLE IF NOT EXISTS softcopy_downloads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  file_id INT UNSIGNED NULL,
  download_number SMALLINT UNSIGNED NULL,
  download_type ENUM('ORIGINAL','ADDITIONAL','STAFF') NOT NULL,
  downloaded_by INT UNSIGNED NULL,
  downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  extra_request_id INT UNSIGNED NULL,
  KEY idx_dl_item (item_id, downloaded_at),
  KEY idx_dl_student (student_id),
  CONSTRAINT fk_dl_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_item FOREIGN KEY (item_id) REFERENCES request_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_dl_file FOREIGN KEY (file_id) REFERENCES request_files (id) ON DELETE SET NULL,
  CONSTRAINT fk_dl_user FOREIGN KEY (downloaded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_dl_extra FOREIGN KEY (extra_request_id) REFERENCES softcopy_download_requests (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verified student receipt uploads are recorded here. requests.payment_status tracks whether payment is confirmed.
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  receipt_no VARCHAR(30) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('Cash','GCash') NOT NULL,
  reference_no VARCHAR(40) NULL,
  received_by INT UNSIGNED NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_receipt (receipt_no),
  UNIQUE KEY uq_payments_request (request_id),
  UNIQUE KEY uq_payments_reference (reference_no),
  KEY idx_payments_paid_at (paid_at),
  CONSTRAINT fk_payments_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  status ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
  remarks VARCHAR(500) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  official_receipt_no VARCHAR(40) NULL,
  amount_paid DECIMAL(10,2) NULL,
  date_paid DATE NULL,
  file_hash CHAR(64) NULL,
  security_flags TEXT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_receipts_request (request_id),
  CONSTRAINT fk_payment_receipts_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_receipts_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_receipts_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  changed_by INT UNSIGNED NULL,
  remarks VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_history_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL,
  KEY idx_history_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Result of the AI verification of a whole request (checklist + where it was routed).
CREATE TABLE IF NOT EXISTS request_verifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  route ENUM('REGISTRAR','AUTO') NOT NULL,
  verdict ENUM('PASS','REVIEW','NEEDS_CORRECTION') NOT NULL,
  summary VARCHAR(500) NOT NULL,
  checks_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_verifications_request (request_id, id),
  CONSTRAINT fk_verifications_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  request_id INT UNSIGNED NULL,
  target_type VARCHAR(30) NULL,
  target_id INT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  message VARCHAR(500) NOT NULL,
  type ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notes_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE SET NULL,
  KEY idx_notes_user (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed log-in attempts (to slow down password guessing) and upload attempts (to limit how many files one student can send per hour).
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_login_ip (ip, created_at),
  KEY idx_login_user (ip, username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  user_id INT UNSIGNED PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  KEY idx_password_reset_expiry (expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_password_reset_requests_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_upload_attempts (user_id, created_at),
  CONSTRAINT fk_upload_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academic management (the admin adds / edits / deactivates these). Students keep the NAMES as text (students.school_level,
-- course, year_level, section), so an item that is used is never deleted, only made inactive.
CREATE TABLE IF NOT EXISTS school_levels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_levels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_courses_name (name),
  CONSTRAINT fk_courses_level FOREIGN KEY (level_id) REFERENCES school_levels (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_year_levels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  year_number TINYINT UNSIGNED NOT NULL,
  label VARCHAR(40) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_years_course (course_id, year_number),
  CONSTRAINT fk_years_course FOREIGN KEY (course_id) REFERENCES school_courses (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_level_id INT UNSIGNED NOT NULL,
  name VARCHAR(20) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sections_year (year_level_id, name),
  CONSTRAINT fk_sections_year FOREIGN KEY (year_level_id) REFERENCES school_year_levels (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document catalog (price, days, release methods and requirements are read from here by the whole system).
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (1, 'Transcript of Records (TOR)', 'Official consolidated academic transcript covering all course units, grades, and graduation credentials.', 'Academic Records', 250.00, 5, 7, 'Hardcopy', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (1, 'Accomplished request', 'Legacy upload requirement. New requests use the online credential request form instead.', 'A filled-out document request form with the student name, student number and purpose.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (1, 'Clearance/completion of requirements', 'Legacy upload requirement. New requests use the online credential request form instead.', 'A clearance form with office signatures or stamps showing the requirements are complete.', 2);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (2, 'Certification of Grades', 'Official certificate containing semester breakdown of subjects taken and grades obtained.', 'Certification', 100.00, 0, 0, 'Softcopy,Hardcopy', 2);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (2, 'Request/application', 'Filled-out request or application form.', 'A filled-out document request or application form with the student name and student number.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (2, 'Student verification', 'Proof of student identity.', 'A valid school ID or government ID showing the student name and photo.', 2);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (3, 'Certificate of Enrollment', 'Formal institutional verification confirming active student status in the current semester.', 'Certification', 100.00, 0, 0, 'Hardcopy', 3);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (3, 'Student identification/verification', 'Proof of student identity.', 'A valid school ID or government ID showing the student name and photo.', 1);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (4, 'Certificate of Registration', 'Certified copy of the official enrollment registration and scheduled course matrix', 'Certification', 100.00, 0, 0, 'Softcopy,Hardcopy', 4);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (4, 'Student information/verification', 'Proof of student identity or enrollment.', 'A school ID or enrollment record showing the student name and student number.', 1);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (5, 'Certificate of Graduation', 'Official institutional attestation confirming the degree earned and graduation date.', 'Certification', 100.00, 0, 0, 'Hardcopy', 5);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (5, 'Verified graduate record', 'Proof that the student has graduated.', 'A diploma, graduation list, or clearance showing the student has graduated.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (5, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (6, 'Certificate of Good Moral Character', 'Attestation from the Office of Student Affairs and Registrar regarding discipline record.', 'Certification', 100.00, 0, 0, 'Hardcopy', 6);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (6, 'Required clearances/verification as applicable', 'Clearance forms that apply to the student.', 'A clearance form with the required office signatures.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (6, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (7, 'Certificate of Units Earned', 'Statement indicating total credited collegiate units completed to date.', 'Certification', 100.00, 0, 0, 'Hardcopy', 7);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (7, 'Request and record verification', 'Request form with student details.', 'A request form showing the student name and student number.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (7, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (8, 'Certificate of General Weighted Average (GWA)', 'Certification of overall cumulative grade point average across all completed semesters.', 'Certification', 100.00, 1, 3, 'Hardcopy', 8);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (8, 'Request and record verification', 'Request form with student details.', 'A request form showing the student name and student number.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (8, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (9, 'Certificate of No Pending/Unfinished Academic Requirement', 'Certification certifying completion of all coursework, prerequisites, and thesis/internship requirements.', 'Certification', 100.00, 0, 0, 'Hardcopy', 9);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (9, 'Verification of academic records', 'Request form or record copy with student details.', 'A request form or record copy showing the student name and student number.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (9, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (10, 'Honorable Dismissal', 'Official document permitting student to transfer out and enroll in another collegiate institution.', 'Clearance', 350.00, 0, 0, 'Hardcopy', 10);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (10, 'Required clearance and institutional requirements', 'Legacy upload requirement. New requests use the online credential request form instead.', 'A clearance form with signatures from the required offices.', 1);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (11, 'Certified True Copy of Academic Record', 'Registrar seal and dry stamp verifying authenticity of submitted photocopies of records.', 'Academic Records', 50.00, 0, 0, 'Hardcopy', 11);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (11, 'Original record/request and verification', 'The record to be certified or a signed request.', 'The academic record to be certified, or a signed request form with student details.', 1);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (12, 'Form 137 / Permanent Record', 'High school transcript / secondary student permanent record copy for school-to-school transfer.', 'Academic Records', 100.00, 5, 7, 'Hardcopy', 12);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (12, 'Appropriate request and authorization', 'Legacy upload requirement. New requests use the online credential request form instead.', 'A request form; for a representative, an authorization letter and valid IDs.', 1);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (13, 'Internship/Training Certification', 'Formal certification of completed on-the-job training (OJT) or practicum hours.', 'Certification', 100.00, 0, 0, 'Hardcopy', 13);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (13, 'Verification of institutional record', 'Proof of the internship or training.', 'A certificate, endorsement letter or agreement proving the internship or training.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (13, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (14, 'NSTP/CWTS-related Certification', 'Official certificate and serial number for completed National Service Training Program.', 'Certification', 100.00, 0, 0, 'Hardcopy', 14);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (14, 'Record verification', 'Proof of the NSTP/CWTS record.', 'An NSTP/CWTS record or serial number proof showing the student name.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (14, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (15, 'Other Certifications', 'Custom registrar certification for special academic or administrative situations.', 'Certification', 100.00, 0, 0, 'Hardcopy', 15);
INSERT INTO documents (id, name, description, category, price, processing_min_days, processing_max_days, release_methods, sort_order) VALUES (16, 'Diploma', 'Official hard-copy diploma. Bring the physical payment receipt when claiming your document.', 'Academic Records', 500.00, 5, 7, 'Hardcopy', 16);
INSERT INTO document_requirements (document_id, name, description, ai_hint, sort_order) VALUES (15, 'Appropriate request and supporting documents', 'Request letter and supporting document.', 'A request letter and a supporting document for the requested certification.', 1);
INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, sort_order) VALUES (15, 'Student ID / Verification', 'Upload a clear copy of your school ID or another valid ID showing your name and photo.', 'A valid school ID or government ID showing the student name and photo.', 'student,id,identification,school,government,photo', 0);