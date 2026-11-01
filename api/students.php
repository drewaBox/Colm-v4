<?php
// Students: list, profile, add one, edit, delete, CSV import. Also the student's own profile.
//
//   GET  ?action=profile[&id=USER_ID]     profile + submitted documents (student: own, admin: any student)
//   GET  ?action=list[&q=&course=&page=]  admin
//   GET  ?action=structure                the school levels / courses / years / sections (for the Add Student form)
//   GET  ?action=template                 admin - downloads the sample CSV
//   POST ?action=create                   admin - add one student
//   POST ?action=update                   admin - edit a student
//   POST ?action=delete                   admin - delete a student (only if there are no requests)
//   POST ?action=reset_password           admin - new default password, shown once
//   POST ?action=import                   admin - CSV file (field "csv"), field "dry_run" = 1 only checks the file
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/students.php';
require_once __DIR__ . '/../backend/mailer.php';
require_once __DIR__ . '/../backend/workflow.php';
register_error_handlers();

$user = require_login();
$action = $_GET['action'] ?? 'profile';

if ($action === 'profile') { require_method('GET'); show_profile($user); }
if ($action === 'update_profile') { require_method('POST'); require_csrf(); require_login(['student']); update_own_profile($user); }
require_login(STAFF_ROLES);                  // everything below is for the admin/registrar only

switch ($action) {
    case 'list':           require_method('GET');  list_students(); break;
    case 'structure':      require_method('GET');  send_structure(); break;
    case 'template':       require_method('GET');  download_template(); break;
    case 'create':         require_method('POST'); require_csrf(); create_one(); break;
    case 'update':         require_method('POST'); require_csrf(); update_one(); break;
    case 'bulk_update':    require_method('POST'); require_csrf(); bulk_update(); break;
    case 'delete':         require_method('POST'); require_csrf(); delete_one(); break;
    case 'reset_password': require_method('POST'); require_csrf(); reset_password(); break;
    case 'set_active':     require_method('POST'); require_csrf(); set_student_active(); break;
    case 'resend_approval_email': require_method('POST'); require_csrf(); resend_approval_email(); break;
    case 'import':         require_method('POST'); require_csrf(); import_csv(); break;
    default: fail('Unknown action.', 404);
}

// ---------- Profile ----------

