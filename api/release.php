<?php
// Release of documents: expected release date, payment when claiming, cancellation and activity log.
//
//   POST ?action=set_release_date   admin/registrar   {id, date: "YYYY-MM-DD"}
//   POST ?action=confirm_payment    admin/registrar   {id, official_receipt_no?}   the student paid when claiming
//   POST ?action=reinstate          admin only        {id, reason}                 documented correction of a cancelled request
//   POST ?action=run_cancellation   admin only        {dry_run?}                   same job as tools/cancel_overdue.php
//   POST ?action=resend_ready_email admin/registrar   {id}
//   GET  ?action=activity&id=ID     admin/registrar   audit trail + email history of one request
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/release.php';
register_error_handlers();

$user = require_login(STAFF_ROLES);
$action = $_GET['action'] ?? '';

// Runs a release function and turns its RuntimeException into a clear error message for the browser.
function release_run($function) {
    try {
        return $function();
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 422);
    }
}

if ($action === 'activity') {
    require_method('GET');
    $id = (int)($_GET['id'] ?? 0);
    if (!fetch_one('SELECT id FROM requests WHERE id = ?', [$id])) fail('Request not found.', 404);
    $audit = fetch_all('SELECT a.action, a.old_value, a.new_value, a.details, a.created_at, u.full_name AS by_name
                        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.request_id = ? ORDER BY a.id DESC LIMIT 100', [$id]);
    foreach ($audit as &$row) $row['by_name'] = $row['by_name'] ?? 'System';
    $emails = fetch_all('SELECT kind, status, attempts, error_message, created_at, sent_at FROM email_log WHERE request_id = ? ORDER BY id DESC', [$id]);
    json_response(['ok' => true, 'audit' => $audit, 'emails' => $emails]);
}

require_method('POST');
require_csrf();
$input = read_json_body();
$id = (int)($input['id'] ?? 0);

switch ($action) {
    case 'set_release_date':
        $result = release_run(fn() => set_expected_release_date($id, $input['date'] ?? '', $user));
        json_response(['ok' => true, 'changed' => $result['changed'], 'message' => $result['changed'] ? 'Expected release date saved.' : 'That is already the expected release date.']);

    case 'confirm_payment':
        $result = release_run(fn() => confirm_payment_at_release($id, $user, $input['official_receipt_no'] ?? ''));
        json_response(['ok' => true, 'already' => $result['already'], 'message' => $result['already'] ? 'This payment was already recorded.' : 'Payment recorded.']);

    case 'resend_ready_email':
        $status = send_ready_email($id);
        $messages = ['sent' => 'The email was sent.', 'failed' => 'The email could not be sent. See the email history.', 'duplicate' => 'The email was already sent for this release.',
                     'skipped' => 'The request is not ready for pickup.', 'error' => 'The email could not be sent. See the error log.'];
        json_response(['ok' => in_array($status, ['sent', 'duplicate'], true), 'result' => $status, 'message' => $messages[$status] ?? 'Done.']);
}

// Everything below is for the Admin only (the Registrar cannot override a cancellation).
require_login(['admin']);
switch ($action) {
    case 'reinstate':
        release_run(fn() => reinstate_request($id, $input['reason'] ?? '', $user));
        json_response(['ok' => true, 'message' => 'The request is ready again. The student has 14 days to claim it.']);

    case 'run_cancellation':
        $result = cancel_overdue_requests(!empty($input['dry_run']));
        json_response(['ok' => true] + $result);

    default:
        fail('Unknown action.', 404);
}
