<?php
// AI verification of a WHOLE request (the uploaded files are already checked one by one by ai.php).
//
//   1. Is the student's profile complete?            (name, student number, course, year level ...)
//   2. Is the form complete and does it match the account?
//   3. Is every selected document valid and allowed for this student and release format?
//   4. Is the quantity correct (1-5 copies, max 15 documents)?
//   5. Were the requirements followed (required files present and not declined)?
//   6. Is the student eligible for this document (for example: Certificate of Enrollment = currently enrolled)?
//
// Then the request is routed:
//   REGISTRAR  Transcript of Records (and the other academic-record documents): the Registrar verifies the academic records and approves.
//   AUTO       Certificate of Enrollment, Good Moral, Graduation and other certifications: AI + the data already in the account.
//              If the AI finds something unclear the request is flagged for manual review by the Registrar.
//
// The AI never rejects a request by itself. A missing or wrong item sends the request back to the student (NEEDS_CORRECTION);
// anything unclear is flagged for the Registrar, who makes the final decision.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/students.php';
require_once __DIR__ . '/names.php';
require_once __DIR__ . '/workflow.php';

const VERIFICATION_ROUTE_LABELS = [
    'REGISTRAR' => 'Registrar - academic records verification',
    'AUTO'      => 'AI-assisted verification (account records)',
];

function verification_doc_key($name) {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$name));
}

// ---------- Routing ----------

// Documents that always go to the Registrar. Edit here to change the routing.
function registrar_route_document($name, $category) {
    if (strpos(verification_doc_key($name), 'transcriptofrecords') === 0) return true;     // TOR
    return credential_request_document($name)                                              // TOR, Honorable Dismissal, Form 137
        || strcasecmp(trim((string)$category), 'Academic Records') === 0;                  // Certified True Copy, Diploma ...
}

// $items = list of ['name' => ..., 'category' => ...]
function verification_route_for_items($items) {
    foreach ($items as $item) {
        if (registrar_route_document($item['name'], $item['category'])) return 'REGISTRAR';
    }
    return 'AUTO';
}

// ---------- Eligibility ----------

// Key = start of the document name without spaces/punctuation. 'status' = values of students.status that may request it.
// Documents that are not listed have no special eligibility rule. Edit this list to match the school's policy.
function eligibility_rules() {
    return [
        'certificateofenrollment'   => ['status' => ['currently_enrolled'], 'text' => 'Certificate of Enrollment is for students who are currently enrolled'],
        'certificateofregistration' => ['status' => ['currently_enrolled'], 'text' => 'Certificate of Registration is for students who are currently enrolled'],
        'certificateofgraduation'   => ['status' => ['alumni'],             'text' => 'Certificate of Graduation is for graduates'],
        'diploma'                   => ['status' => ['alumni'],             'text' => 'Diploma is for graduates'],
    ];
}

function student_status_label($status) {
    return [
        'currently_enrolled' => 'currently enrolled',
        'alumni'             => 'alumni (graduate)',
        'undergrad'          => 'undergraduate (not enrolled)',
    ][(string)$status] ?? 'not set';
}

// Returns a sentence when the student does not meet the rule of this document, otherwise null.
function eligibility_problem($documentName, $studentStatus) {
    $key = verification_doc_key($documentName);
    foreach (eligibility_rules() as $prefix => $rule) {
        if (strpos($key, $prefix) !== 0) continue;
        if (in_array((string)$studentStatus, $rule['status'], true)) return null;
        return $rule['text'] . ', but your account is marked as ' . student_status_label($studentStatus) . '.';
    }
    return null;
}

// ---------- Result helpers ----------

function vcheck($key, $label, $status, $detail) {
    return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];     // status: pass | warn | fail
}

// fail -> student must correct something; warn -> the Registrar looks at it; otherwise pass.
function verification_verdict($checks) {
    foreach ($checks as $check) if ($check['status'] === 'fail') return 'NEEDS_CORRECTION';
    foreach ($checks as $check) if ($check['status'] === 'warn') return 'REVIEW';
    return 'PASS';
}

function verification_summary($verdict, $route, $checks) {
    $details = function ($status) use ($checks) {
        return implode(' ', array_map(fn($c) => $c['detail'], array_filter($checks, fn($c) => $c['status'] === $status)));
    };
    if ($verdict === 'NEEDS_CORRECTION') {
        $text = 'AI verification found a problem: ' . $details('fail');
    } elseif ($verdict === 'REVIEW') {
        $text = 'AI verification needs manual review by the Registrar: ' . $details('warn');
    } elseif ($route === 'REGISTRAR') {
        $text = 'AI verification passed. Forwarded to the Registrar for academic records verification and approval.';
    } else {
        $text = 'AI verification passed using your account information. No manual document review is needed.';
    }
    return mb_substr(trim($text), 0, 500);
}

