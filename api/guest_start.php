<?php
// POST {first_name, last_name, student_id, course, year_level, email [, code]}
// Public entry for the landing page "Request a Document" form (no login page for requesters).
// Step 1 (no code): email a 6-digit code, or show a local-only code in development on localhost.
// NOTHING is created or opened until the code is verified.
// Step 2 (with code): the code proves the person owns the mailbox. Only then is the passwordless student record
//   created (or re-opened) and the session started. So knowing a Student ID and an email address is NOT enough to open someone's requests.
// Code rules: valid 10 minutes, one use, 5 wrong tries, 60 seconds between emails, 5 emails per hour per address.
// Admin and registrar still use api/login.php.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/students.php';
require_once __DIR__ . '/../backend/mailer.php';
require_once __DIR__ . '/../backend/ratelimit.php';
register_error_handlers();
require_method('POST');
start_session();

$in = read_json_body();
$first  = clean_text($in['first_name'] ?? '', 50);
$lastIn = clean_text($in['last_name'] ?? '', 50);
$course = clean_text($in['course'] ?? '', 80);
$yearLabel = clean_text($in['year_level'] ?? '', 30);
$email  = strtolower(clean_text($in['email'] ?? '', 120));
$studentId = preg_replace('/[^0-9-]/', '', (string)($in['student_id'] ?? ''));
$studentId = mb_substr($studentId, 0, 30);

$years = ['First Year' => 1, 'Second Year' => 2, 'Third Year' => 3, 'Fourth Year' => 4, 'Graduate / Alumni' => null];
$isAlumni = $yearLabel === 'Graduate / Alumni';

