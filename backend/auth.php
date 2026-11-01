<?php
// Login session, role checks and CSRF protection.
require_once __DIR__ . '/database.php';

function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(env('SESSION_NAME', 'COLM_SESSID'));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (env('TRUST_PROXY', '') === '1' && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Returns the logged-in user (from the database, so a disabled account is blocked at once) or null.
function current_user() {
    start_session();
    if (empty($_SESSION['user_id'])) return null;
    static $user = false;
    if ($user === false) {
        $user = fetch_one('SELECT id, username, full_name, email, role, student_no, is_active, is_approved, must_change_password, password_changed_at FROM users WHERE id = ?', [$_SESSION['user_id']]);
    }
    if (!$user || !$user['is_active'] || $user['role'] === 'cashier') return null;
    return $user;
}

// Every protected API starts with this. $roles = list of allowed roles (empty = any logged-in user).
function require_login($roles = []) {
    $user = current_user();
    if (!$user) fail('Please log in first.', 401);
    // A new account (for example an imported student) must choose its own password first.
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($user['must_change_password'] && !in_array($script, ['me.php', 'password.php', 'logout.php'], true)) {
        fail('Please change your default password first (Settings page).', 403, ['code' => 'PASSWORD_CHANGE_REQUIRED']);
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        // Someone typed an api/ address in the browser: send them back to their own page.
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            header('Location: ../frontend/' . home_page_for($user['role']));
            exit;
        }
        fail('You are not allowed to do this.', 403);
    }
    return $user;
}

function csrf_token() {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

// Every request that changes data must send the token in the X-CSRF-Token header.
function require_csrf() {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) fail('Security check failed. Please refresh the page.', 403);
}

// Admin and registrar share the same management pages.
const STAFF_ROLES = ['admin', 'registrar'];

// The page each role uses after login (see frontend/).
function home_page_for($role) {
    return $role === 'student' ? 'student.html' : 'manage.html';
}

function user_for_browser($user) {
    return [
        'id'         => (int)$user['id'],
        'username'   => $user['username'],
        'full_name'  => $user['full_name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'student_no' => $user['student_no'],
        'must_change_password' => (bool)$user['must_change_password'],
        'password_changed_at' => $user['password_changed_at'],
        'home'       => home_page_for($user['role']),
    ];
}
