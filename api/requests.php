<?php
// All document-request actions. The action is given in the URL:  api/requests.php?action=...
//
//   GET  list, detail, stats                         (any logged-in user; students only see their own)
//   POST create, resubmit                            (student)
//   POST review_file, set_status, run_ai, attach_release   (admin = registrar)
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
require_once __DIR__ . '/../backend/workflow.php';
require_once __DIR__ . '/../backend/ai.php';
require_once __DIR__ . '/../backend/softcopy.php';
require_once __DIR__ . '/../backend/students.php';
require_once __DIR__ . '/../backend/verification.php';
require_once __DIR__ . '/../backend/receipt_security.php';
require_once __DIR__ . '/../backend/release.php';
require_once __DIR__ . '/../backend/signature.php';
register_error_handlers();

$user = require_login(['student', 'admin', 'registrar']);
$action = $_GET['action'] ?? 'list';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    require_csrf();
    // When the upload is bigger than post_max_size in php.ini, PHP drops the whole form.
    $isMultipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
    if ($isMultipart && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('The files are too big for the server. Use smaller files or ask the admin to raise post_max_size in php.ini.', 413);
    }
}

switch ($action) {
    case 'list':   require_method('GET');  list_requests($user); break;
    case 'stats':  require_method('GET');  request_stats($user); break;
    case 'detail': require_method('GET');  request_detail($user); break;
    case 'create':   require_method('POST'); require_login(['student']);   create_request($user); break;
    case 'verify_student_form': require_method('POST'); require_login(['student']); verify_student_form_action($user); break;
    case 'resubmit': require_method('POST'); require_login(['student']);   resubmit_request($user); break;
    case 'review_file':    require_method('POST'); require_login(STAFF_ROLES); review_file($user); break;
    case 'set_status':     require_method('POST'); require_login(STAFF_ROLES); set_request_status($user); break;
    case 'run_ai':         require_method('POST'); require_login(STAFF_ROLES); run_ai_action($user); break;
    case 'attach_release': require_method('POST'); require_login(STAFF_ROLES); attach_release($user); break;
    case 'softcopy_ready': require_method('POST'); require_login(STAFF_ROLES); softcopy_ready($user); break;
    default: fail('Unknown action.', 404);
}

// ---------- Reading ----------

