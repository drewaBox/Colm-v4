<?php
require_once __DIR__ . '/../backend/mailer.php';
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
require_method('POST');

$input = read_json_body();
$action = (string)($input['action'] ?? '');

if ($action === 'request') {
    $username = clean_text($input['username'] ?? '', 50);
    $email = mb_strtolower(trim((string)($input['email'] ?? '')));
    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
        fail('Enter a valid username and email address.', 422);
    }

    $mailer = configured_mailer();
    if (!$mailer) fail('Password recovery email is not configured. Please contact the administrator.', 503);
    try {
        $baseUrl = reset_password_base_url();
    } catch (RuntimeException $error) {
        write_log('Password reset URL could not be created: ' . $error->getMessage());
        fail('Password recovery link is not configured. Please contact the administrator.', 503);
    }

    $ip = request_ip();
    $recentRequests = (int)fetch_one(
        'SELECT COUNT(*) AS count FROM password_reset_requests WHERE ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        [$ip]
    )['count'];
    if ($recentRequests < 5) {
        query('INSERT INTO password_reset_requests (ip) VALUES (?)', [$ip]);
        $user = fetch_one(
            "SELECT id, full_name, email FROM users WHERE username = ? AND email = ? AND is_active = 1 AND role = 'admin'",
            [$username, $email]
        );
        if ($user && $user['email']) {
            $token = bin2hex(random_bytes(32));
            query(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                 ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP',
                [(int)$user['id'], hash('sha256', $token)]
            );

            $emailSent = send_password_reset_email(
                $user['email'],
                $user['full_name'],
                $baseUrl . '/frontend/reset-password.html?token=' . rawurlencode($token),
                $mailer
            );
            if (!$emailSent) {
                query('DELETE FROM password_reset_tokens WHERE user_id = ?', [(int)$user['id']]);
                fail('The reset email could not be sent. Please try again later or contact the Registrar.', 503);
            }
        }
    }

    if (random_int(1, 20) === 1) {
        query('DELETE FROM password_reset_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        query('DELETE FROM password_reset_tokens WHERE expires_at < NOW()');
    }

    json_response([
        'ok' => true,
        'message' => 'If the username and email match an active account, and email is available, a password reset link will be sent. Check your inbox and spam folder. If you no longer have access to the email, contact the Registrar.'
    ]);
}

if ($action === 'reset') {
    $token = (string)($input['token'] ?? '');
    $password = (string)($input['password'] ?? '');
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) fail('This reset link is invalid or has expired.', 422);
    if (strlen($password) < 8) fail('The new password must be at least 8 characters.', 422);
    if (strlen($password) > 256) fail('The new password must be 256 characters or fewer.', 422);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = fetch_one(
            'SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE',
            [hash('sha256', $token)]
        );
        if (!$row) {
            $pdo->rollBack();
            fail('This reset link is invalid or has expired.', 422);
        }

        query(
            'UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP, must_change_password = 0 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]
        );
        query('DELETE FROM password_reset_tokens WHERE user_id = ?', [(int)$row['user_id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    json_response(['ok' => true]);
}

fail('Invalid password reset request.', 422);

function reset_password_base_url() {
    $configured = env('APP_URL', '');
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('APP_URL must be an http(s) base URL without query or fragment.');
        }
        return rtrim($configured, '/');
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/\A[a-z0-9.-]+(?::\d{1,5})?\z/i', $host)) {
        throw new RuntimeException('Set APP_URL in .env to the public base URL of this site.');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (env('TRUST_PROXY', '') === '1' && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $basePath = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/password_reset.php'))));
    return ($https ? 'https://' : 'http://') . $host . ($basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/'));
}