function show_profile($user) {
    $id = $user['role'] === 'student' ? (int)$user['id'] : (int)($_GET['id'] ?? 0);
    if ($user['role'] !== 'student' && !in_array($user['role'], STAFF_ROLES, true)) fail('You are not allowed to do this.', 403);

    $student = fetch_one("SELECT u.id, u.username, u.full_name, u.email, u.student_no, u.is_active, u.is_approved, u.created_at,
                                 s.school_level, s.course, s.year_level, s.section, s.status, s.academic_year, s.contact_no, (s.photo_stored IS NOT NULL) AS has_photo
                          FROM users u LEFT JOIN students s ON s.user_id = u.id
                          WHERE u.id = ? AND u.role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);

    // Every document the student currently has uploaded, with its verification result.
    $documents = fetch_all("SELECT f.id AS file_id, f.request_id, f.original_name, f.review_status, f.review_remarks, f.uploaded_at,
                                   r.name AS requirement_name, d.name AS document_name, rq.tracking_no, rq.status AS request_status
                            FROM request_files f
                            JOIN requests rq ON rq.id = f.request_id
                            JOIN request_items i ON i.id = f.item_id
                            JOIN documents d ON d.id = i.document_id
                            JOIN document_requirements r ON r.id = f.requirement_id
                            WHERE rq.student_id = ? AND f.kind = 'requirement' AND f.is_current = 1
                            ORDER BY f.uploaded_at DESC, f.id DESC LIMIT 100", [$id]);
    $summary = ['total' => 0, 'pending' => 0, 'verified' => 0, 'declined' => 0];
    foreach ($documents as &$doc) {
        $doc['verification'] = verification_label($doc['review_status']);
        $summary['total']++;
        if ($doc['verification'] === 'Verified') $summary['verified']++;
        elseif ($doc['verification'] === 'Declined') $summary['declined']++;
        else $summary['pending']++;
    }
    $requests = fetch_one('SELECT COUNT(*) AS total FROM requests WHERE student_id = ?', [$id]);

    $overall = 'No documents submitted';
    if ($summary['total'] > 0) {
        $overall = $summary['declined'] > 0 ? 'Declined' : ($summary['pending'] > 0 ? 'Pending' : 'Verified');
    }
    $student['year_label'] = $student['year_level'] ? year_label($student['school_level'], $student['year_level'], $student['course']) : '';
    $student['id'] = (int)$student['id'];
    $student['is_active'] = (bool)$student['is_active'];
    $student['has_photo'] = (bool)$student['has_photo'];
    json_response(['ok' => true, 'student' => $student, 'documents' => $documents, 'summary' => $summary,
                   'verification_status' => $overall, 'request_count' => (int)$requests['total']]);
}

// The three statuses the school wants to show: Pending, Verified, Declined.
function verification_label($reviewStatus) {
    if ($reviewStatus === 'Verified') return 'Verified';
    if ($reviewStatus === 'Rejected' || $reviewStatus === 'Resubmit') return 'Declined';
    return 'Pending';
}

// ---------- List ----------

function list_students() {
    $where = ["u.role = 'student'"]; $params = [];
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(u.full_name LIKE ? OR u.student_no LIKE ? OR u.email LIKE ? OR s.course LIKE ? OR s.section LIKE ? OR s.school_level LIKE ? OR s.academic_year LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%");
    }
    // The filters work together (AND): School level + Course + Year level + Section + Account.
    $level = clean_text($_GET['level'] ?? '', 30);
    if ($level !== '') { $where[] = 's.school_level = ?'; $params[] = $level; }
    $course = clean_text($_GET['course'] ?? '', 80);
    if ($course !== '') { $where[] = 's.course = ?'; $params[] = $course; }
    $year = (int)($_GET['year'] ?? 0);
    if ($year > 0) { $where[] = 's.year_level = ?'; $params[] = $year; }
    $section = clean_text($_GET['section'] ?? '', 20);
    if ($section !== '') { $where[] = 's.section = ?'; $params[] = $section; }
    $studentStatus = $_GET['student_status'] ?? '';
    if (in_array($studentStatus, ['currently_enrolled', 'alumni', 'undergrad'], true)) {
        $where[] = 's.status = ?';
        $params[] = $studentStatus;
    }
    $account = $_GET['account'] ?? '';
    if ($account === 'active') $where[] = 'u.is_active = 1';
    elseif ($account === 'disabled') $where[] = 'u.is_active = 0';
    elseif ($account === 'default') $where[] = 'u.must_change_password = 1';
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $perPage = 25;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = (int)fetch_one("SELECT COUNT(*) AS c FROM users u LEFT JOIN students s ON s.user_id = u.id $whereSql", $params)['c'];
    $rows = fetch_all("SELECT u.id, u.username, u.full_name, u.email, u.student_no, u.is_active, u.is_approved, u.must_change_password,
                              s.school_level, s.course, s.year_level, s.section, s.status, s.academic_year, s.contact_no,
                              (SELECT COUNT(*) FROM requests r WHERE r.student_id = u.id) AS request_count
                       FROM users u LEFT JOIN students s ON s.user_id = u.id $whereSql
                       ORDER BY u.full_name LIMIT $perPage OFFSET $offset", $params);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (bool)$row['is_active'];
        $row['is_approved'] = (bool)$row['is_approved'];
        $row['must_change_password'] = (bool)$row['must_change_password'];
        $row['request_count'] = (int)$row['request_count'];
    }
    foreach ($rows as &$row) $row['year_label'] = $row['year_level'] ? year_label($row['school_level'], $row['year_level'], $row['course']) : '';
    $courses = array_column(fetch_all("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course <> '' ORDER BY course"), 'course');
    json_response(['ok' => true, 'students' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'courses' => $courses]);
}

// The list the dropdowns are built from: [{level, courses: [{name, years: [{year, label, sections: [names]}]}]}]
// Normal: only active items (for Add / Edit Student). With ?all=1: also the deactivated ones (for the filters, so old students can still be found).
function send_structure() {
    json_response(['ok' => true, 'levels' => school_structure_for_client(!empty($_GET['all']))]);
}

// Used only before database/install.php has created the Academic Management tables.
function tree_from_defaults() {
    $tree = [];
    foreach (default_school_structure() as $level => $courses) {
        $courseList = [];
        foreach ($courses as $course => $years) {
            $yearList = [];
            foreach ($years as $year => $sections) {
                $yearList[] = ['year' => $year, 'label' => default_year_label($level, $year), 'sections' => array_map(fn($n) => ['name' => $n], $sections)];
            }
            $courseList[] = ['name' => $course, 'years' => $yearList];
        }
        $tree[] = ['level' => $level, 'courses' => $courseList];
    }
    return $tree;
}

function download_template() {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_template.csv"');
    echo sample_students_csv();
    exit;
}

// ---------- Add / edit / delete ----------

function student_input() {
    $input = read_json_body();
    return [
        'student_id' => $input['student_id'] ?? '', 'full_name' => $input['full_name'] ?? '', 'email' => $input['email'] ?? '',
        'school_level' => $input['school_level'] ?? '', 'course' => $input['course'] ?? '', 'year' => $input['year'] ?? '', 'section' => $input['section'] ?? '',
        'contact_number' => $input['contact_number'] ?? '', 'status' => $input['status'] ?? 'currently_enrolled',
        'academic_year' => $input['academic_year'] ?? '', 'id' => (int)($input['id'] ?? 0),
    ];
}

function create_one() {
    $input = student_input();
    [$data, $errors] = validate_student($input, null, false);
    if (!$errors && student_id_taken($data['student_id'])) $errors[] = 'A student or account with this ID already exists.';
    if (!$errors && student_email_taken($data['email'])) $errors[] = 'Email address is already used by another account.';
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    $password = make_default_password();
    $userId = create_student($data, $password);
    $emailSent = send_student_account_email($data['email'], $data['full_name'], $data['student_id'], $password);
    json_response(['ok' => true, 'id' => $userId, 'email_sent' => $emailSent,
        'message' => $emailSent ? 'Student account created and credentials emailed.' : 'Student account created, but the email could not be sent. Check the mail configuration and deliver the credentials securely.',
        'credentials' => ['username' => $data['student_id'], 'password' => $password, 'full_name' => $data['full_name']]], 201);
}

function update_one() {
    $input = student_input();
    $existing = fetch_one("SELECT u.id, u.username, s.school_level, s.course, s.year_level, s.section, s.status
                           FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ? AND u.role = 'student'", [$input['id']]);
    if (!$existing) fail('Student not found.', 404);
    // The student ID is also the username, so it cannot be changed here.
    $input['student_id'] = $existing['username'];
    // If the school level / course / year / section were not changed, they stay valid even when the admin deactivated them later.
    $structure = null;
    if ($existing['course'] !== null && strcasecmp((string)$input['school_level'], (string)$existing['school_level']) === 0
        && strcasecmp((string)$input['course'], (string)$existing['course']) === 0
        && (int)parse_year_level($input['year']) === (int)$existing['year_level']
        && strcasecmp((string)$input['section'], (string)$existing['section']) === 0) {
        $structure = [$existing['school_level'] => [$existing['course'] => [(int)$existing['year_level'] => [$existing['section']]]]];
    }
    [$data, $errors] = validate_student($input, $structure);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);
    if (student_email_taken($data['email'], (int)$existing['id'])) fail('Email address is already used by another account.', 422);

    query('UPDATE users SET full_name = ?, email = ? WHERE id = ?', [$data['full_name'], $data['email'] !== '' ? $data['email'] : null, $existing['id']]);
    query('UPDATE students SET school_level = ?, course = ?, year_level = ?, section = ?, status = ?, academic_year = ?, contact_no = ? WHERE user_id = ?',
        [$data['school_level'], $data['course'], $data['year'], $data['section'], $data['status'], $data['academic_year'] !== '' ? $data['academic_year'] : null, $data['contact_number'] !== '' ? $data['contact_number'] : null, $existing['id']]);
    json_response(['ok' => true]);
}

function update_own_profile($user) {
    $input = read_json_body();
    $email = trim((string)($input['email'] ?? ''));
    $section = clean_text($input['section'] ?? '', 20);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) fail('Enter a valid email address.', 422);
    if ($section === '') fail('Section is required.', 422);
    if (fetch_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) fail('Email address is already used by another account.', 422);
    $student = fetch_one('SELECT school_level, course, year_level, section FROM students WHERE user_id = ?', [$user['id']]);
    if (!$student) fail('Student profile not found.', 404);
    $structure = school_structure();
    if (strcasecmp($section, (string)$student['section']) !== 0) {
        [$combination, $problem] = resolve_school_combination($student['school_level'], $student['course'], $student['year_level'], $section, $structure);
        if ($problem !== '') fail($problem, 422);
        $section = $combination['section'];
    }
    query('UPDATE users SET email = ? WHERE id = ?', [$email, $user['id']]);
    query('UPDATE students SET section = ? WHERE user_id = ?', [$section, $user['id']]);
    json_response(['ok' => true, 'message' => 'Your email and section were updated.']);
}

