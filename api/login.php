<?php
// POST {username, password}  ->  logs the user in.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
require_method('POST');
start_session();

$input = read_json_body();
$username = clean_text($input['username'] ?? '', 50);
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '') fail('Please enter your username and password.', 422);

$user = fetch_one('SELECT * FROM users WHERE username = ?', [$username]);

// Too many wrong passwords: wait 15 minutes. The lock belongs to the username TOGETHER WITH the address of the computer,
// so a stranger cannot lock a real person out, and the message is the same whether the username exists or not.
$ip = request_ip();
$since = date('Y-m-d H:i:s', time() - 15 * 60);
$sameName = (int)fetch_one('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND username = ? AND created_at > ?', [$ip, $username, $since])['c'];
$anyName = (int)fetch_one('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND created_at > ?', [$ip, $since])['c'];
if ($sameName >= 5 || $anyName >= 30) fail('Too many failed attempts. Please try again in 15 minutes.', 429);
if (random_int(1, 50) === 1) query('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);

if (!$user || !password_verify($password, $user['password_hash'])) {
    query('INSERT INTO login_attempts (ip, username, created_at) VALUES (?, ?, ?)', [$ip, $username, date('Y-m-d H:i:s')]);
    fail('Incorrect username or password. Please try again.', 401);
}
// Staff sign in here. Student requesters use the guest session from the landing page (api/guest_start.php).
if (!in_array($user['role'], STAFF_ROLES, true)) fail('Incorrect username or password. Please try again.', 401);
if (!$user['is_active']) fail('This account is disabled. Please contact the registrar.', 403);

query('DELETE FROM login_attempts WHERE ip = ? AND username = ?', [$ip, $username]);
query('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
unset($_SESSION['csrf']);

json_response(['ok' => true, 'user' => user_for_browser($user), 'csrf' => csrf_token()]);