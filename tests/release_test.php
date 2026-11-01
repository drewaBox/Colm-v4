<?php
// Pure-logic tests (no database): php tests/release_test.php
require_once __DIR__ . '/../backend/release.php';
require_once __DIR__ . '/../backend/masterlist.php';
$failed = 0;
function check($name, $ok) { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $failed++; }

// claim deadline: 14 days after the ready DATE, end of that day; later expected release date moves the start
check('deadline from ready_at', claim_deadline(['ready_at' => '2026-11-02 10:30:00', 'expected_release_date' => null])->format('Y-m-d H:i:s') === '2026-11-16 23:59:59');
check('expected date later than ready moves deadline', claim_deadline(['ready_at' => '2026-11-02 10:30:00', 'expected_release_date' => '2026-11-10'])->format('Y-m-d') === '2026-11-24');
check('expected date earlier than ready is ignored', claim_deadline(['ready_at' => '2026-11-10 08:00:00', 'expected_release_date' => '2026-11-03'])->format('Y-m-d') === '2026-11-24');
check('no ready_at = no deadline', claim_deadline(['ready_at' => null]) === null);

// release date validation
$req = ['created_at' => date('Y-m-d H:i:s')];
$monday = (new DateTimeImmutable('today'))->modify('next monday')->format('Y-m-d');
$saturday = (new DateTimeImmutable('today'))->modify('next saturday')->format('Y-m-d');
check('weekday in the future is fine', release_date_problem($monday, $req) === '');
check('weekend refused', release_date_problem($saturday, $req) !== '');
check('past date refused', release_date_problem('2020-01-01', $req) !== '');
check('2026-02-30 refused', release_date_problem('2026-02-30', $req) !== '');
check('text refused', release_date_problem('tomorrow', $req) !== '');
check('too far refused', release_date_problem((new DateTimeImmutable('today'))->modify('+200 days')->format('Y-m-d'), $req) !== '');

// purposes
function purpose($c, $o) { try { return resolve_item_purpose($c, $o, 'TOR'); } catch (RuntimeException $e) { return false; } }
check('listed purpose', purpose('Employment', '') === 'Employment');
check('Others needs text', purpose('Others', '') === false && purpose('Others', 'ab') === false);
check('Others saves the real text', purpose('Others', 'Visa application') === 'Visa application');
check('unknown purpose refused', purpose('Hacking', '') === false);

// semester ordering
check('period order', master_period_key('2025-2026', 2) > master_period_key('2025-2026', 1) && master_period_key('2026-2027', 1) > master_period_key('2025-2026', 3));
check('bad academic year', master_period_key('2025-2027', 1) === null && master_period_key('2025-2026', 4) === null);

echo $failed ? "\n$failed test(s) FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