function list_requests($user) {
    $where = [];
    $params = [];
    if ($user['role'] === 'student') { $where[] = 'r.student_id = ?'; $params[] = $user['id']; }
    $status = $_GET['status'] ?? '';
    if ($status !== '' && isset(STATUS_LABELS[$status])) { $where[] = 'r.status = ?'; $params[] = $status; }
    $payment = $_GET['payment'] ?? '';
    if (in_array($payment, ['Paid', 'Unpaid', 'Refunded'], true)) { $where[] = 'r.payment_status = ?'; $params[] = $payment; }
    $documentId = (int)($_GET['document'] ?? 0);                 // "request type": requests that contain this document
    if ($documentId > 0) { $where[] = 'EXISTS (SELECT 1 FROM request_items i WHERE i.request_id = r.id AND i.document_id = ?)'; $params[] = $documentId; }
    $from = $_GET['from'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'r.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    $to = $_GET['to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'r.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $releasedAt = "COALESCE((SELECT h.created_at FROM request_history h WHERE h.request_id = r.id AND h.new_status = 'READY' ORDER BY h.id DESC LIMIT 1), IF(r.status = 'COMPLETED', r.completed_at, NULL))";
    $releasedFrom = $_GET['released_from'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $releasedFrom)) { $where[] = "$releasedAt >= ?"; $params[] = $releasedFrom . ' 00:00:00'; }
    $releasedTo = $_GET['released_to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $releasedTo)) { $where[] = "$releasedAt <= ?"; $params[] = $releasedTo . ' 23:59:59'; }
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        if (($_GET['request_search'] ?? '') === '1') {
            $where[] = '(r.tracking_no LIKE ? OR u.full_name LIKE ?)';
            array_push($params, "%$search%", "%$search%");
        } else {
            $where[] = '(r.tracking_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ?)';
            array_push($params, "%$search%", "%$search%", "%$search%");
        }
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $perPage = 20;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = (int)fetch_one("SELECT COUNT(*) AS c FROM requests r JOIN users u ON u.id = r.student_id $whereSql", $params)['c'];
    $rows = fetch_all("SELECT r.id, r.tracking_no, r.status, r.payment_status, r.payment_method, r.release_method,
                              r.total_amount, r.payment_term, r.expected_release_date, r.ready_at, r.cancel_reason, r.completed_at, r.created_at, u.full_name, u.student_no,
                              s.course, s.status AS student_status,
                              (SELECT v.route FROM request_verifications v WHERE v.request_id = r.id ORDER BY v.id DESC LIMIT 1) AS verification_route,
                              (SELECT v.verdict FROM request_verifications v WHERE v.request_id = r.id ORDER BY v.id DESC LIMIT 1) AS verification_verdict,
                              (SELECT h.created_at FROM request_history h WHERE h.request_id = r.id AND h.new_status = 'READY' ORDER BY h.id DESC LIMIT 1) AS released_at,
                              (SELECT COUNT(*) FROM request_items x WHERE x.request_id = r.id) AS item_count,
                              (SELECT COUNT(*) FROM request_items x WHERE x.request_id = r.id AND x.softcopy_status = 'READY') AS soft_ready
                       FROM requests r JOIN users u ON u.id = r.student_id LEFT JOIN students s ON s.user_id = u.id $whereSql
                       ORDER BY r.created_at DESC, r.id DESC LIMIT $perPage OFFSET $offset", $params);

    $items = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (fetch_all("SELECT i.request_id, d.name, i.quantity, i.unit_price
                            FROM request_items i JOIN documents d ON d.id = i.document_id
                            WHERE i.request_id IN ($marks) ORDER BY i.id", $ids) as $item) {
            $items[$item['request_id']][] = ['name' => $item['name'], 'quantity' => (int)$item['quantity'],
                                             'line_total' => (float)$item['unit_price'] * $item['quantity']];
        }
    }
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['total_amount'] = (float)$r['total_amount'];
        $r['items'] = $items[$r['id']] ?? [];
        $r['status_label'] = STATUS_LABELS[$r['status']];
        $r['portal'] = portal_status($r['status'], $r['verification_route']);
        // "Soft Copy Available" = the registrar uploaded the PDF of every document and marked it ready
        $r['softcopy_label'] = '';
        if (format_wants_softcopy($r['release_method'])) {
            $r['softcopy_label'] = ((int)$r['soft_ready'] === (int)$r['item_count'] && (int)$r['item_count'] > 0) ? 'Soft Copy Available' : 'Waiting for Registrar';
        }
        unset($r['item_count'], $r['soft_ready']);
        // overdue = the registrar's expected release date passed and the document is still not ready
        $r['is_overdue'] = !in_array($r['status'], ['COMPLETED', 'REJECTED', 'CANCELLED', 'READY'], true) && $r['expected_release_date'] && $r['expected_release_date'] < $today;
        $deadline = $r['status'] === 'READY' && $r['payment_status'] === 'Unpaid' ? claim_deadline($r) : null;
        $r['claim_by'] = $deadline ? $deadline->format('Y-m-d') : null;      // the last day to pay and claim
    }
    json_response(['ok' => true, 'requests' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function request_stats($user) {
    $where = $user['role'] === 'student' ? 'WHERE student_id = ?' : 'WHERE 1 = ?';
    $row = fetch_one("SELECT COUNT(*) AS total,
        COALESCE(SUM(status IN ('AI_VERIFYING','FOR_REVIEW','NEEDS_CORRECTION','PROCESSING')), 0) AS processing,
        COALESCE(SUM(status = 'FOR_REVIEW'), 0) AS for_review,
        COALESCE(SUM(payment_status = 'Unpaid' AND status NOT IN ('REJECTED','COMPLETED','CANCELLED')), 0) AS pending_payment,
        COALESCE(SUM(status = 'READY'), 0) AS ready,
        COALESCE(SUM(status = 'COMPLETED'), 0) AS completed,
        COALESCE(SUM(status = 'REJECTED'), 0) AS rejected,
        COALESCE(SUM(status = 'CANCELLED'), 0) AS cancelled
        FROM requests $where", [$user['role'] === 'student' ? $user['id'] : 1]);
    $stats = array_map('intval', $row);
    // Money comes from the payment records, never from adding up requests: verified payments that were not refunded,
    // and the balance still to collect on requests that can still be paid.
    $money = fetch_one("SELECT
        COALESCE((SELECT SUM(p.amount) FROM payments p JOIN requests r ON r.id = p.request_id WHERE p.refunded_at IS NULL AND r.payment_status = 'Paid' " . ($user['role'] === 'student' ? 'AND r.student_id = ?' : 'AND 1 = ?') . "), 0) AS collected,
        COALESCE((SELECT SUM(r.total_amount) FROM requests r WHERE r.payment_status = 'Unpaid' AND r.status NOT IN ('REJECTED','COMPLETED','CANCELLED') " . ($user['role'] === 'student' ? 'AND r.student_id = ?' : 'AND 1 = ?') . "), 0) AS unpaid_balance",
        [$user['role'] === 'student' ? $user['id'] : 1, $user['role'] === 'student' ? $user['id'] : 1]);
    $stats['collected'] = round((float)$money['collected'], 2);
    $stats['unpaid_balance'] = round((float)$money['unpaid_balance'], 2);
    json_response(['ok' => true, 'stats' => $stats]);
}

// Loads a request. A student can only open their own (others get 404, so ids cannot be guessed).
function load_request($id, $user) {
    $request = fetch_one('SELECT r.*, u.full_name, u.student_no FROM requests r JOIN users u ON u.id = r.student_id WHERE r.id = ?', [$id]);
    if (!$request || ($user['role'] === 'student' && (int)$request['student_id'] !== (int)$user['id'])) fail('Request not found.', 404);
    return $request;
}

function request_detail($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    $id = (int)$request['id'];
    $request['credential_form'] = $request['credential_form_json'] ? json_decode($request['credential_form_json'], true) : null;
    $request['student_verification_form'] = $request['student_verification_form_json']
        ? json_decode($request['student_verification_form_json'], true) : null;
    unset($request['credential_form_json']);
    unset($request['student_verification_form_json']);
    // Only the owner student and the admin/registrar may see uploaded files.
    $isAdmin = in_array($user['role'], STAFF_ROLES, true);       // admin and registrar
    $canSeeFiles = $isAdmin || $user['role'] === 'student';

    $files = [];
    $ai = [];
    if ($canSeeFiles) {
        foreach (fetch_all('SELECT * FROM request_files WHERE request_id = ? AND is_current = 1', [$id]) as $f) $files[] = $f;
        if ($files) {
            $marks = implode(',', array_fill(0, count($files), '?'));
            foreach (fetch_all("SELECT * FROM ai_verifications WHERE file_id IN ($marks) ORDER BY id", array_column($files, 'id')) as $a) {
                $ai[$a['file_id']] = $a; // the newest result wins because of ORDER BY id
            }
        }
    }

    $items = fetch_all('SELECT i.id, i.document_id, i.quantity, i.unit_price, i.purpose, d.name, d.category, i.softcopy_status, i.softcopy_uploaded_by, i.softcopy_uploaded_at,
                               i.softcopy_ready_at, i.download_count, i.download_limit, i.last_downloaded_at FROM request_items i
                        JOIN documents d ON d.id = i.document_id WHERE i.request_id = ? ORDER BY i.id', [$id]);
    $needsFix = false;
    foreach ($items as &$item) {
        $item['requirements'] = [];
        $credentialFormItem = $request['credential_form']
            && credential_form_required_document($item['name']);
        $studentVerificationFormItem = $request['student_verification_form']
            && legacy_student_verification_document($item['name'], $item['category']);
        $itemRequirements = effective_document_requirements(
            $item['document_id'], $item['name'], $item['category'], !$credentialFormItem && !$studentVerificationFormItem
        );
        if ($credentialFormItem) {
            $itemRequirements = [[
                'id' => 0, 'name' => 'Completed credential request form',
                'description' => 'The completed credential form is included with this request.',
                'is_required' => 1, 'allowed_types' => '', 'max_size_mb' => 0, 'form_complete' => true,
            ]];
        } elseif ($studentVerificationFormItem) {
            $itemRequirements = [[
                'id' => 0, 'name' => 'Completed Student Verification form',
                'description' => 'The student information form is included with this request.',
                'is_required' => 1, 'allowed_types' => '', 'max_size_mb' => 0, 'form_complete' => true,
            ]];
        }
        foreach ($itemRequirements as $req) {
            if (!empty($req['form_complete'])) {
                $req['file'] = null;
                $req['needs_fix'] = false;
                $item['requirements'][] = $req;
                continue;
            }
            $req['file'] = null;
            foreach ($files as $f) {
                if ($f['kind'] === 'requirement' && (int)$f['item_id'] === (int)$item['id'] && (int)$f['requirement_id'] === (int)$req['id']) {
                    $req['file'] = file_for_browser($f, $ai[$f['id']] ?? null, $isAdmin);
                }
            }
            $req['needs_fix'] = !$req['file'] || in_array($req['file']['review_status'], ['Rejected', 'Resubmit'], true);
            if ($req['needs_fix']) $needsFix = true;
            $item['requirements'][] = $req;
        }
        // Soft copy (PDF). The student only gets the state (the file is only sent by api/softcopy.php); the staff also see the file details.
        $item['softcopy'] = null;
        $item['release_file'] = null;
        if (format_wants_softcopy($request['release_method'])) {
            $latest = fetch_one('SELECT id, status, reason_type, reason, rejection_reason, created_at FROM softcopy_download_requests WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$item['id']]);
            $extraCount = (int)fetch_one('SELECT COUNT(*) AS c FROM softcopy_download_requests WHERE item_id = ?', [$item['id']])['c'];
            $item['softcopy'] = softcopy_state($item, $request, $latest, $extraCount);
            if ($isAdmin) {
                $file = softcopy_current_file($item['id']);
                $uploader = $item['softcopy_uploaded_by'] ? fetch_one('SELECT full_name FROM users WHERE id = ?', [$item['softcopy_uploaded_by']]) : null;
                $item['softcopy'] += [
                    'status' => $item['softcopy_status'], 'file_name' => $file ? $file['original_name'] : null,
                    'file_size' => $file ? (int)$file['size_bytes'] : null, 'uploaded_at' => $item['softcopy_uploaded_at'],
                    'uploaded_by' => $uploader ? $uploader['full_name'] : null, 'ready_at' => $item['softcopy_ready_at'],
                    'last_downloaded_at' => $item['last_downloaded_at'],
                    'pending_request' => ($latest && $latest['status'] === 'PENDING') ? $latest : null,
                ];
                $item['release_file'] = $file ? ['id' => (int)$file['id'], 'original_name' => $file['original_name']] : null;
            }
        }
        unset($item['softcopy_status'], $item['softcopy_uploaded_by'], $item['softcopy_uploaded_at'], $item['softcopy_ready_at'],
              $item['download_count'], $item['download_limit'], $item['last_downloaded_at']);
    }

    $history = fetch_all('SELECT h.old_status, h.new_status, h.remarks, h.created_at, u.full_name AS by_name
                          FROM request_history h LEFT JOIN users u ON u.id = h.changed_by
                          WHERE h.request_id = ? ORDER BY h.id', [$id]);
    $request['status_label'] = STATUS_LABELS[$request['status']];
    $request['verification'] = latest_verification($id);
    $request['portal'] = portal_status($request['status'], $request['verification']['route'] ?? null);
    $request['can_see_files'] = $canSeeFiles;
    ensure_receipt_security_columns();
    // "clean" = the receipt has no security warnings. The warnings, the Official Receipt No. and the amount are only sent to staff.
    $receipt = fetch_one("SELECT original_name, status, remarks, uploaded_at, official_receipt_no, amount_paid, security_flags, (security_flags IS NULL OR security_flags = '') AS clean FROM payment_receipts WHERE request_id = ?", [$id]);
    if ($receipt) {
        $receipt['clean'] = (bool)$receipt['clean'];
        $receipt['flags'] = ($isAdmin && $receipt['security_flags']) ? (json_decode($receipt['security_flags'], true) ?: []) : [];
        if (!$isAdmin) unset($receipt['official_receipt_no'], $receipt['amount_paid']);
        unset($receipt['security_flags']);
    }
    $request['payment_receipt'] = $receipt;
    $request['needs_fix'] = $needsFix;
    $signature = fetch_one('SELECT signed_at FROM request_signatures WHERE request_id = ?', [$id]);
    $request['signature'] = $signature ? ['signed_at' => $signature['signed_at']] : null;       // the picture itself comes from api/signature.php
    $deadline = $request['status'] === 'READY' && $request['payment_status'] === 'Unpaid' ? claim_deadline($request) : null;
    $request['claim_by'] = $deadline ? $deadline->format('Y-m-d') : null;
    $request['payment'] = $isAdmin
        ? fetch_one('SELECT p.receipt_no, p.amount, p.method, p.reference_no, p.paid_at, p.refunded_at, p.refund_reason, u.full_name AS received_by
                     FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.request_id = ?', [$id])
        : null;
    json_response(['ok' => true, 'request' => $request, 'items' => $items, 'history' => $history]);
}

// Only send the browser what that role may see. Students get the file and its status/reason only:
// the details of the automatic check are for the admin/registrar.
function file_for_browser($f, $ai, $isAdmin) {
    $out = [
        'id' => (int)$f['id'], 'original_name' => $f['original_name'], 'mime_type' => $f['mime_type'],
        'size_bytes' => (int)$f['size_bytes'], 'review_status' => $f['review_status'],
        'review_remarks' => $f['review_remarks'], 'uploaded_at' => $f['uploaded_at'], 'ai' => null,
    ];
    if ($ai && $isAdmin) {
        $out['ai'] = [
            'status' => $ai['status'], 'reason' => $ai['reason'], 'auto_declined' => (bool)$ai['auto_declined'],
            'extracted_text' => $ai['extracted_text'], 'confidence' => $ai['confidence'],
            'name_on_document' => $ai['name_on_document'], 'name_match_score' => $ai['name_match_score'],
            'model' => $ai['model'], 'error_message' => $ai['error_message'], 'created_at' => $ai['created_at'],
        ];
    }
    return $out;
}

// ---------- Creating a request ----------
// The files were already uploaded and checked by api/verify.php. The browser sends the check ids in the "payload":
//   payload.checks = { "ROW:REQUIREMENT_ID": CHECK_ID, ... }

// A VERIFIED upload of this student that was not used yet. Returns the row or null.
// This is the server-side rule: a file that is declined, needs correction, is still pending or was never checked is refused here,
// no matter what the browser sends.
function load_usable_check($userId, $checkId, $requirementId) {
    if ($checkId <= 0) return null;
    $check = fetch_one("SELECT * FROM upload_checks WHERE id = ? AND user_id = ? AND requirement_id = ? AND used_file_id IS NULL
                        AND status = 'VERIFIED' AND stored_name IS NOT NULL AND created_at > (NOW() - INTERVAL 3 DAY)",
                       [$checkId, $userId, $requirementId]);
    if ($check && !is_file(stored_file_path($check['stored_name']))) return null;
    return $check;
}

function active_request_for_document($userId, $documentId) {
    return fetch_one("SELECT r.tracking_no FROM requests r
                      JOIN request_items i ON i.request_id = r.id
                      WHERE r.student_id = ? AND i.document_id = ?
                        AND r.status NOT IN ('COMPLETED', 'REJECTED', 'CANCELLED')
                      ORDER BY r.created_at, r.id LIMIT 1", [$userId, $documentId]);
}

function duplicate_document_request_error($documentName, $trackingNo) {
    fail("You already have a request for \"$documentName\" in progress ($trackingNo). You can request it again after it is completed, rejected or cancelled.", 422);
}

function credential_form_value($data, $key, $maxLength) {
    return clean_text($data[$key] ?? '', $maxLength);
}

function valid_form_date($value, $allowFuture = false) {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value && ($allowFuture || $date <= new DateTime('today'));
}

function validate_credential_form($input, $items, $student) {
    $requestedDocuments = array_values(array_map(
        fn($item) => $item['doc']['name'],
        array_filter($items, fn($item) => credential_form_required_document($item['doc']['name']))
    ));
    if (!$requestedDocuments) return null;
    if (!is_array($input)) fail('Complete the credential request form before submitting.', 422);

    $data = [
        'last_name' => credential_form_value($input, 'last_name', 80),
        'first_name' => credential_form_value($input, 'first_name', 80),
        'middle_name' => credential_form_value($input, 'middle_name', 80),
        'address' => credential_form_value($input, 'address', 255),
        'birthdate' => credential_form_value($input, 'birthdate', 10),
        'gender' => credential_form_value($input, 'gender', 30),
        'student_no' => credential_form_value($student, 'student_no', 30),
        'grade_level' => credential_form_value($input, 'grade_level', 40),
        'strand' => credential_form_value($input, 'strand', 80),
        'course' => credential_form_value($student, 'course', 100),
        'requested_documents' => $requestedDocuments,
        'graduated' => credential_form_value($input, 'graduated', 3),
        'graduation_date' => credential_form_value($input, 'graduation_date', 10),
        'last_attended_school_year' => credential_form_value($input, 'last_attended_school_year', 9),
        'elementary_school' => credential_form_value($input, 'elementary_school', 150),
        'junior_high_school' => credential_form_value($input, 'junior_high_school', 150),
        'senior_high_school' => credential_form_value($input, 'senior_high_school', 150),
        'telephone' => credential_form_value($input, 'telephone', 30),
        'mobile' => credential_form_value($input, 'mobile', 30),
        'purpose' => credential_form_value($input, 'purpose', 30),
        'other_purpose' => credential_form_value($input, 'other_purpose', 120),
        'signature' => credential_form_value($input, 'signature', 160),
        'notes' => credential_form_value($input, 'notes', 1000),
    ];

    if ($data['last_name'] === '' || $data['first_name'] === '' || mb_strlen($data['address']) < 5) {
        fail('Enter your first name, last name, and complete address in the credential request form.', 422);
    }
    if (!valid_form_date($data['birthdate'])) fail('Enter a valid birthdate in the credential request form.', 422);
    if (!in_array($data['gender'], ['Female', 'Male', 'Prefer not to say'], true)) fail('Choose a valid gender in the credential request form.', 422);
    if (strlen(preg_replace('/\D/', '', $data['mobile'])) < 7) fail('Enter a valid mobile number in the credential request form.', 422);
    if (!in_array($data['graduated'], ['yes', 'no'], true)) fail('Select your graduation status in the credential request form.', 422);
    if ($data['graduated'] === 'yes' && !valid_form_date($data['graduation_date'])) {
        fail('Enter a valid graduation date in the credential request form.', 422);
    }
    if ($data['graduated'] === 'no'
        && (!preg_match('/^(\d{4})-(\d{4})$/', $data['last_attended_school_year'], $schoolYear)
            || (int)$schoolYear[2] !== (int)$schoolYear[1] + 1)) {
        fail('Enter the school year last attended as consecutive years, for example 2025-2026.', 422);
    }
    if (!in_array($data['purpose'], ['Employment', 'Evaluation', 'Board Examination', 'Others'], true)
        || ($data['purpose'] === 'Others' && mb_strlen($data['other_purpose']) < 3)) {
        fail('Choose or specify a valid purpose in the credential request form.', 422);
    }
    if (mb_strlen($data['signature']) < 3) fail('Type your full name as your signature in the credential request form.', 422);
    return $data;
}

function student_verification_result($input, $student) {
    if (!is_array($input)) $input = [];

    $studentId = credential_form_value($input, 'student_id', 30);
    $studentName = credential_form_value($input, 'student_name', 120);
    $course = credential_form_value($input, 'course_program', 100);
    $academicYear = credential_form_value($input, 'academic_year', 9);
    $gradeLevel = credential_form_value($input, 'grade_level', 40);
    $profileId = credential_form_value($student, 'student_no', 30);
    if ($profileId === '') $profileId = credential_form_value($student, 'username', 50);
    $profileName = credential_form_value($student, 'full_name', 120);
    $profileCourse = credential_form_value($student, 'course', 100);
    $profileYear = credential_form_value($student, 'academic_year', 9);
    $profileGrade = !empty($student['year_level'])
        ? year_label($student['school_level'] ?? '', (int)$student['year_level'], $profileCourse)
        : '';

    $normalize = fn($value) => mb_strtolower(preg_replace('/\s+/', ' ', trim($value)));
    $schoolYear = [];
    $validAcademicYear = preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $schoolYear)
        && (int)$schoolYear[2] === (int)$schoolYear[1] + 1;
    $checks = [
        [
            'field' => 'Student ID',
            'passed' => $profileId !== '' && preg_match('/^\d{4}-\d{5}$/', $studentId) && $studentId === $profileId,
            'result' => $profileId === ''
                ? 'Student ID is missing from your profile.'
                : (!preg_match('/^\d{4}-\d{5}$/', $studentId)
                    ? 'Use the ID format 1234-56789.'
                    : ($studentId === $profileId ? 'Matches your student profile.' : 'Does not match your student profile.')),
        ],
        [
            'field' => 'Student Name',
            'passed' => $profileName !== '' && $normalize($studentName) === $normalize($profileName),
            'result' => $profileName === ''
                ? 'Student name is missing from your profile.'
                : ($normalize($studentName) === $normalize($profileName) ? 'Matches your student profile.' : 'Does not match your student profile.'),
        ],
        [
            'field' => 'Course/Program',
            'passed' => $profileCourse !== '' && $normalize($course) === $normalize($profileCourse),
            'result' => $profileCourse === ''
                ? 'Course/Program is missing from your profile.'
                : ($normalize($course) === $normalize($profileCourse) ? 'Matches your student profile.' : 'Does not match your student profile.'),
        ],
        [
            'field' => 'Academic Year',
            'passed' => $validAcademicYear && ($profileYear === '' || $academicYear === $profileYear),
            'result' => !$validAcademicYear
                ? 'Enter consecutive years, for example 2025-2026.'
                : ($profileYear !== '' && $academicYear !== $profileYear
                    ? 'Does not match your student profile.'
                    : ($profileYear !== '' ? 'Matches your student profile.' : 'Academic year format is valid.')),
        ],
        [
            'field' => 'Grade Level',
            'passed' => $gradeLevel !== '' && ($profileGrade === '' || $normalize($gradeLevel) === $normalize($profileGrade)),
            'result' => $gradeLevel === ''
                ? 'Enter your grade level.'
                : ($profileGrade !== '' && $normalize($gradeLevel) !== $normalize($profileGrade)
                    ? 'Does not match your student profile.'
                    : ($profileGrade !== '' ? 'Matches your student profile.' : 'No grade level is set in your profile to compare.')),
        ],
    ];
    $verified = !array_filter($checks, fn($check) => !$check['passed']);
    if (!$verified) return ['verified' => false, 'checks' => $checks, 'form' => null];

    return [
        'verified' => true,
        'form' => [
            'student_id' => $profileId,
            'student_name' => $profileName,
            'course_program' => $profileCourse,
            'academic_year' => $academicYear,
            'grade_level' => $gradeLevel,
        ],
        'checks' => $checks,
    ];
}

function validate_student_verification_form($input, $items, $student) {
    $required = array_filter(
        $items,
        fn($item) => student_verification_form_required_document($item['doc']['name'], $item['doc']['category'])
    );
    if (!$required) return null;
    $result = student_verification_result($input, $student);
    if (!$result['verified']) {
        $failed = array_filter($result['checks'], fn($check) => !$check['passed']);
        fail('Student Verification failed. Correct the fields that do not match before submitting.', 422, ['checks' => array_values($failed)]);
    }
    return $result['form'];
}

function verify_student_form_action($user) {
    $input = read_json_body();
    $items = $input['items'] ?? [];
    if (!is_array($items) || !$items || count($items) > 15) fail('Choose a Certificate before verifying student information.', 422);

    $documentIds = array_values(array_unique(array_filter(array_map(
        fn($item) => (int)($item['document_id'] ?? 0),
        $items
    ))));
    if (!$documentIds) fail('Choose a valid Certificate before verifying student information.', 422);
    $marks = implode(',', array_fill(0, count($documentIds), '?'));
    $documents = fetch_all("SELECT name, category FROM documents WHERE id IN ($marks) AND is_active = 1", $documentIds);
    if (!$documents || !array_filter($documents, fn($document) => student_verification_form_required_document($document['name'], $document['category']))) {
        fail('Student Verification is only available for Certification documents.', 422);
    }

    $student = fetch_one('SELECT u.student_no, u.username, u.full_name, s.school_level, s.course, s.year_level, s.academic_year
                          FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?', [$user['id']]);
    if (!$student) fail('Student profile not found.', 404);
    $result = student_verification_result($input['student_verification_form'] ?? null, $student);
    json_response(['ok' => true, 'verified' => $result['verified'], 'form' => $result['form'], 'checks' => $result['checks']]);
}

// Puts a verified upload into the request: file row, link back to the check, and the result for the registrar.
function attach_check($requestId, $itemId, $requirementId, $check) {
    $fileId = save_file_row($requestId, $itemId, $requirementId, 'requirement', $check['original_name'], $check['stored_name'],
                            ['mime' => $check['mime_type'], 'size' => $check['size_bytes'], 'sha256' => $check['sha256']]);
    query('UPDATE upload_checks SET used_file_id = ? WHERE id = ?', [$fileId, $check['id']]);
    save_ai_result($requestId, $fileId, [
        'status' => $check['result_status'], 'reason' => $check['reason'], 'extracted_text' => $check['extracted_text'],
        'confidence' => $check['confidence'], 'name_on_document' => $check['name_on_document'], 'name_match_score' => $check['name_match_score'],
        'auto_declined' => $check['auto_declined'], 'model' => $check['model'], 'error' => $check['error_message'],
    ]);
    return $fileId;
}

function create_request($user) {
    $payload = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($payload)) fail('Invalid request data.', 422);
    // The page makes a new random token each time the form is opened. The same token twice = the same click sent twice
    // (double click, refresh, slow network): the first saved request is returned and nothing new is created.
    $token = clean_text($payload['submission_token'] ?? '', 64);
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)) fail('Please reload the page and try again.', 422);
    answer_if_already_submitted($token, (int)$user['id']);
    $student = fetch_one('SELECT s.school_level, s.course, s.year_level, s.status, s.academic_year,
                                 u.student_no, u.username, u.full_name
                          FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?', [$user['id']]);

    $releaseMethod = $payload['release_method'] ?? '';
    $paymentMethod = $payload['payment_method'] ?? '';
    if (!in_array($releaseMethod, ['Hardcopy', 'Softcopy', 'Both'], true)) fail('Choose a document format.', 422);
    if (!in_array($paymentMethod, ['Cash', 'GCash'], true)) fail('Choose a payment method.', 422);

    $rows = $payload['items'] ?? [];
    if (!is_array($rows) || !$rows || count($rows) > 15) fail('Choose at least one document.', 422);
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];

    // 1. Check every chosen document and its quantity.
    $items = [];
    $seen = [];
    foreach ($rows as $row) {
        $documentId = (int)($row['document_id'] ?? 0);
        $quantity = (int)($row['quantity'] ?? 0);
        $rowKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['row'] ?? ''));
        $doc = fetch_one('SELECT * FROM documents WHERE id = ? AND is_active = 1', [$documentId]);
        if (!$doc) fail('One of the selected documents is not available.', 422);
        if (isset($seen[$documentId])) fail('Each document can only be selected once.', 422);
        $activeRequest = active_request_for_document($user['id'], $documentId);
        if ($activeRequest) duplicate_document_request_error($doc['name'], $activeRequest['tracking_no']);
        $seen[$documentId] = true;
        if ($quantity < 1 || $quantity > 5) fail('You can request 1 to 5 copies of a document.', 422);
        // "Both" needs a document that can be released as hard copy AND as soft copy
        $needed = $releaseMethod === 'Both' ? ['Hardcopy', 'Softcopy'] : [$releaseMethod];
        if (array_diff($needed, explode(',', $doc['release_methods']))) {
            fail("\"{$doc['name']}\" is not available as $releaseMethod. Submit it in a separate request.", 422);
        }
        $doc['requirements'] = effective_document_requirements($documentId, $doc['name'], $doc['category']);
        // The purpose is a choice. "Others" needs the written purpose, and the written text is what we save.
        try {
            $itemPurpose = resolve_item_purpose($row['purpose'] ?? '', $row['purpose_other'] ?? '', $doc['name']);
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
        $items[] = ['row' => $rowKey, 'doc' => $doc, 'quantity' => $quantity, 'purpose' => $itemPurpose];
    }

    // The request keeps one summary purpose (shown in lists); each document keeps its own purpose in request_items.
    $purposes = array_values(array_unique(array_column($items, 'purpose')));
    $purpose = count($purposes) === 1
        ? $purposes[0]
        : mb_substr(implode('; ', array_map(fn($item) => $item['doc']['name'] . ': ' . $item['purpose'], $items)), 0, 255);

    $credentialForm = validate_credential_form($payload['credential_form'] ?? null, $items, $student ?? []);
    $studentVerificationForm = validate_student_verification_form(
        $payload['student_verification_form'] ?? null, $items, $student ?? []
    );
    // TOR / Form 137 / Honorable Dismissal (the credential form) also need the drawn signature. Checked here, before anything is saved.
    $signaturePng = null;
    if ($credentialForm !== null) {
        try {
            $signaturePng = signature_from_data_url($payload['signature_image'] ?? '');
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
    }

    // 2. Required document check: every required document must be uploaded AND have passed the verification.
    $missing = []; $errors = []; $uploads = []; $hashes = [];
    foreach ($items as $index => $item) {
        foreach ($item['doc']['requirements'] as $req) {
            $checkId = (int)($checks[$item['row'] . ':' . $req['id']] ?? 0);
            if ($checkId === 0) {
                if ($req['is_required']) $missing[] = ['document' => $item['doc']['name'], 'requirement' => $req['name']];
                continue;
            }
            $check = load_usable_check($user['id'], $checkId, (int)$req['id']);
            if (!$check) {
                $errors[] = "{$item['doc']['name']} - {$req['name']}: this document is not verified yet. Please upload it and wait for the verification.";
                continue;
            }
            if (isset($hashes[$check['sha256']])) {
                $errors[] = "{$item['doc']['name']} - {$req['name']}: this is the same file you uploaded for \"{$hashes[$check['sha256']]}\". Upload a different file.";
                continue;
            }
            $hashes[$check['sha256']] = $req['name'];
            $uploads[$index][$req['id']] = $check;
        }
    }
    if ($missing) fail('Required document missing. Please upload all required documents before continuing.', 422, ['missing' => $missing]);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    // 3. Save everything in one transaction.
    // The total is always calculated here from the catalog prices (never from the browser): price x copies for each document.
    $total = 0;
    foreach ($items as $item) $total += round((float)$item['doc']['price'] * $item['quantity'], 2);
    $total = round($total, 2);
    $pdo = db();
    $verification = null;
    $signatureFile = null;
    try {
        $pdo->beginTransaction();
        query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user['id']]);      // one request at a time for this student
        if (find_request_by_token($token)) { $pdo->rollBack(); answer_if_already_submitted($token, (int)$user['id']); }
        foreach ($items as $item) {
            $activeRequest = active_request_for_document($user['id'], (int)$item['doc']['id']);
            if ($activeRequest) {
                $pdo->rollBack();
                duplicate_document_request_error($item['doc']['name'], $activeRequest['tracking_no']);
            }
        }
        $tracking = make_tracking_number();
        // No release date is made by the system any more: the Registrar sets expected_release_date by hand.
        // The student pays when claiming the document (payment_term UPON_RELEASE).
        query('INSERT INTO requests (tracking_no, submission_token, student_id, purpose, release_method, payment_method, payment_term, total_amount, credential_form_json, student_verification_form_json)
               VALUES (?, ?, ?, ?, ?, ?, \'UPON_RELEASE\', ?, ?, ?)',
            [$tracking, $token, $user['id'], $purpose, $releaseMethod, $paymentMethod, $total,
             $credentialForm ? json_encode($credentialForm, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
             $studentVerificationForm ? json_encode($studentVerificationForm, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null]);
        $requestId = (int)$pdo->lastInsertId();
        if ($signaturePng !== null) {
            // saved once with the request; there is no code that changes it later
            $signatureFile = store_signature_png($signaturePng);
            query('INSERT INTO request_signatures (request_id, stored_name, sha256, signed_name, signed_ip) VALUES (?, ?, ?, ?, ?)',
                [$requestId, $signatureFile['stored'], $signatureFile['sha256'], $credentialForm['signature'], request_ip()]);
        }
        foreach ($items as $index => $item) {
            query('INSERT INTO request_items (request_id, document_id, quantity, unit_price, purpose) VALUES (?, ?, ?, ?, ?)',
                [$requestId, $item['doc']['id'], $item['quantity'], $item['doc']['price'], $item['purpose']]);
            $itemId = (int)$pdo->lastInsertId();
            foreach ($uploads[$index] ?? [] as $requirementId => $check) attach_check($requestId, $itemId, (int)$requirementId, $check);
        }
        add_history($requestId, null, 'SUBMITTED', $user['id'], '');
        $verification = finish_submission($requestId);          // inside the same transaction: the request can never stay stuck at "Submitted"
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();       // the verified files stay, so the student can simply try again
        if ($signatureFile) delete_stored_file($signatureFile['stored']);
        // Two identical submissions at the same moment: the database refused the second token. Return the first request.
        if ($e instanceof PDOException && (string)$e->getCode() === '23000' && strpos($e->getMessage(), 'uq_requests_submission') !== false) {
            answer_if_already_submitted($token, (int)$user['id']);
        }
        write_log('create_request failed: ' . $e->getMessage());
        fail('Could not save your request. Please try again.', 500);
    }

    notify_verification_result($requestId, $tracking, (int)$user['id'], $user['full_name'], $verification);
    $needsCorrection = $verification['status'] === 'NEEDS_CORRECTION';

    json_response(['ok' => true, 'request' => ['id' => $requestId, 'tracking_no' => $tracking, 'total' => $total, 'status' => $verification['status'],
        'route' => $verification['route'], 'verdict' => $verification['verdict'],
        'message' => $needsCorrection
            ? $verification['summary']
            : ($verification['route'] === 'REGISTRAR'
                ? 'Your request passed AI verification and was sent to the Registrar for academic records verification and approval.'
                : 'Your request passed AI verification and is waiting for the Registrar. You pay when you claim your document.')]], 201);
}

function find_request_by_token($token) {
    return fetch_one('SELECT id, tracking_no, student_id, total_amount, status FROM requests WHERE submission_token = ?', [$token]);
}

// If this token was already used, stop here and give the browser the request that was saved the first time.
function answer_if_already_submitted($token, $userId) {
    $existing = find_request_by_token($token);
    if (!$existing) return;
    if ((int)$existing['student_id'] !== $userId) fail('Please reload the page and try again.', 409);
    $verification = latest_verification((int)$existing['id']);
    json_response(['ok' => true, 'already_submitted' => true, 'request' => ['id' => (int)$existing['id'], 'tracking_no' => $existing['tracking_no'],
        'total' => (float)$existing['total_amount'], 'status' => $existing['status'], 'route' => $verification['route'] ?? null, 'verdict' => $verification['verdict'] ?? null,
        'message' => 'This request was already submitted. Your reference number is ' . $existing['tracking_no'] . '.']], 200);
}

function make_tracking_number() {
    for ($i = 0; $i < 10; $i++) {
        $number = 'COLM-' . date('Ymd') . '-' . random_int(10000, 99999);
        if (!fetch_one('SELECT id FROM requests WHERE tracking_no = ?', [$number])) return $number;
    }
    throw new RuntimeException('Could not create a tracking number.');
}

function save_file_row($requestId, $itemId, $requirementId, $kind, $originalName, $storedName, $info) {
    $name = clean_text(basename(str_replace('\\', '/', $originalName)), 255);
    query('INSERT INTO request_files (request_id, item_id, requirement_id, kind, original_name, stored_name, mime_type, size_bytes, sha256)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$requestId, $itemId, $requirementId, $kind, $name, $storedName, $info['mime'], $info['size'], $info['sha256']]);
    return (int)db()->lastInsertId();
}

// SUBMITTED / NEEDS_CORRECTION -> "Document Checking" -> For Admin Review (or back to Needs Correction).
// The files were already checked when they were uploaded; backend/verification.php now checks the whole request and routes it.
function finish_submission($requestId) {
    return finish_request_verification($requestId);
}

// ---------- Student fixes the documents the registrar asked for ----------

function resubmit_request($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    if ($request['status'] !== 'NEEDS_CORRECTION') fail('This request does not need correction.', 422);
    $requestId = (int)$request['id'];
    $payload = json_decode($_POST['payload'] ?? '', true);
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];

    $wanted = fetch_all("SELECT i.id AS item_id, d.name AS document_name, r.*
                         FROM request_items i
                         JOIN documents d ON d.id = i.document_id
                         JOIN document_requirements r ON r.document_id = d.id
                         LEFT JOIN request_files f ON f.item_id = i.id AND f.requirement_id = r.id AND f.is_current = 1 AND f.kind = 'requirement'
                         WHERE i.request_id = ? AND (f.id IS NULL OR f.review_status IN ('Rejected','Resubmit'))", [$requestId]);
    if (!$wanted) fail('There is nothing to correct.', 422);

    $hashes = array_column(fetch_all("SELECT sha256 FROM request_files WHERE request_id = ? AND is_current = 1", [$requestId]), 'sha256');
    $missing = []; $errors = []; $uploads = [];
    foreach ($wanted as $w) {
        $check = load_usable_check($user['id'], (int)($checks[$w['item_id'] . ':' . $w['id']] ?? 0), (int)$w['id']);
        if (!$check) { $missing[] = ['document' => $w['document_name'], 'requirement' => $w['name']]; continue; }
        if (in_array($check['sha256'], $hashes, true)) { $errors[] = "{$w['name']}: this file was already uploaded. Upload the corrected file."; continue; }
        $hashes[] = $check['sha256'];
        $uploads[] = [$w, $check];
    }
    if ($missing) fail('Required document missing. Please upload all the documents that need correction.', 422, ['missing' => $missing]);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    $pdo = db();
    $verification = null;
    try {
        $pdo->beginTransaction();
        foreach ($uploads as [$w, $check]) {
            query("UPDATE request_files SET is_current = 0 WHERE item_id = ? AND requirement_id = ? AND kind = 'requirement'", [$w['item_id'], $w['id']]);
            attach_check($requestId, (int)$w['item_id'], (int)$w['id'], $check);
        }
        add_history($requestId, 'NEEDS_CORRECTION', 'NEEDS_CORRECTION', $user['id'], 'Student uploaded corrected documents.');
        $verification = finish_submission($requestId);          // inside the same transaction (see create_request)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        write_log('resubmit failed: ' . $e->getMessage());
        fail('Could not save your corrections. Please try again.', 500);
    }

    notify_verification_result($requestId, $request['tracking_no'], (int)$user['id'], $user['full_name'], $verification, true);
    json_response(['ok' => true, 'status' => $verification['status'],
        'message' => $verification['status'] === 'NEEDS_CORRECTION'
            ? $verification['summary']
            : 'Your corrected documents passed AI verification and were sent for review.']);
}

// ---------- Admin / registrar actions ----------

function review_file($user) {
    $input = read_json_body();
    $decision = $input['decision'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($decision, ['Verified', 'Rejected', 'Resubmit'], true)) fail('Invalid decision.', 422);
    if ($decision !== 'Verified' && $remarks === '') fail('Please write a remark so the student knows what to fix.', 422);

    $file = fetch_one("SELECT f.id, f.request_id, r.status FROM request_files f JOIN requests r ON r.id = f.request_id
                       WHERE f.id = ? AND f.kind = 'requirement' AND f.is_current = 1", [(int)($input['file_id'] ?? 0)]);
    if (!$file) fail('File not found.', 404);
    if (!in_array($file['status'], ['FOR_REVIEW', 'NEEDS_CORRECTION'], true)) fail('Documents can only be reviewed while the request is waiting for review or correction.', 422);

    query('UPDATE request_files SET review_status = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
        [$decision, $remarks !== '' ? $remarks : null, $user['id'], $file['id']]);

    // Nothing is declined any more (for example the admin approved an AI-declined file): back to review.
    if ($file['status'] === 'NEEDS_CORRECTION') {
        $stillBad = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1
                               AND review_status IN ('Rejected','Resubmit')", [$file['request_id']])['c'];
        if ($stillBad == 0) change_status((int)$file['request_id'], 'FOR_REVIEW', $user['id'], 'All documents are accepted.');
    }
    json_response(['ok' => true]);
}

function set_request_status($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    $id = (int)$request['id'];
    $new = $input['status'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($new, ['FOR_REVIEW', 'PROCESSING', 'NEEDS_CORRECTION', 'READY', 'COMPLETED', 'REJECTED'], true)) {
        fail('That status is set by the system, not by hand.', 422);        // CANCELLED is only set by the 14-day rule (backend/release.php)
    }
    if (in_array($new, ['NEEDS_CORRECTION', 'REJECTED'], true) && $remarks === '') fail('Please write a remark for the student.', 422);
    $upon = $request['payment_term'] === 'UPON_RELEASE';

    if ($new === 'READY' && $request['status'] === 'FOR_REVIEW') {
        // "Ready to Release" in one step: the request passes through PROCESSING by itself once the documents are fine
        // (and, for the older pay-first requests, the Official Receipt).
        $notVerified = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1 AND review_status <> 'Verified'", [$id])['c'];
        if ($notVerified > 0) fail('Please verify every uploaded document first.', 422);
        if (!payment_gate_ok($request, $user['id'])) fail('The Official Receipt has not passed the check yet. Approve it first.', 422);
        if (!$upon && (float)$request['total_amount'] > 0) $request['payment_status'] = 'Paid';
        if (format_wants_softcopy($request['release_method'])) {
            $notReady = (int)fetch_one("SELECT COUNT(*) AS c FROM request_items WHERE request_id = ? AND softcopy_status <> 'READY'", [$id])['c'];
            if ($notReady > 0) {
                change_status($id, 'PROCESSING', $user['id'], '');
                json_response(['ok' => true, 'message' => ($upon ? 'Upload' : 'Payment confirmed. Upload') . ' the soft copy (PDF) of every document, then press Ready to Release again.']);
            }
        }
        change_status($id, 'PROCESSING', $user['id'], '');
    }
    if ($new === 'PROCESSING') {
        $notVerified = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1 AND review_status <> 'Verified'", [$id])['c'];
        if ($notVerified > 0) fail('Please verify every uploaded document first.', 422);
    }
    // READY and COMPLETED are decided by the one release gate (backend/release.php), never by the buttons.
    if (in_array($new, ['READY', 'COMPLETED'], true)) {
        $gate = release_gate_problems($request, $new, $user['id']);
        if ($gate) fail(implode(' ', $gate), 422);
    }
    change_status($id, $new, $user['id'], $remarks);
    if ($new === 'READY') {
        audit_log('status_ready', 'request', $id, $id, $user['id'], $request['status'], 'READY', 'Document ready for pickup.');
        send_ready_email($id);          // sent once; a failed email is logged and never changes the request
    } elseif (in_array($new, ['COMPLETED', 'REJECTED'], true)) {
        audit_log('status_' . strtolower($new), 'request', $id, $id, $user['id'], $request['status'], $new, $remarks);
    }
    if ($new === 'REJECTED' && $request['payment_status'] === 'Paid') {
        // The payment was already received: direct both the student and staff to resolve it with the Registrar.
        $payment = fetch_one('SELECT receipt_no FROM payments WHERE request_id = ?', [$id]);
        $note = 'Payment ' . ($payment ? $payment['receipt_no'] : '') . ' was already received for this rejected request. Contact the Registrar to resolve the payment.';
        add_history($id, 'REJECTED', 'REJECTED', $user['id'], $note);
        notify_roles(STAFF_ROLES, $id, 'Payment follow-up needed', "Request {$request['tracking_no']}: " . $note, 'warning');
        add_notification((int)$request['student_id'], $id, 'Payment follow-up', "Request {$request['tracking_no']} was rejected after payment. Please contact the Registrar's Office to discuss the payment.", 'warning');
    }
    json_response(['ok' => true]);
}

function run_ai_action($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    if (!in_array($request['status'], ['FOR_REVIEW', 'NEEDS_CORRECTION'], true)) fail('The documents can only be checked again while the request is waiting for review or correction.', 422);
    $fileIds = null;
    if (!empty($input['file_id'])) {
        $file = fetch_one("SELECT id FROM request_files WHERE id = ? AND request_id = ? AND kind = 'requirement' AND is_current = 1", [(int)$input['file_id'], $request['id']]);
        if (!$file) fail('File not found.', 404);
        $fileIds = [(int)$file['id']];
    }
    $run = run_ai_for_request((int)$request['id'], $fileIds);
    json_response(['ok' => true, 'counts' => $run['counts'], 'summary' => ai_summary_text($run)]);
}

// The registrar uploads (or replaces) the PDF of ONE document of a request. The PDF belongs to that document of that request only.
function attach_release($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    if (!format_wants_softcopy($request['release_method'])) fail('The student did not ask for a soft copy.', 422);
    if (!in_array($request['status'], ['PROCESSING', 'READY'], true)) fail('A soft copy can only be uploaded after the payment was confirmed, while the request is processing.', 422);
    if (!payment_gate_ok($request, $user['id'])) fail('The payment receipt has not passed the security check yet. Review it first on the Payment Receipts page.', 422);
    // the document must be part of THIS request (never trust the number the browser sends)
    $item = fetch_one('SELECT id, softcopy_status FROM request_items WHERE id = ? AND request_id = ?', [(int)($_POST['item_id'] ?? 0), $request['id']]);
    if (!$item) fail('Document not found.', 404);
    try {
        $file = get_uploaded('file');
        if (!$file) fail('Choose a PDF file to upload.', 422);
        $info = validate_file($file['tmp_name'], $file['name'], SOFTCOPY_MAX_MB, 'pdf');          // PDF only: the content is checked, not only the name
        if (check_pdf_basic($file['tmp_name']) !== null) throw new RuntimeException('The PDF is damaged or password protected. Please upload another file.');
        $stored = store_file($file['tmp_name'], 'pdf');                                            // random name: the uploaded name is never used on the disk
    } catch (RuntimeException $e) { fail($e->getMessage(), 422); }

    $pdo = db();
    $replaced = $item['softcopy_status'] !== 'NONE';
    try {
        $pdo->beginTransaction();
        query("UPDATE request_files SET is_current = 0 WHERE item_id = ? AND kind = 'release'", [$item['id']]);   // the old PDF is kept, only not current
        save_file_row((int)$request['id'], (int)$item['id'], null, 'release', $file['name'], $stored, $info);
        // A new PDF starts again: not ready, one download. Open additional-download requests of the old PDF are closed.
        query("UPDATE request_items SET softcopy_status = 'UPLOADED', softcopy_uploaded_by = ?, softcopy_uploaded_at = NOW(), softcopy_ready_at = NULL,
                      download_limit = 1, download_count = 0, last_downloaded_at = NULL WHERE id = ?", [$user['id'], $item['id']]);
        query("UPDATE softcopy_download_requests SET status = 'REJECTED', rejection_reason = 'The document was replaced by the registrar.', decided_by = ?, decided_at = NOW()
               WHERE item_id = ? AND status = 'PENDING'", [$user['id'], $item['id']]);
        query("UPDATE softcopy_download_requests SET status = 'USED' WHERE item_id = ? AND status = 'APPROVED'", [$item['id']]);
        softcopy_log((int)$request['id'], (int)$user['id'], ($replaced ? 'Soft copy replaced' : 'Soft copy uploaded') . ' (' . clean_text(basename(str_replace('\\', '/', $file['name'])), 120) . ').' . ($replaced ? ' Downloads start again.' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        delete_stored_file($stored);
        write_log('attach_release failed: ' . $e->getMessage());
        fail('The PDF could not be saved. Please try again.', 500);
    }
    json_response(['ok' => true]);
}

// The registrar marks the uploaded PDF as ready: from now on the student can download it (once).
function softcopy_ready($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    if (!in_array($request['status'], ['PROCESSING', 'READY'], true)) fail('The request must be processing first.', 422);
    if (!payment_gate_ok($request, $user['id'])) fail('The payment receipt has not passed the security check yet. Review it first on the Payment Receipts page.', 422);
    $item = fetch_one('SELECT i.id, i.softcopy_status, d.name FROM request_items i JOIN documents d ON d.id = i.document_id WHERE i.id = ? AND i.request_id = ?', [(int)($input['item_id'] ?? 0), $request['id']]);
    if (!$item) fail('Document not found.', 404);
    if ($item['softcopy_status'] === 'NONE' || !softcopy_current_file($item['id'])) fail('Upload the PDF first.', 422);
    if ($item['softcopy_status'] === 'READY') fail('This soft copy is already marked ready.', 422);

    query("UPDATE request_items SET softcopy_status = 'READY', softcopy_ready_at = NOW() WHERE id = ?", [$item['id']]);
    softcopy_log((int)$request['id'], (int)$user['id'], "Soft copy of {$item['name']} marked ready.");
    $notReady = (int)fetch_one("SELECT COUNT(*) AS c FROM request_items WHERE request_id = ? AND softcopy_status <> 'READY'", [$request['id']])['c'];
    if ($notReady === 0 && in_array($request['status'], ['READY', 'COMPLETED'], true)) {
        add_notification((int)$request['student_id'], (int)$request['id'], 'Soft copy available', "The soft copy (PDF) of request {$request['tracking_no']} is ready. You can download it from your dashboard.", 'success');
    }
    json_response(['ok' => true, 'all_ready' => $notReady === 0]);
}