if ($first === '' || $lastIn === '' || $course === '' || $email === '') fail('Please fill out all fields.', 422);
if (!array_key_exists($yearLabel, $years)) fail('Please choose your year level.', 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.', 422);
if (!$isAlumni && $studentId === '') fail('Please enter your Student ID.', 422);
if ($isAlumni) $studentId = '';
if (level_of_course($course) === null) fail('Please choose a course from the list.', 422);

// Same flood limit idea as the login: at most 20 new sessions from one address in 15 minutes.
$ip = request_ip();
$since = date('Y-m-d H:i:s', time() - 15 * 60);
$recent = (int)fetch_one('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND username = ? AND created_at > ?', [$ip, 'guest-start', $since])['c'];
if ($recent >= 20) fail('Too many attempts. Please try again in 15 minutes.', 429);
query('INSERT INTO login_attempts (ip, username, created_at) VALUES (?, ?, ?)', [$ip, 'guest-start', date('Y-m-d H:i:s')]);

// ---------- Email ownership check (OTP) ----------
$code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
$now = date('Y-m-d H:i:s');
$yearKey = $yearLabel;
if ($code === '') {
    $mailer = configured_mailer();
    if (!$mailer) {
        if (env('MAIL_HOST', '') === 'smtp.gmail.com' && env('MAIL_USERNAME', '') !== '' && env('MAIL_PASSWORD', '') === '') {
            fail('Gmail is missing its App Password. Set MAIL_PASSWORD in .env to your 16-character Google App Password (not your regular Gmail password), then try again.', 503);
        }
        fail('The verification email is not configured. Check the Gmail settings in .env and run composer install.', 503);
    }
    if (!rate_limit_allow('guest-otp-ip:' . $ip, 10, 3600)) fail('Too many codes were requested from this connection. Please try again later.', 429);
    $last = fetch_one('SELECT created_at FROM guest_otps WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]);
    if ($last && strtotime($last['created_at']) > time() - 60) fail('A code was just sent. Please wait one minute before asking for another one.', 429);
    $hourly = (int)fetch_one('SELECT COUNT(*) AS c FROM guest_otps WHERE email = ? AND created_at > ?', [$email, date('Y-m-d H:i:s', time() - 3600)])['c'];
    if ($hourly >= 5) fail('Too many codes were sent to this email address. Please try again in an hour.', 429);

    $newCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $payload = json_encode(['first' => $first, 'last' => $lastIn, 'course' => $course, 'year' => $yearKey, 'student_id' => $studentId], JSON_UNESCAPED_UNICODE);
    query('INSERT INTO guest_otps (email, student_no, code_hash, payload_json, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$email, $studentId !== '' ? $studentId : null, hash('sha256', $email . '|' . $newCode), $payload, date('Y-m-d H:i:s', time() + 600), $now]);
    $otpId = (int)db()->lastInsertId();
    [$sent, $mailError] = mail_send($email, $first, 'Your COLM verification code',
        '<p>Hello ' . mail_h($first) . ',</p><p>Your verification code is <strong style="font-size:20px;letter-spacing:3px">' . $newCode . '</strong></p><p>It expires in 10 minutes. If you did not ask for it, ignore this email.</p>',
        "Hello $first,\nYour COLM verification code is $newCode. It expires in 10 minutes. If you did not ask for it, ignore this email.", 'guest code', $mailer);
    if (!$sent) {
        query('DELETE FROM guest_otps WHERE id = ?', [$otpId]);
        write_log('Guest verification email failed: ' . $mailError);
        fail('We could not send the verification email. Please try again later or contact the Registrar.', 503);
    }
    json_response(['ok' => true, 'otp_required' => true, 'message' => 'We sent a 6-digit code to ' . $email . '. Enter it below to continue.']);
}

// Step 2: check the code. The latest unused, unexpired code for this email is the only one that counts.
$otp = fetch_one('SELECT id, code_hash, payload_json, attempts FROM guest_otps WHERE email = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$email, $now]);
if (!$otp) fail('The code expired or was already used. Press Continue to get a new code.', 422);
if ((int)$otp['attempts'] >= 5) fail('Too many wrong codes. Press Continue later to get a new code.', 429);
query('UPDATE guest_otps SET attempts = attempts + 1 WHERE id = ?', [$otp['id']]);
if (!hash_equals($otp['code_hash'], hash('sha256', $email . '|' . $code))) fail('That code is not correct. Please check your email and try again.', 422);
// One use only: if two requests send the right code at the same moment, only one wins.
if (query('UPDATE guest_otps SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, $otp['id']])->rowCount() !== 1) fail('The code was already used. Press Continue to get a new code.', 422);
// From here on use what the verified code was issued for, not what the browser sends now.
$saved = json_decode($otp['payload_json'], true) ?: [];
$first = $saved['first'] ?? $first; $course = $saved['course'] ?? $course; $yearLabel = $saved['year'] ?? $yearLabel; $studentId = $saved['student_id'] ?? $studentId;
$lastName = $saved['last'] ?? '';
$isAlumni = $yearLabel === 'Graduate / Alumni';

$fullName = mb_substr($first . ' ' . $lastName, 0, 120);
$year = $years[$yearLabel] ?? null;
$status = $isAlumni ? 'alumni' : 'currently_enrolled';
$userId = 0;

try {
    $existing = $studentId !== ''
        ? fetch_one('SELECT id, username, email, is_active FROM users WHERE student_no = ?', [$studentId])
        // alumni have no Student ID: the verified email is their identity, so the same person gets the same record every time
        : fetch_one("SELECT id, username, email, is_active FROM users WHERE email = ? AND student_no IS NULL AND username LIKE 'guest-%' ORDER BY id LIMIT 1", [$email]);
    if ($existing) {
        // A returning requester may only re-open a record that was made by this form, and only with the same email.
        $isGuest = strpos((string)$existing['username'], 'guest-') === 0;
        if (!$isGuest || strtolower((string)$existing['email']) !== $email || !$existing['is_active']) {
            fail('This Student ID already has a request record. Please use the same email address you used before, or contact the registrar.', 409);
        }
        $userId = (int)$existing['id'];
        query('UPDATE users SET full_name = ? WHERE id = ?', [$fullName, $userId]);
        query('UPDATE students SET school_level = ?, course = ?, year_level = ?, status = ? WHERE user_id = ?',
            ['COLLEGE', $course, $year, $status, $userId]);
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            query('INSERT INTO users (username, password_hash, password_changed_at, full_name, email, role, student_no, must_change_password, is_active, is_approved) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, 0, 1, 1)',
                ['guest-' . bin2hex(random_bytes(8)), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                 $fullName, $email, 'student', $studentId !== '' ? $studentId : null]);
            $userId = (int)$pdo->lastInsertId();
            query('INSERT INTO students (user_id, school_level, course, year_level, section, status) VALUES (?, ?, ?, ?, NULL, ?)',
                [$userId, 'COLLEGE', $course, $year, $status]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
} catch (Throwable $e) {
    write_log('guest_start failed: ' . $e->getMessage());
    fail('Could not start your request. Please try again.', 500);
}

$user = fetch_one('SELECT id, username, full_name, email, role, student_no, must_change_password, password_changed_at FROM users WHERE id = ?', [$userId]);
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
unset($_SESSION['csrf']);

json_response(['ok' => true, 'user' => user_for_browser($user), 'csrf' => csrf_token()]);