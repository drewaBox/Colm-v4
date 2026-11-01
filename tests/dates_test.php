<?php
// Tests estimated release dates, counting working days from the submission date.
require_once __DIR__ . '/../backend/workflow.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++;
    echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}

expect('One working day after Monday is Tuesday', estimate_processing_date('2026-10-12', 1), '2026-10-13');
expect('Friday processing time skips the weekend', estimate_processing_date('2026-10-16', 1), '2026-10-19');
expect('Three working days after Friday ends Wednesday', estimate_processing_date('2026-10-16', 3), '2026-10-21');
expect('A zero-day estimate on a weekday is the same day', estimate_processing_date('2026-10-12', 0), '2026-10-12');
expect('A weekend submission starts on the next working day', estimate_processing_date('2026-10-17', 0), '2026-10-19');
expect('A weekend submission counts from Monday', estimate_processing_date('2026-10-17', 1), '2026-10-20');

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
