<?php
// Admin only: sends one test email to the admin's own address so SMTP can be checked from the website. POST, with the CSRF token.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/mailer.php';
require_once __DIR__ . '/../backend/ratelimit.php';
register_error_handlers();
require_method('POST');
$user = require_login(['admin']);
require_csrf();
if (!rate_limit_allow('mail-test:' . $user['id'], 5, 600)) fail('Too many tests. Please wait a few minutes.', 429);
$me = fetch_one('SELECT email FROM users WHERE id = ?', [$user['id']]);
[$ok, $error] = mail_send($me['email'] ?? '', $user['full_name'] ?? 'Admin', 'COLM Registrar email test', '<p>This is a test email from the COLM Registrar system.</p>', 'This is a test email from the COLM Registrar system.', 'admin test');
if (!$ok) fail($error, 502);
json_response(['ok' => true, 'message' => 'The email server accepted the test message. Check your inbox (and spam) to confirm it arrived.']);
