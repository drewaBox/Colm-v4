<?php
// Student receipt uploads and staff receipt reviews.
//   GET  ?action=receipts                 receipts waiting for staff review
//   GET  ?action=receipt_file&id=REQUEST_ID  securely preview/download an uploaded receipt
//   POST ?action=upload_receipt           student uploads a payment receipt
//   POST ?action=review_receipt           admin/registrar verifies or rejects a receipt
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/workflow.php';
require_once __DIR__ . '/../backend/files.php';
require_once __DIR__ . '/../backend/receipt_security.php';
register_error_handlers();
$user = require_login(['student', 'admin', 'registrar']);
$action = $_GET['action'] ?? '';
ensure_receipt_security_columns();

switch ($action) {
    case 'upload_receipt': require_method('POST'); require_login(['student']); require_csrf(); upload_payment_receipt($user); break;
    case 'receipt_file': require_method('GET'); show_payment_receipt_file($user); break;
    case 'receipts': require_method('GET'); require_login(STAFF_ROLES); list_payment_receipts(); break;
    case 'review_receipt': require_method('POST'); require_login(STAFF_ROLES); require_csrf(); review_payment_receipt($user); break;
    default: fail('Unknown action.', 404);
}

function upload_payment_receipt($user) {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('The receipt is too large for the server. Upload a smaller file or ask the administrator to adjust the upload limit.', 413);
    }
    $requestId = (int)($_POST['request_id'] ?? 0);
    $request = fetch_one("SELECT id, tracking_no, student_id, status, payment_status, payment_term, total_amount FROM requests WHERE id = ? AND student_id = ?",
        [$requestId, $user['id']]);
    if (!$request) fail('Request not found.', 404);
    if ($request['payment_term'] === 'UPON_RELEASE') fail('You do not need to upload a receipt. You pay at the Registrar\'s Office when you claim your document.', 422);
    if ($request['payment_status'] === 'Paid') fail('This request is already paid.', 422);
    if (in_array($request['status'], ['REJECTED', 'COMPLETED', 'CANCELLED'], true) || (float)$request['total_amount'] <= 0) {
        fail('A payment receipt cannot be uploaded for this request.', 422);
    }
    try {
        $file = get_uploaded('receipt');
    } catch (RuntimeException $error) {
        fail($error->getMessage(), 422);
    }
    if (!$file) fail('Choose a payment receipt file first.', 422);
    try {
        $info = validate_file($file['tmp_name'], $file['name'], 5, 'pdf,jpg,jpeg,png');
    } catch (RuntimeException $error) {
        fail($error->getMessage(), 422);
    }
    $existing = fetch_one('SELECT id, stored_name, status FROM payment_receipts WHERE request_id = ?', [$requestId]);
    if ($existing && $existing['status'] === 'Pending') fail('Your receipt is already waiting for review.', 422);

    // ---- Security checks (see backend/receipt_security.php) ----
    $orNo = strtoupper(trim((string)($_POST['official_receipt_no'] ?? '')));
    if (!preg_match('/^COLM-\d{4}-\d{5}$/', $orNo)) fail('The Official Receipt No. must be in this format: COLM-0000-00000.', 422);
    // The amount and the date are fixed by the system (the request's total and the day the request was made).
    $amountPaid = round((float)$request['total_amount'], 2);
    $datePaid = fetch_one('SELECT DATE(created_at) AS d FROM requests WHERE id = ?', [$requestId])['d'];

    $hash = hash_file('sha256', $file['tmp_name']);
    $fileUsed = (bool)fetch_one('SELECT id FROM payment_receipts WHERE file_hash = ? AND request_id <> ?', [$hash, $requestId]);
    $orUsed = (bool)fetch_one("SELECT id FROM payment_receipts WHERE official_receipt_no = ? AND request_id <> ? AND status IN ('Pending','Verified')", [$orNo, $requestId]);
    if ($fileUsed && $orUsed) {
        fail("This receipt file and the Official Receipt No. ($orNo) were already used for another request. Upload the receipt of your own payment.", 422);
    }
    if ($fileUsed) fail('This receipt file was already used for another request. Upload the receipt of your own payment.', 422);
    if ($orUsed) fail("This Official Receipt No. ($orNo) was already used for another request.", 422);
    $flags = array_merge(receipt_metadata_flags($file['tmp_name']), receipt_ocr_flags($file['tmp_name'], $info['mime'], $orNo, $amountPaid));
    $flagsJson = $flags ? json_encode(array_values($flags), JSON_UNESCAPED_UNICODE) : null;

    $storedName = store_file($file['tmp_name'], $info['ext']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($existing) {
            query("UPDATE payment_receipts SET uploaded_by = ?, original_name = ?, stored_name = ?, mime_type = ?, size_bytes = ?,
                   official_receipt_no = ?, amount_paid = ?, date_paid = ?, file_hash = ?, security_flags = ?,
                   status = 'Pending', remarks = NULL, reviewed_by = NULL, reviewed_at = NULL, uploaded_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$user['id'], clean_text($file['name'], 255), $storedName, $info['mime'], $info['size'], $orNo, $amountPaid, $datePaid, $hash, $flagsJson, $existing['id']]);
        } else {
            query("INSERT INTO payment_receipts (request_id, uploaded_by, original_name, stored_name, mime_type, size_bytes,
                                                 official_receipt_no, amount_paid, date_paid, file_hash, security_flags)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$requestId, $user['id'], clean_text($file['name'], 255), $storedName, $info['mime'], $info['size'], $orNo, $amountPaid, $datePaid, $hash, $flagsJson]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        delete_stored_file($storedName);
        throw $error;
    }
    if ($existing) delete_stored_file($existing['stored_name']);
    notify_roles(STAFF_ROLES, $requestId, 'Payment receipt uploaded', "A receipt for request {$request['tracking_no']} is ready for review." . ($flags ? ' Warning: the security check found something to look at.' : ''), $flags ? 'warning' : 'info');
    // "check" tells the page how the automatic (AI) check went: 'passed' = nothing suspicious, 'review' = the Registrar must look at it.
    $message = $flags
        ? 'Receipt uploaded. The automatic check found something, so the Registrar will review it. Keep and bring the hard copy when collecting your document.'
        : 'Receipt passed the automatic check. Keep and bring the hard copy when collecting your document.';
    json_response(['ok' => true, 'check' => $flags ? 'review' : 'passed', 'message' => $message], 201);
}