function normalize_form_text($value) {
    return mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$value)));
}

// ---------- The verification itself ----------

function run_request_verification($requestId) {
    $request = fetch_one('SELECT * FROM requests WHERE id = ?', [$requestId]);
    if (!$request) throw new RuntimeException('Request not found.');
    $student = fetch_one('SELECT u.full_name, u.student_no, u.username, s.school_level, s.course, s.year_level, s.status AS student_status
                          FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ?', [$request['student_id']]);
    if (!$student) throw new RuntimeException('Student not found.');
    $items = fetch_all('SELECT i.id, i.document_id, i.quantity, d.name, d.category, d.release_methods, d.is_active
                        FROM request_items i JOIN documents d ON d.id = i.document_id WHERE i.request_id = ? ORDER BY i.id', [$requestId]);
    $credentialForm = $request['credential_form_json'] ? json_decode($request['credential_form_json'], true) : null;
    $verificationForm = $request['student_verification_form_json'] ? json_decode($request['student_verification_form_json'], true) : null;
    $checks = [];

    // 1. Basic information saved in the account
    $missing = [];
    foreach (['full_name' => 'name', 'student_no' => 'student number', 'course' => 'course', 'school_level' => 'school level'] as $field => $label) {
        if (trim((string)($student[$field] ?? '')) === '') $missing[] = $label;
    }
    if (empty($student['year_level'])) $missing[] = 'year level';
    $checks[] = $missing
        ? vcheck('profile', 'Account information', 'fail', 'Your account is missing: ' . implode(', ', $missing) . '. Ask the Registrar to complete your profile.')
        : vcheck('profile', 'Account information', 'pass', 'Name, student number, course and year level are on file.');

    // 2. Forms
    $formProblems = [];
    $formWarnings = [];
    foreach ($items as $item) {
        if (credential_form_required_document($item['name'])) {
            $f = is_array($credentialForm) ? $credentialForm : [];
            if (!$f) { $formProblems[] = "The credential request form for {$item['name']} is missing."; continue; }
            $digits = strlen(preg_replace('/\D/', '', (string)($f['mobile'] ?? '')));
            if (trim((string)($f['first_name'] ?? '')) === '' || trim((string)($f['last_name'] ?? '')) === ''
                || mb_strlen(trim((string)($f['address'] ?? ''))) < 5 || trim((string)($f['birthdate'] ?? '')) === ''
                || trim((string)($f['gender'] ?? '')) === '' || $digits < 7 || !in_array($f['graduated'] ?? '', ['yes', 'no'], true)
                || trim((string)($f['purpose'] ?? '')) === '' || mb_strlen(trim((string)($f['signature'] ?? ''))) < 3) {
                $formProblems[] = "The credential request form for {$item['name']} is incomplete.";
                continue;
            }
            $nameOnForm = trim($f['first_name'] . ' ' . $f['last_name']);
            if (!names_match($student['full_name'], $nameOnForm)['match']) {
                $formWarnings[] = "The name on the credential form ($nameOnForm) does not clearly match the account name.";
            }
        } elseif ($verificationForm && legacy_student_verification_document($item['name'], $item['category'])) {
            $f = is_array($verificationForm) ? $verificationForm : [];
            if (!$f) { $formProblems[] = "The Student Verification form for {$item['name']} is missing."; continue; }
            $profileId = trim((string)($student['student_no'] ?: $student['username']));
            if (trim((string)($f['student_id'] ?? '')) !== $profileId
                || normalize_form_text($f['student_name'] ?? '') !== normalize_form_text($student['full_name'])
                || normalize_form_text($f['course_program'] ?? '') !== normalize_form_text($student['course'])) {
                $formProblems[] = "The Student Verification form for {$item['name']} does not match your account (student ID, name or course).";
            }
        }
    }
    if ($formProblems) $checks[] = vcheck('form', 'Request form', 'fail', implode(' ', array_unique($formProblems)));
    elseif ($formWarnings) $checks[] = vcheck('form', 'Request form', 'warn', implode(' ', array_unique($formWarnings)));
    else $checks[] = vcheck('form', 'Request form', 'pass', 'The form is complete and matches your account.');

    // 3. Documents are valid and may be released in the chosen format
    $docProblems = [];
    $needed = $request['release_method'] === 'Both' ? ['Hardcopy', 'Softcopy'] : [$request['release_method']];
    foreach ($items as $item) {
        if (!(int)$item['is_active']) $docProblems[] = "{$item['name']} is no longer available.";
        if (array_diff($needed, explode(',', $item['release_methods']))) $docProblems[] = "{$item['name']} is not available as {$request['release_method']}.";
    }
    $checks[] = $docProblems
        ? vcheck('documents', 'Selected documents', 'fail', implode(' ', $docProblems))
        : vcheck('documents', 'Selected documents', 'pass', count($items) . ' document(s) selected, all valid for this student and format.');

    // 4. Quantity
    $quantityProblems = [];
    if (!$items || count($items) > 15) $quantityProblems[] = 'A request needs 1 to 15 documents.';
    foreach ($items as $item) {
        if ((int)$item['quantity'] < 1 || (int)$item['quantity'] > 5) $quantityProblems[] = "{$item['name']}: you can request 1 to 5 copies.";
    }
    $checks[] = $quantityProblems
        ? vcheck('quantity', 'Quantity', 'fail', implode(' ', $quantityProblems))
        : vcheck('quantity', 'Quantity', 'pass', 'Quantity is within the allowed 1 to 5 copies per document.');

    // 5. Requirements (uploaded files)
    $reqFail = [];
    $reqWarn = [];
    $reqCount = 0;
    foreach ($items as $item) {
        $usesCredentialForm = $credentialForm && credential_form_required_document($item['name']);
        $usesVerificationForm = $verificationForm && legacy_student_verification_document($item['name'], $item['category']);
        $requirements = effective_document_requirements($item['document_id'], $item['name'], $item['category'], !$usesCredentialForm && !$usesVerificationForm);
        foreach ($requirements as $req) {
            if (empty($req['id']) || !(int)$req['is_required']) continue;
            $reqCount++;
            $file = fetch_one("SELECT id, review_status FROM request_files WHERE request_id = ? AND item_id = ? AND requirement_id = ? AND kind = 'requirement' AND is_current = 1 ORDER BY id DESC LIMIT 1",
                [$requestId, $item['id'], $req['id']]);
            if (!$file) { $reqFail[] = "{$item['name']}: \"{$req['name']}\" was not uploaded."; continue; }
            if (in_array($file['review_status'], ['Rejected', 'Resubmit'], true)) { $reqFail[] = "{$item['name']}: \"{$req['name']}\" was declined."; continue; }
            $ai = fetch_one('SELECT status FROM ai_verifications WHERE file_id = ? ORDER BY id DESC LIMIT 1', [$file['id']]);
            if ($ai && $ai['status'] === 'FAIL') $reqFail[] = "{$item['name']}: \"{$req['name']}\" did not pass the document check.";
            elseif (!$ai || in_array($ai['status'], ['REVIEW', 'UNAVAILABLE'], true)) $reqWarn[] = "{$item['name']}: \"{$req['name']}\" could not be confirmed by the AI.";
        }
    }
    if ($reqFail) $checks[] = vcheck('requirements', 'Requirements', 'fail', implode(' ', $reqFail));
    elseif ($reqWarn) $checks[] = vcheck('requirements', 'Requirements', 'warn', implode(' ', $reqWarn));
    elseif ($reqCount) $checks[] = vcheck('requirements', 'Requirements', 'pass', 'All required files were uploaded and checked.');
    else $checks[] = vcheck('requirements', 'Requirements', 'pass', 'No file upload is required; the online form is used.');

    // 6. Eligibility
    $eligibility = [];
    foreach ($items as $item) {
        $problem = eligibility_problem($item['name'], $student['student_status'] ?? '');
        if ($problem) $eligibility[] = $problem;
    }
    $checks[] = $eligibility
        ? vcheck('eligibility', 'Eligibility', 'warn', implode(' ', array_unique($eligibility)))
        : vcheck('eligibility', 'Eligibility', 'pass', 'The student record allows these documents.');

    $route = verification_route_for_items($items);
    $verdict = verification_verdict($checks);
    $summary = verification_summary($verdict, $route, $checks);
    query('INSERT INTO request_verifications (request_id, route, verdict, summary, checks_json) VALUES (?, ?, ?, ?, ?)',
        [$requestId, $route, $verdict, $summary, json_encode($checks, JSON_UNESCAPED_UNICODE)]);
    return ['route' => $route, 'verdict' => $verdict, 'summary' => $summary, 'checks' => $checks];
}

// ---------- Moving the request forward ----------

// SUBMITTED / NEEDS_CORRECTION -> Document Checking -> For Admin Review (or back to NEEDS_CORRECTION).
// If the verification itself crashes, the request still goes to the Registrar, so a student is never stuck.
function finish_request_verification($requestId) {
    try {
        $result = run_request_verification($requestId);
    } catch (Throwable $e) {
        write_log('Request verification failed: ' . $e->getMessage());
        $current = fetch_one('SELECT status FROM requests WHERE id = ?', [$requestId]);
        if (!$current || $current['status'] !== 'AI_VERIFYING') change_status($requestId, 'AI_VERIFYING', null);
        change_status($requestId, 'FOR_REVIEW', null);
        return ['status' => 'FOR_REVIEW', 'route' => 'REGISTRAR', 'verdict' => 'REVIEW',
                'summary' => 'The automatic verification could not run. The Registrar will review this request.'];
    }
    change_status($requestId, 'AI_VERIFYING', null);
    if ($result['verdict'] === 'NEEDS_CORRECTION') {
        change_status($requestId, 'NEEDS_CORRECTION', null, $result['summary']);      // also tells the student
        $result['status'] = 'NEEDS_CORRECTION';
    } else {
        change_status($requestId, 'FOR_REVIEW', null);
        add_history($requestId, 'FOR_REVIEW', 'FOR_REVIEW', null, $result['summary']);  // the remark is not written to registrar_remarks
        $result['status'] = 'FOR_REVIEW';
    }
    return $result;
}

// Tells the student and the staff what happened. Call it AFTER the transaction was committed.
function notify_verification_result($requestId, $tracking, $studentId, $studentName, $result, $isCorrection = false) {
    if (($result['status'] ?? '') === 'NEEDS_CORRECTION') return;           // change_status already told the student
    $title = $isCorrection ? 'Corrections received' : 'Request submitted';
    if ($result['route'] === 'REGISTRAR') {
        $message = "Request $tracking passed AI verification and was sent to the Registrar for academic records verification and approval.";
    } elseif ($result['verdict'] === 'REVIEW') {
        $message = "Request $tracking was received. The AI found something to confirm, so the Registrar will review it manually.";
    } else {
        $message = "Request $tracking passed AI verification using your account information. The Registrar will confirm your payment and process it.";
    }
    add_notification($studentId, $requestId, $title, $message, 'success');

    if ($result['route'] === 'REGISTRAR') {
        notify_roles(STAFF_ROLES, $requestId, 'Academic records verification needed', "Request $tracking from $studentName needs academic records verification.", 'info');
    } elseif ($result['verdict'] === 'REVIEW') {
        notify_roles(STAFF_ROLES, $requestId, 'Manual review needed', "Request $tracking from $studentName was flagged by the AI: " . $result['summary'], 'warning');
    } else {
        notify_roles(STAFF_ROLES, $requestId, 'New request (AI-verified)', "Request $tracking from $studentName passed AI verification.", 'info');
    }
}

// ---------- What the portal shows ----------

// The student portal uses four simple words. Staff still see the detailed status.
function portal_status($status, $route = null) {
    switch ($status) {
        case 'SUBMITTED':        return ['key' => 'pending',            'label' => 'Pending',            'note' => 'Your request was received.'];
        case 'AI_VERIFYING':     return ['key' => 'under_verification', 'label' => 'Under Verification', 'note' => 'The AI is checking your request.'];
        case 'FOR_REVIEW':       return ['key' => 'under_verification', 'label' => 'Under Verification',
                                         'note' => $route === 'REGISTRAR'
                                             ? 'The Registrar is verifying your academic records.'
                                             : 'AI verification is done. Waiting for the Registrar to confirm your payment and approve.'];
        case 'NEEDS_CORRECTION': return ['key' => 'needs_correction',   'label' => 'Needs Correction',   'note' => 'Fix the items listed, then submit again.'];
        case 'PROCESSING':       return ['key' => 'approved',           'label' => 'Approved',           'note' => 'Approved. Your document is being prepared.'];
        case 'READY':            return ['key' => 'approved',           'label' => 'Approved',           'note' => 'Ready for pickup. You pay when you claim it.'];
        case 'COMPLETED':        return ['key' => 'completed',          'label' => 'Completed',          'note' => 'Your document was released.'];
        case 'REJECTED':         return ['key' => 'rejected',           'label' => 'Rejected',           'note' => 'See the remarks for the reason.'];
        case 'CANCELLED':        return ['key' => 'cancelled',          'label' => 'Cancelled',          'note' => 'Not paid or claimed in time. Submit a new request.'];
    }
    return ['key' => 'pending', 'label' => (string)$status, 'note' => ''];
}

// Newest verification of a request, ready for the browser (or null).
function latest_verification($requestId) {
    $row = fetch_one('SELECT route, verdict, summary, checks_json, created_at FROM request_verifications WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$requestId]);
    if (!$row) return null;
    return [
        'route'       => $row['route'],
        'route_label' => VERIFICATION_ROUTE_LABELS[$row['route']] ?? $row['route'],
        'verdict'     => $row['verdict'],
        'summary'     => $row['summary'],
        'checks'      => json_decode($row['checks_json'], true) ?: [],
        'created_at'  => $row['created_at'],
    ];
}