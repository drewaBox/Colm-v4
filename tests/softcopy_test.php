<?php
// Tests the soft copy (PDF) download rules without a database.   Run:  php tests/softcopy_test.php
require_once __DIR__ . '/../backend/softcopy.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++; echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}
$item = fn($status = 'READY', $count = 0, $limit = 1) => ['softcopy_status' => $status, 'download_count' => $count, 'download_limit' => $limit];
$request = fn($format = 'Softcopy', $status = 'READY', $paid = 'Paid') => ['release_method' => $format, 'status' => $status, 'payment_status' => $paid, 'total_amount' => 50];

echo "Which formats want a soft copy\n";
expect('Hard copy only: no soft copy', format_wants_softcopy('Hardcopy'), false);
expect('Soft copy', format_wants_softcopy('Softcopy'), true);
expect('Both', format_wants_softcopy('Both'), true);

echo "Before the student can download\n";
$s = softcopy_state($item(), $request('Hardcopy'));
expect('Hard copy request: nothing to download', [$s['wanted'], $s['can_download'], $s['label']], [false, false, '-']);
$s = softcopy_state($item('NONE'), $request('Softcopy', 'PROCESSING'));
expect('Soft copy requested, not uploaded: Waiting for Registrar', [$s['code'], $s['label'], $s['can_download']], ['WAITING', 'Waiting for Registrar', false]);
$s = softcopy_state($item('UPLOADED'), $request());
expect('Uploaded but not marked ready: cannot download', $s['can_download'], false);
$s = softcopy_state($item('READY'), $request('Softcopy', 'PROCESSING'));
expect('Ready but the request is still processing: cannot download', $s['can_download'], false);
$s = softcopy_state($item('READY'), $request('Softcopy', 'READY', 'Unpaid'));
expect('Not paid: cannot download (payment is never bypassed)', $s['can_download'], false);

echo "The first (one-time) download\n";
$s = softcopy_state($item(), $request());
expect('Paid, ready, released: Soft Copy Available, download allowed', [$s['code'], $s['can_download'], $s['can_request_more']], ['AVAILABLE', true, false]);
$s = softcopy_state($item(), $request('Both', 'COMPLETED'));
expect('Format "Both" and a completed request can download', $s['can_download'], true);

echo "After the download is used\n";
$s = softcopy_state($item('READY', 1, 1), $request());
expect('Used: no download, but the student may ask for another', [$s['code'], $s['label'], $s['can_download'], $s['can_request_more']], ['USED', 'Download Used', false, true]);
$s = softcopy_state($item('READY', 1, 1), $request(), ['status' => 'PENDING', 'rejection_reason' => null], 1);
expect('A request is waiting: cannot download and cannot ask again', [$s['code'], $s['can_download'], $s['can_request_more']], ['REQUEST_PENDING', false, false]);
$s = softcopy_state($item('READY', 1, 1), $request(), ['status' => 'REJECTED', 'rejection_reason' => 'Not allowed'], 1);
expect('Rejected: no download, the reason is shown, the student may ask again', [$s['code'], $s['rejection_reason'], $s['can_download'], $s['can_request_more']], ['REJECTED', 'Not allowed', false, true]);
$s = softcopy_state($item('READY', 1, 2), $request(), ['status' => 'APPROVED', 'rejection_reason' => null], 1);
expect('Approved (limit 2, used 1): download allowed again', [$s['code'], $s['can_download'], $s['downloads_used'], $s['downloads_allowed']], ['APPROVED', true, 1, 2]);
$s = softcopy_state($item('READY', 2, 2), $request(), ['status' => 'USED', 'rejection_reason' => null], 1);
expect('The approved download was used too: Download Used again', [$s['code'], $s['can_download']], ['USED', false]);
$s = softcopy_state($item('READY', 1, 1), $request(), ['status' => 'REJECTED', 'rejection_reason' => null], SOFTCOPY_MAX_EXTRA_REQUESTS);
expect('After too many requests the student cannot ask again', $s['can_request_more'], false);

echo "Additional download reasons\n";
expect('Only the three allowed reasons are available', SOFTCOPY_REASONS, [
    'PDF file was corrupted', 'System error - unable to download', 'Wrong name or data',
]);
expect('Old additional-download reasons are not accepted', in_array('Other', SOFTCOPY_REASONS, true), false);
expect('Download name never uses the uploaded name', softcopy_download_name(['tracking_no' => 'COLM-2026-0001', 'document_name' => 'Transcript of Records (TOR)']), 'COLM-2026-0001-Transcript-of-Records-TOR.pdf');

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