function bulk_update() {
    $input = read_json_body();
    if (!is_array($input['ids'] ?? null)) fail('Select students to update.', 422);
    foreach ($input['ids'] as $id) if (!is_scalar($id)) fail('Invalid student selection.', 422);
    $ids = array_values(array_unique(array_filter(array_map('intval', $input['ids']), fn($id) => $id > 0)));
    if (!$ids || count($ids) > 200) fail('Select between 1 and 200 students.', 422);
    $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    $status = $fields['status'] ?? '';
    foreach (['status', 'course', 'year', 'section'] as $key) {
        if (isset($fields[$key]) && !is_scalar($fields[$key])) fail('Invalid bulk edit field.', 422);
    }
    $course = clean_text($fields['course'] ?? '', 80);
    $yearInput = trim((string)($fields['year'] ?? ''));
    $section = clean_text($fields['section'] ?? '', 20);
    if ($status !== '' && !in_array($status, STUDENT_STATUSES, true)) fail('Choose a valid student status.', 422);
    if ($status === '' && $course === '' && $yearInput === '' && $section === '') fail('Choose at least one field to update.', 422);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $student = fetch_one("SELECT u.id, s.school_level, s.course, s.year_level, s.section FROM users u
                                  JOIN students s ON s.user_id = u.id WHERE u.id = ? AND u.role = 'student' FOR UPDATE", [$id]);
            if (!$student) fail('One or more selected student accounts could not be found.', 404);
            $newCourse = $course !== '' ? $course : $student['course'];
            $newYear = $yearInput !== '' ? parse_year_level($yearInput) : (int)$student['year_level'];
            $newSection = $section !== '' ? $section : $student['section'];
            if ($yearInput !== '' && $newYear === null) fail("{$student['id']}: enter a valid year level.", 422);
            if ($course !== '' || $yearInput !== '' || $section !== '') {
                [$combination, $problem] = resolve_school_combination($student['school_level'], $newCourse, $newYear, $newSection);
                if ($problem !== '') fail("{$student['id']}: $problem", 422);
                $newCourse = $combination['course'];
                $newYear = $combination['year'];
                $newSection = $combination['section'];
            }
            $sets = []; $params = [];
            if ($status !== '') { $sets[] = 'status = ?'; $params[] = $status; }
            if ($course !== '') { $sets[] = 'course = ?'; $params[] = $newCourse; }
            if ($yearInput !== '') { $sets[] = 'year_level = ?'; $params[] = $newYear; }
            if ($section !== '') { $sets[] = 'section = ?'; $params[] = $newSection; }
            $params[] = $id;
            query('UPDATE students SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    json_response(['ok' => true, 'updated' => count($ids)]);
}

function delete_one() {
    $id = (int)(read_json_body()['id'] ?? 0);
    $student = fetch_one("SELECT id, full_name FROM users WHERE id = ? AND role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    $requests = (int)fetch_one('SELECT COUNT(*) AS c FROM requests WHERE student_id = ?', [$id])['c'];
    if ($requests > 0) fail("{$student['full_name']} has $requests document request(s). Disable the account instead of deleting it so the records are kept.", 422);

    require_once __DIR__ . '/../backend/files.php';
    $photo = fetch_one('SELECT photo_stored FROM students WHERE user_id = ?', [$id]);
    $leftovers = fetch_all('SELECT stored_name FROM upload_checks WHERE user_id = ? AND stored_name IS NOT NULL', [$id]);   // checked files that were never used in a request
    query('DELETE FROM users WHERE id = ?', [$id]);              // the students row and the checks are deleted with it
    if ($photo && $photo['photo_stored']) delete_stored_file($photo['photo_stored']);
    foreach ($leftovers as $old) delete_stored_file($old['stored_name']);
    json_response(['ok' => true]);
}

function reset_password() {
    $id = (int)(read_json_body()['id'] ?? 0);
    $student = fetch_one("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    $password = make_default_password();
    query('UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $id]);
    query('DELETE FROM password_reset_tokens WHERE user_id = ?', [$id]);
    json_response(['ok' => true, 'credentials' => ['username' => $student['username'], 'password' => $password, 'full_name' => $student['full_name']]]);
}

function set_student_active() {
    $input = read_json_body();
    $id = (int)($input['id'] ?? 0);
    if (!isset($input['is_active']) || !in_array($input['is_active'], [true, false, 0, 1, '0', '1'], true)) {
        fail('Choose whether to enable or disable the student account.', 422);
    }
    $student = fetch_one("SELECT id, full_name, email, username, is_active, is_approved FROM users WHERE id = ? AND role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    $active = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
    if ($active) {
        query('UPDATE users SET is_active = 1, is_approved = 1 WHERE id = ?', [$id]);
    } else {
        query('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);
    }
    $emailSent = null;
    if ($active && !$student['is_approved']) {
        $emailSent = !empty($student['email'])
            ? send_student_approval_email($student['email'], $student['full_name'], $student['username'])
            : false;
        add_notification(
            $id,
            null,
            'Student account approved',
            'Your COLM student account has been approved. You can now sign in and access the student portal.',
            'success',
            'student',
            $id
        );
    }
    json_response([
        'ok' => true,
        'is_active' => $active,
        'email_sent' => $emailSent,
        'message' => $emailSent === true
            ? 'Student account approved and notification email sent.'
            : ($emailSent === false ? 'Student account approved, but the notification email could not be sent.' : 'Student account updated.'),
    ]);
}

function resend_approval_email() {
    $id = (int)(read_json_body()['id'] ?? 0);
    $student = fetch_one("SELECT id, full_name, email, username, is_active, is_approved FROM users WHERE id = ? AND role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    if (!$student['is_active'] || !$student['is_approved']) fail('Approve and enable this student account before sending the approval email.', 422);
    if (empty($student['email'])) fail('This student has no email address. Add a valid email before sending the approval notice.', 422);

    $emailSent = send_student_approval_email($student['email'], $student['full_name'], $student['username']);
    json_response([
        'ok' => true,
        'email_sent' => $emailSent,
        'message' => $emailSent
            ? 'Approval email sent to the student.'
            : 'Email was not sent. Check Composer dependencies, SMTP settings, and the server error log.',
    ]);
}

// ---------- CSV import ----------

function import_csv() {
    $dryRun = !empty($_POST['dry_run']);
    $file = $_FILES['csv'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) fail('Choose a CSV file first.', 422);
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) fail('The upload failed. Please try again.', 422);
    if ($file['size'] > 2 * 1024 * 1024) fail('The CSV file is too large (maximum 2 MB).', 422);
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') fail('Please upload a .csv file.', 422);

    try {
        $rows = read_students_csv($file['tmp_name']);
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 422);
    }
    $checked = check_students_csv($rows);

    if ($dryRun) {
        json_response(['ok' => true, 'dry_run' => true, 'valid_count' => count($checked['valid']), 'failed' => $checked['failed'],
                       'preview' => array_map(fn($r) => ['line' => $r['line']] + $r['data'], array_slice($checked['valid'], 0, 10))]);
    }

    // Import every valid row. A row that fails does not stop the others.
    $imported = []; $failed = $checked['failed'];
    foreach ($checked['valid'] as $row) {
        $password = make_default_password();
        try {
            create_student($row['data'], $password);
            $emailSent = send_student_account_email($row['data']['email'], $row['data']['full_name'], $row['data']['student_id'], $password);
            $imported[] = ['line' => $row['line'], 'student_id' => $row['data']['student_id'], 'full_name' => $row['data']['full_name'],
                           'username' => $row['data']['student_id'], 'password' => $password, 'email_sent' => $emailSent];
        } catch (Throwable $e) {
            write_log('CSV import row ' . $row['line'] . ' failed: ' . $e->getMessage());
            $failed[] = ['line' => $row['line'], 'student_id' => $row['data']['student_id'], 'full_name' => $row['data']['full_name'],
                         'errors' => ['Could not be saved (it may already exist).']];
        }
    }
    usort($failed, fn($a, $b) => $a['line'] <=> $b['line']);
    json_response(['ok' => true, 'dry_run' => false, 'imported' => $imported, 'failed' => $failed]);
}
