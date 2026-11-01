<?php
// Checks the email setup and (optionally) sends one test message:
//   php tools/test_mail.php                      checks the configuration only
//   php tools/test_mail.php you@example.com      also sends a test email to that address
// It shows what is wrong in plain words and never prints the SMTP password.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
require_once __DIR__ . '/../backend/mailer.php';

$problems = [];
if (!is_file(__DIR__ . '/../vendor/autoload.php')) $problems[] = 'vendor/ is missing: run "composer install" in the project folder (PHPMailer is not installed).';
$host = env('MAIL_HOST', ''); $from = env('MAIL_FROM', ''); $user = env('MAIL_USERNAME', '');
$port = (int)env('MAIL_PORT', '587'); $enc = strtolower(env('MAIL_ENCRYPTION', 'tls'));
if ($host === '') $problems[] = 'MAIL_HOST is empty.';
if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $problems[] = 'MAIL_FROM is empty or not a valid email address.';
if ($user !== '' && env('MAIL_PASSWORD', '') === '') $problems[] = 'MAIL_USERNAME is set but MAIL_PASSWORD is empty.';
if ($user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL) && strcasecmp($user, $from) !== 0) $problems[] = 'MAIL_FROM differs from MAIL_USERNAME. Many hosts (Gmail, Hostinger) reject or rewrite this.';
if ($port === 465 && $enc !== 'ssl') $problems[] = 'Port 465 needs MAIL_ENCRYPTION=ssl.';
if ($port === 587 && $enc !== 'tls') $problems[] = 'Port 587 needs MAIL_ENCRYPTION=tls.';
if ($host !== '' && !@fsockopen(($enc === 'ssl' ? 'ssl://' : '') . $host, $port ?: 587, $errno, $errstr, 5)) $problems[] = "Cannot open a connection to $host:$port ($errstr). The host may block outgoing SMTP or the host/port is wrong.";

echo "Host: " . ($host ?: '(none)') . "  Port: $port  Encryption: $enc  From: " . ($from ?: '(none)') . "  Username: " . ($user ?: '(none)') . "\n";
echo $problems ? "PROBLEMS:\n - " . implode("\n - ", $problems) . "\n" : "Configuration looks complete.\n";

$to = $argv[1] ?? '';
if ($to !== '') {
    [$ok, $error] = mail_send($to, 'Test', 'COLM Registrar email test', '<p>This is a test email from the COLM Registrar system.</p>', 'This is a test email from the COLM Registrar system.', 'test');
    echo $ok ? "Sent to $to. The SMTP server ACCEPTED it; open the inbox (and spam) to confirm it really arrived.\n" : "NOT sent: $error\n";
    exit($ok ? 0 : 1);
}
