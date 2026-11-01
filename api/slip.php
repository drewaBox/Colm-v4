<?php
// The page that opens when a request's QR code is scanned. GET ?t=<token from the QR>
// WITHOUT login (anyone with the QR): only the reference number, documents, copies, dates, status and payment status. No address, birthdate,
// phone number, signature or full student name - a lost or photographed QR code must not leak personal data.
// WITH login as the student who owns the request, or as admin/registrar: the full request slip.
// The token is random (128 bits for new requests); it is only the key to the lookup, never proof of who is looking.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/workflow.php';
require_once __DIR__ . '/../backend/release.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$h = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$token = trim((string)($_GET['t'] ?? ''));
$request = $token === '' ? null : fetch_one('SELECT id, tracking_no, student_id, status, payment_status, payment_term, total_amount, purpose, credential_form_json, expected_release_date, ready_at, created_at FROM requests WHERE qr_code = ?', [$token]);

if (!$request) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Slip not found</title>'
       . '<body style="font-family:Arial,sans-serif;text-align:center;padding:60px 20px;color:#123c2d"><h2>Request slip not found</h2><p>This QR code is not valid.</p></body>';
    exit;
}

$viewer = current_user();
$full = $viewer && (in_array($viewer['role'], STAFF_ROLES, true) || ((int)$viewer['id'] === (int)$request['student_id'] && $viewer['role'] === 'student'));
$student = fetch_one('SELECT student_no, username, full_name FROM users WHERE id = ?', [$request['student_id']]) ?: [];
// "Juan Dela Cruz" -> "J*** D*** C***": enough for the student to recognise, not enough to identify.
$maskedName = implode(' ', array_map(fn($w) => mb_substr($w, 0, 1) . str_repeat('*', 3), preg_split('/\s+/', trim((string)($student['full_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY)));
$deadline = ($request['status'] === 'READY' && $request['payment_status'] === 'Unpaid') ? claim_deadline($request) : null;
$studentNo = ($student['student_no'] ?? '') !== '' ? $student['student_no'] : ($student['username'] ?? '');
$credentialForm = $request['credential_form_json'] ? json_decode($request['credential_form_json'], true) : null;
$credentialForm = is_array($credentialForm) ? $credentialForm : null;
$items = fetch_all('SELECT d.name, i.quantity, i.unit_price
                    FROM request_items i JOIN documents d ON d.id = i.document_id
                    WHERE i.request_id = ? ORDER BY i.id', [$request['id']]);
$money = fn($amount) => 'PHP ' . number_format((float)$amount, 2);
$displayDate = fn($value) => $value ? date('F j, Y', strtotime($value)) : '';
$formDate = fn($value) => $value ? date('m/d/Y', strtotime($value)) : '';
$fullName = $credentialForm
    ? implode(' ', array_filter([$credentialForm['first_name'] ?? '', $credentialForm['middle_name'] ?? '', $credentialForm['last_name'] ?? '']))
    : '';
$statusLabel = STATUS_LABELS[$request['status']] ?? $request['status'];
$statusColors = [
    'CANCELLED' => '#b3261e',
    'SUBMITTED' => '#b26a00', 'AI_VERIFYING' => '#b26a00', 'FOR_REVIEW' => '#b26a00', 'NEEDS_CORRECTION' => '#b3261e',
    'PROCESSING' => '#1a5fb4', 'READY' => '#1b7a3e', 'COMPLETED' => '#1b7a3e', 'REJECTED' => '#b3261e',
];
$statusColor = $statusColors[$request['status']] ?? '#444';
$date = date('F j, Y', strtotime($request['created_at'])) . ' &nbsp;|&nbsp; ' . date('g:i A', strtotime($request['created_at']));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Document Request Slip - <?= $h($request['tracking_no']) ?></title>
<style>
  :root { --green: #123c2d; --green-soft: #e3ece7; --line: #cfdcd5; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 18px 12px 40px; background: #eef3f0; font-family: "Segoe UI", Arial, sans-serif; color: var(--green); }
  .slip { max-width: 520px; margin: 0 auto; background: #fff; border: 4px solid var(--green); border-radius: 18px; padding: 14px; box-shadow: 0 8px 24px rgba(18, 60, 45, .15); }
  .head { display: flex; align-items: center; gap: 14px; background: var(--green); color: #fff; border-radius: 12px 12px 0 0; padding: 14px 16px; }
  .head img { width: 64px; height: 64px; border-radius: 50%; background: #fff; padding: 2px; flex: none; }
  .head .bar { width: 2px; align-self: stretch; background: rgba(255,255,255,.45); }
  .head h1 { margin: 0; font-size: 1.25rem; letter-spacing: .5px; line-height: 1.15; }
  .head p { margin: 2px 0 0; font-size: 1rem; font-weight: 700; letter-spacing: .3px; }
  .body { border: 1px solid var(--line); border-top: 0; border-radius: 0 0 12px 12px; padding: 14px 16px 16px; }
  .info { display: grid; grid-template-columns: 112px 1fr; row-gap: 8px; margin: 4px 0 14px; font-size: .98rem; }
  .info dt { font-weight: 700; }
  .info dd { margin: 0; word-break: break-word; }
  .status { font-weight: 800; text-transform: uppercase; color: <?= $statusColor ?>; }
  table { width: 100%; border-collapse: collapse; font-size: .86rem; }
  th { background: var(--green-soft); text-align: left; padding: 9px 8px; font-weight: 700; }
  th:first-child { border-radius: 8px 0 0 8px; } th:last-child { border-radius: 0 8px 8px 0; }
  td { padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  td:first-child, th:first-child { width: 44%; }
  .num { text-align: right; white-space: nowrap; }
  .qty { text-align: center; }
  .total { display: flex; justify-content: flex-end; gap: 22px; align-items: center; background: var(--green-soft); border-radius: 8px; padding: 11px 10px; margin-top: 12px; font-weight: 800; font-size: 1.05rem; }
  .note { text-align: center; font-size: .78rem; color: #5b7468; margin-top: 12px; }
  .application { max-width: 960px; padding: 10px; border: 1px solid #333; border-radius: 0; box-shadow: none; color: #222; }
  .application-head { display: grid; grid-template-columns: 50px minmax(0, 1fr) 210px; align-items: start; gap: 10px; padding: 4px 4px 14px; }
  .application-head img { width: 48px; height: 48px; object-fit: contain; }
  .school-name { font-size: 14px; line-height: 1.4; }
  .finance-box { padding: 7px; border: 1px solid #333; color: #222; font-size: 12px; }
  .finance-box strong { display: block; margin-bottom: 4px; text-align: center; }
  .application-title { margin: 0; font-size: 16px; text-decoration: underline; }
  .date-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px; font-weight: 700; text-transform: uppercase; }
  .application-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); border-top: 1px solid #333; border-left: 1px solid #333; }
  .form-section { min-width: 0; border-right: 1px solid #333; border-bottom: 1px solid #333; }
  .form-section h2, .claim-slip h2 { margin: 0; padding: 6px; border-bottom: 1px solid #333; background: #dedede; font-size: 14px; text-align: center; text-transform: uppercase; }
  .field-row { display: grid; grid-template-columns: minmax(96px, 34%) minmax(0, 1fr); min-height: 28px; border-bottom: 1px solid #555; }
  .field-row:last-child { border-bottom: 0; }
  .field-label, .field-value { min-width: 0; padding: 5px 7px; overflow-wrap: anywhere; }
  .field-label { border-right: 1px solid #555; text-transform: uppercase; }
  .field-value { min-height: 28px; }
  .subsection { padding: 7px; border-top: 1px solid #555; }
  .subsection:first-of-type { border-top: 0; }
  .subsection-title { display: block; margin-bottom: 5px; font-weight: 700; text-transform: uppercase; }
  .check-list { display: grid; gap: 5px; padding: 7px; }
  .check-item { display: flex; align-items: flex-start; gap: 7px; overflow-wrap: anywhere; }
  .check { flex: 0 0 14px; width: 14px; height: 14px; margin-top: 1px; border: 1px solid #333; text-align: center; font-size: 12px; line-height: 12px; }
  .graduation-status { display: flex; flex-wrap: wrap; justify-content: space-around; gap: 8px; padding: 8px; }
  .office-clearance { display: grid; grid-template-columns: 1fr 1fr; }
  .office-clearance > div { min-height: 88px; padding: 7px; border-right: 1px solid #555; border-bottom: 1px solid #555; }
  .office-clearance > div:nth-child(2n) { border-right: 0; }
  .office-clearance > div:nth-last-child(-n+2) { border-bottom: 0; }
  .notes-box { min-height: 70px; margin-top: 14px; padding: 8px; border: 1px solid #333; white-space: pre-wrap; overflow-wrap: anywhere; }
  .notes-box strong { display: block; margin-bottom: 5px; text-transform: uppercase; }
  .claim-slip { margin-top: 14px; border: 1px solid #333; }
  .claim-slip h2 { padding: 8px; font-size: 18px; }
  .claim-content { display: grid; gap: 12px; padding: 12px; color: #222; }
  .claim-name { overflow-wrap: anywhere; }
  .claim-documents { display: flex; flex-wrap: wrap; gap: 8px 18px; }
  .claim-important { padding: 9px; border-top: 1px solid #555; font-size: 12px; line-height: 1.5; }
  .request-status { margin: 0 0 10px; font-size: 13px; font-weight: 700; }
  @media (max-width: 620px) {
    body { padding: 10px 6px 24px; }
    .application { padding: 7px; }
    .application-head { grid-template-columns: 38px minmax(0, 1fr); gap: 7px; }
    .application-head img { width: 36px; height: 36px; }
    .school-name { font-size: 11px; }
    .finance-box { grid-column: 1 / -1; font-size: 11px; }
    .application-title { font-size: 14px; }
    .date-fields { gap: 6px; font-size: 11px; }
    .application-grid { grid-template-columns: minmax(0, 1fr); }
    .field-row { grid-template-columns: minmax(90px, 34%) minmax(0, 1fr); }
    .form-section h2 { font-size: 12px; }
    .field-label, .field-value, .subsection, .check-list { padding: 5px; font-size: 12px; }
    .office-clearance > div { min-height: 64px; }
    .claim-slip h2 { font-size: 16px; }
  }
  @media print {
    body { padding: 0; background: #fff; }
    .slip { max-width: none; border: 0; box-shadow: none; }
    .application { max-width: none; border: 0; box-shadow: none; }
    .application-grid { grid-template-columns: 1.2fr 1fr; }
  }
  @media (max-width: 420px) {
    table { table-layout: fixed; font-size: .75rem; }
    th, td { padding: 7px 4px; }
    th:first-child, td:first-child { width: 40%; }
    th.qty, td.qty { width: 12%; }
    th.num, td.num { width: 24%; white-space: normal; }
  }
</style>
</head>
<body>
<?php if ($full && $credentialForm): ?>
<main class="slip application">
  <header class="application-head">
    <img src="../frontend/assets/images/colm_registrar_logo.png" alt="COLM Office of the Registrar">
    <div class="school-name"><strong>COLLEGE OF OUR LADY OF MERCY OF PULILAN FOUNDATION INC.</strong><br>OFFICE OF THE REGISTRAR</div>
    <div class="finance-box"><strong>FINANCE USE ONLY</strong>O.R. No. __________<br>Amount Paid: __________</div>
  </header>
  <h1 class="application-title">REQUEST FOR CREDENTIALS / DOCUMENTS</h1>
  <div class="date-fields"><span>Date Received: <?= $h($displayDate($request['created_at'])) ?></span><span>Expected Release: <?= $h($request['expected_release_date'] ? $displayDate($request['expected_release_date']) : 'To be announced') ?></span></div>
  <p class="request-status">Tracking No: <?= $h($request['tracking_no']) ?> &nbsp;|&nbsp; Status: <span class="status"><?= $h($statusLabel) ?></span></p>
  <div class="application-grid">
    <section class="form-section">
      <h2>Personal Information</h2>
      <?php foreach ([
        'Last Name' => $credentialForm['last_name'] ?? '',
        'First Name' => $credentialForm['first_name'] ?? '',
        'Middle Name' => $credentialForm['middle_name'] ?? '',
        'Address' => $credentialForm['address'] ?? '',
        'Birthdate' => $formDate($credentialForm['birthdate'] ?? ''),
        'Gender' => $credentialForm['gender'] ?? '',
      ] as $label => $value): ?>
        <div class="field-row"><div class="field-label"><?= $h($label) ?></div><div class="field-value"><?= $h($value) ?></div></div>
      <?php endforeach; ?>
      <div class="subsection">
        <span class="subsection-title">Signature</span>
        <img src="signature.php?request_id=<?= (int)$request['id'] ?>" alt="Drawn signature" style="max-width:100%;max-height:90px;display:block" onerror="this.style.display='none'">
        <?= $h($credentialForm['signature'] ?? '') ?>
      </div>
      <div class="subsection">
        <span class="subsection-title">Previous Schools / Year Graduated</span>
        <div class="field-row"><div class="field-label">Elementary</div><div class="field-value"><?= $h($credentialForm['elementary_school'] ?? '') ?></div></div>
        <div class="field-row"><div class="field-label">Junior High</div><div class="field-value"><?= $h($credentialForm['junior_high_school'] ?? '') ?></div></div>
        <div class="field-row"><div class="field-label">Senior High</div><div class="field-value"><?= $h($credentialForm['senior_high_school'] ?? '') ?></div></div>
      </div>
      <div class="subsection">
        <span class="subsection-title">Documents Requested</span>
        <div class="check-list">
          <?php foreach (($credentialForm['requested_documents'] ?? []) as $document): ?>
            <div class="check-item"><span class="check">✓</span><?= $h($document) ?></div>
          <?php endforeach; ?>
        </div>
        <span class="subsection-title">Purpose of Request</span>
        <div class="check-list">
          <?php foreach (['Employment', 'Evaluation', 'Board Examination', 'Others'] as $purpose): ?>
            <?php $selectedPurpose = ($credentialForm['purpose'] ?? '') === $purpose; ?>
            <div class="check-item"><span class="check"><?= $selectedPurpose ? '✓' : '' ?></span><?= $h($purpose === 'Others' && $selectedPurpose ? 'Others: ' . ($credentialForm['other_purpose'] ?? '') : $purpose) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <section class="form-section">
      <h2>Academic Information</h2>
      <?php foreach ([
        'Student No.' => $credentialForm['student_no'] ?? $studentNo,
        'Grade Level' => $credentialForm['grade_level'] ?? '',
        'Strand' => $credentialForm['strand'] ?? '',
        'Course' => $credentialForm['course'] ?? '',
      ] as $label => $value): ?>
        <div class="field-row"><div class="field-label"><?= $h($label) ?></div><div class="field-value"><?= $h($value) ?></div></div>
      <?php endforeach; ?>
      <h2>Graduation</h2>
      <div class="graduation-status">
        <span class="check-item"><span class="check"><?= ($credentialForm['graduated'] ?? '') === 'yes' ? '✓' : '' ?></span>Yes, I graduated<?= !empty($credentialForm['graduation_date']) ? ' — ' . $h($formDate($credentialForm['graduation_date'])) : '' ?></span>
        <span class="check-item"><span class="check"><?= ($credentialForm['graduated'] ?? '') === 'no' ? '✓' : '' ?></span>No, last attended <?= $h($credentialForm['last_attended_school_year'] ?? '') ?></span>
      </div>
      <h2>Contact Information</h2>
      <div class="field-row"><div class="field-label">Telephone No.</div><div class="field-value"><?= $h($credentialForm['telephone'] ?? '') ?></div></div>
      <div class="field-row"><div class="field-label">Mobile No.</div><div class="field-value"><?= $h($credentialForm['mobile'] ?? '') ?></div></div>
      <h2>Clearance</h2>
      <div class="office-clearance"><div><strong>FINANCE</strong></div><div><strong>REMARKS</strong></div><div></div><div></div></div>
    </section>
  </div>
  <div class="notes-box"><strong>Notes if any:</strong><?= $h($credentialForm['notes'] ?? '') ?></div>
  <section class="claim-slip">
    <h2>Claim Slip</h2>
    <div class="claim-content">
      <div class="claim-name">Name: <strong><?= $h($fullName) ?></strong></div>
      <div>Request for:</div>
      <div class="claim-documents">
        <?php foreach (($credentialForm['requested_documents'] ?? []) as $document): ?>
          <span class="check-item"><span class="check">✓</span><?= $h($document) ?></span>
        <?php endforeach; ?>
      </div>
      <strong>CLAIMING TIME: 9:00 A.M. – 4:00 P.M. ONLY</strong>
    </div>
    <div class="claim-important"><strong>IMPORTANT:</strong><br>Per your claim slip date, come and bring a valid ID. If you cannot personally come, your representative can bring your valid ID and letter of authorization duly notarized.</div>
  </section>
  <p class="note">Status is updated live. Present this slip at the Registrar's Office.</p>
</main>
<?php else: ?>
<main class="slip">
  <header class="head">
    <img src="../frontend/assets/images/colm_registrar_logo.png" alt="COLM Office of the Registrar">
    <div class="bar" aria-hidden="true"></div>
    <div>
      <h1>COLM REGISTRAR</h1>
      <p>DOCUMENT REQUEST</p>
    </div>
  </header>
  <section class="body">
    <dl class="info">
      <dt>Tracking No:</dt><dd><?= $h($request['tracking_no']) ?></dd>
      <?php if ($full): ?><dt>Student No:</dt><dd><?= $h($studentNo) ?></dd><?php else: ?><dt>Student:</dt><dd><?= $h($maskedName) ?></dd><?php endif; ?>
      <dt>Status:</dt><dd class="status"><?= $h($statusLabel) ?></dd>
      <dt>Payment:</dt><dd><?= $h($request['payment_status']) ?><?= $request['payment_term'] === 'UPON_RELEASE' && $request['payment_status'] === 'Unpaid' ? ' (pay when you claim)' : '' ?></dd>
      <dt>Expected release:</dt><dd><?= $h($request['expected_release_date'] ? $displayDate($request['expected_release_date']) : 'To be announced') ?></dd>
      <?php if ($deadline): ?><dt>Claim by:</dt><dd><?= $h($deadline->format('F j, Y')) ?></dd><?php endif; ?>
      <dt>Date:</dt><dd><?= $date ?></dd>
    </dl>
    <table>
      <thead><tr><th>Documents</th><th class="qty">Copies</th><th class="num">Unit Price</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><?= $h($item['name']) ?></td>
          <td class="qty"><?= (int)$item['quantity'] ?></td>
          <td class="num"><?= $h($money($item['unit_price'])) ?></td>
          <td class="num"><?= $h($money($item['unit_price'] * $item['quantity'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="total"><span>Total:</span><span><?= $h($money($request['total_amount'])) ?></span></div>
    <p class="note">Status is updated live. Present this slip at the Registrar's Office.<?= $full ? '' : ' Sign in to the portal to see the full details of your request.' ?></p>
  </section>
</main>
<?php endif; ?>
</body>
</html>