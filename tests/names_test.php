<?php
// Tests the name comparison used by AI verification.   Run:  php tests/names_test.php
require_once __DIR__ . '/../backend/names.php';

$failed = 0;
$cases = [
    // account name,      name on the document,   should it match?
    ['Juan Dela Cruz',   'CRUZ, JUAN D.',        true],
    ['Juan Dela Cruz',   'Juan Cruz',            true],
    ['Juan Dela Cruz',   'JUAN DELA CRUZ',       true],
    ['Juan Dela Cruz',   'Mr. Juan D. Cruz Jr.', true],
    ['Juan Dela Cruz',   'Juam Dela Cruz',       true],   // one letter misread
    ['Maria Peña',       'MARIA PENA',           true],   // accents
    ['Ana Garcia',       'Anna Garcia',          true],
    ['Juan Dela Cruz',   'Maria Santos',         false],
    ['Juan Dela Cruz',   'Juan Santos',          false],
    ['Juan Dela Cruz',   'Jon Dela Cruz',        false],
    ['Ana Garcia',       'Ana Garza',            false],
    ['Pedro Reyes',      'Pablo Reyes',          false],
    ['Juan Dela Cruz',   '',                     false],
];
foreach ($cases as [$account, $document, $expected]) {
    $result = names_match($account, $document);
    $ok = $result['match'] === $expected;
    if (!$ok) $failed++;
    printf("  %s  \"%s\" vs \"%s\" -> %s (%d%%)\n", $ok ? 'PASS' : 'FAIL', $account, $document, $result['match'] ? 'match' : 'different', $result['score']);
}
echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