function list_payment_receipts() {
    $rows = fetch_all("SELECT pr.request_id, pr.original_name, pr.status, pr.remarks, pr.uploaded_at, pr.official_receipt_no, pr.amount_paid, pr.date_paid, pr.security_flags, r.tracking_no, r.total_amount,
                              r.payment_method, u.full_name, u.student_no, s.course
                       FROM payment_receipts pr JOIN requests r ON r.id = pr.request_id
                       JOIN users u ON u.id = r.student_id LEFT JOIN students s ON s.user_id = u.id
                       WHERE pr.status = 'Pending' ORDER BY pr.uploaded_at ASC LIMIT 200");
    foreach ($rows as &$row) {
        $row['request_id'] = (int)$row['request_id'];
        $row['total_amount'] = (float)$row['total_amount'];
        $row['security_flags'] = $row['security_flags'] ? (json_decode($row['security_flags'], true) ?: []) : [];
    }
    json_response(['ok' => true, 'receipts' => $rows]);
}

function show_payment_receipt_file($user) {
    $requestId = (int)($_GET['id'] ?? 0);
    $receipt = fetch_one("SELECT pr.*, r.student_id FROM payment_receipts pr JOIN requests r ON r.id = pr.request_id WHERE pr.request_id = ?",
        [$requestId]);
    if (!$receipt || ($user['role'] === 'student' && (int)$receipt['student_id'] !== (int)$user['id'])) fail('Receipt not found.', 404);
    send_stored_file($receipt, !empty($_GET['inline']));
}

function review_payment_receipt($user) {
    $input = read_json_body();
    $requestId = (int)($input['request_id'] ?? 0);
    $decision = $input['decision'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($decision, ['Verified', 'Rejected'], true)) fail('Choose Verify or Reject.', 422);
    if ($decision === 'Rejected' && $remarks === '') fail('Explain why the receipt was rejected.', 422);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $request = fetch_one("SELECT id, tracking_no, student_id, status, payment_status, total_amount, payment_method
                              FROM requests WHERE id = ? FOR UPDATE", [$requestId]);
        $receipt = fetch_one("SELECT id, status FROM payment_receipts WHERE request_id = ? FOR UPDATE", [$requestId]);
        if (!$request || !$receipt) fail('Payment receipt not found.', 404);
        if ($receipt['status'] !== 'Pending' || $request['payment_status'] === 'Paid') fail('This receipt has already been reviewed.', 422);
        if (in_array($request['status'], ['REJECTED', 'COMPLETED', 'CANCELLED'], true)) fail('This request can no longer be paid.', 422);
        if ($decision === 'Verified') {
            $receiptNo = make_receipt_number();
            query('INSERT INTO payments (request_id, receipt_no, amount, method, received_by) VALUES (?, ?, ?, ?, ?)',
                [$requestId, $receiptNo, $request['total_amount'], $request['payment_method'], $user['id']]);
            query("UPDATE requests SET payment_status = 'Paid' WHERE id = ?", [$requestId]);
            $remarks = "Payment receipt verified ($receiptNo).";
        }
        query('UPDATE payment_receipts SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$decision, $remarks !== '' ? $remarks : null, $user['id'], $receipt['id']]);
        add_history($requestId, $request['status'], $request['status'], $user['id'], $remarks);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $title = $decision === 'Verified' ? 'Payment confirmed' : 'Payment receipt needs correction';
    add_notification((int)$request['student_id'], $requestId, $title,
        $decision === 'Verified' ? "Payment for request {$request['tracking_no']} was verified. Keep your hard-copy receipt for document release."
            : "The receipt for request {$request['tracking_no']} was rejected: $remarks", $decision === 'Verified' ? 'success' : 'warning');
    json_response(['ok' => true, 'message' => $decision === 'Verified' ? 'Payment confirmed.' : 'Receipt rejected.']);
}

function make_receipt_number() {
    for ($i = 0; $i < 10; $i++) {
        $number = 'OR-' . date('Ymd') . '-' . random_int(1000, 9999);
        if (!fetch_one('SELECT id FROM payments WHERE receipt_no = ?', [$number])) return $number;
    }
    throw new RuntimeException('Could not create a receipt number.');
}