<?php
// Tests the routing, eligibility and status words of the request-level AI verification (no database needed).
// Run:  php tests/verification_test.php
define('STAFF_ROLES', ['admin', 'registrar']);
function env($k, $d = null) { return $d; }
function fetch_all(...$a) { return []; }
function fetch_one(...$a) { return null; }
function query(...$a) {}
function write_log(...$a) {}
function fail(...$a) { throw new RuntimeException('fail'); }
require_once __DIR__ . '/../backend/names.php';
// Only the pure functions are needed, so load the file without its database/workflow dependencies.
$source = file_get_contents(__DIR__ . '/../backend/verification.php');
$source = preg_replace("/^require_once .*$/m", '', $source);
function credential_request_document($name) {
    $n = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$name));
    return strpos($n, 'transcriptofrecords') === 0 || $n === 'honorabledismissal' || strpos($n, 'form137') === 0;
}
eval('?>' . $source);

$failed = 0;
function check($label, $actual, $expected) {
    global $failed;
    if ($actual !== $expected) { $failed++; echo "FAIL: $label\n  expected " . var_export($expected, true) . "\n  got      " . var_export($actual, true) . "\n"; }
}

// routing
check('TOR -> registrar', verification_route_for_items([['name' => 'Transcript of Records (TOR)', 'category' => 'Academic Records']]), 'REGISTRAR');
check('Good Moral -> auto', verification_route_for_items([['name' => 'Certificate of Good Moral Character', 'category' => 'Certification']]), 'AUTO');
check('COE -> auto', verification_route_for_items([['name' => 'Certificate of Enrollment', 'category' => 'Certification']]), 'AUTO');
check('mixed -> registrar', verification_route_for_items([
    ['name' => 'Certificate of Enrollment', 'category' => 'Certification'],
    ['name' => 'Transcript of Records (TOR)', 'category' => 'Academic Records']]), 'REGISTRAR');
check('Diploma -> registrar', verification_route_for_items([['name' => 'Diploma', 'category' => 'Academic Records']]), 'REGISTRAR');

// eligibility
check('COE enrolled ok', eligibility_problem('Certificate of Enrollment', 'currently_enrolled'), null);
check('COE alumni flagged', is_string(eligibility_problem('Certificate of Enrollment', 'alumni')), true);
check('Graduation alumni ok', eligibility_problem('Certificate of Graduation', 'alumni'), null);
check('Graduation enrolled flagged', is_string(eligibility_problem('Certificate of Graduation', 'currently_enrolled')), true);
check('Good Moral no rule', eligibility_problem('Certificate of Good Moral Character', 'undergrad'), null);
check('Other certification no rule', eligibility_problem('Other Certifications', 'alumni'), null);

// verdict
check('all pass', verification_verdict([vcheck('a', 'A', 'pass', ''), vcheck('b', 'B', 'pass', '')]), 'PASS');
check('warn -> review', verification_verdict([vcheck('a', 'A', 'pass', ''), vcheck('b', 'B', 'warn', '')]), 'REVIEW');
check('fail wins', verification_verdict([vcheck('a', 'A', 'warn', ''), vcheck('b', 'B', 'fail', '')]), 'NEEDS_CORRECTION');

// portal words
check('submitted', portal_status('SUBMITTED')['label'], 'Pending');
check('ai verifying', portal_status('AI_VERIFYING')['label'], 'Under Verification');
check('for review', portal_status('FOR_REVIEW', 'REGISTRAR')['label'], 'Under Verification');
check('processing', portal_status('PROCESSING')['label'], 'Approved');
check('ready', portal_status('READY')['label'], 'Approved');
check('rejected', portal_status('REJECTED')['label'], 'Rejected');
check('registrar note', strpos(portal_status('FOR_REVIEW', 'REGISTRAR')['note'], 'Registrar') !== false, true);

echo $failed ? "\n$failed test(s) FAILED\n" : "All verification tests passed.\n";
exit($failed ? 1 : 0);
