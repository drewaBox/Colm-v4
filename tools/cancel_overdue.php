<?php
// The automatic 14-day cancellation. Run it every hour (cron) - it is safe to run as often as you like:
//   php tools/cancel_overdue.php            cancels and prints what it did
//   php tools/cancel_overdue.php --dry-run  only lists what would be cancelled
// Hostinger: hPanel > Advanced > Cron Jobs > "php /home/USER/public_html/tools/cancel_overdue.php" every hour.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line or a cron job.'); }
require_once __DIR__ . '/../backend/release.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$result = cancel_overdue_requests($dryRun);
echo date('Y-m-d H:i:s') . ($dryRun ? ' [dry run] ' : ' ') . count($result['cancelled']) . ' request(s) ' . ($dryRun ? 'would be cancelled' : 'cancelled')
   . ($result['cancelled'] ? ': ' . implode(', ', $result['cancelled']) : '') . ' (skipped: ' . $result['skipped'] . ")\n";
