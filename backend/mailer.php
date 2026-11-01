<?php
require_once __DIR__ . '/helpers.php';

function configured_mailer() {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        write_log('Email not sent: Composer dependencies are missing. Run composer install.');
        return null;
    }
    require_once $autoload;

    $host = env('MAIL_HOST', '');
    $from = env('MAIL_FROM', '');
    if ($host === '' || $from === '') {
        write_log('Email not sent: MAIL_HOST or MAIL_FROM is not configured.');
        return null;
    }
    if (env('MAIL_USERNAME', '') !== '' && env('MAIL_PASSWORD', '') === '') {
        write_log('Email not sent: MAIL_USERNAME is set but MAIL_PASSWORD is empty.');
        return null;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int)env('MAIL_PORT', '587');
        $mail->SMTPAuth = env('MAIL_USERNAME', '') !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = env('MAIL_USERNAME', '');
            $mail->Password = env('MAIL_PASSWORD', '');
        }
        $encryption = strtolower(env('MAIL_ENCRYPTION', 'tls'));
        if ($encryption === 'ssl') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        elseif ($encryption === 'tls') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($encryption !== 'none') throw new RuntimeException('MAIL_ENCRYPTION must be tls, ssl, or none.');

        // Without a timeout PHP waits up to 5 minutes for a dead SMTP server and the whole page hangs.
        $mail->Timeout = max(3, (int)env('MAIL_TIMEOUT', '10'));
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, env('MAIL_FROM_NAME', 'COLM Registrar'));
        // Optional: MAIL_REPLY_TO in .env (for example the registrar's real school address) so replies go to a person.
        $replyTo = trim((string)env('MAIL_REPLY_TO', ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo, env('MAIL_FROM_NAME', 'COLM Registrar'));
        $mail->XMailer = ' ';                                          // do not advertise "PHPMailer" in the email headers
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');    // tells mail servers this is an automatic system message
        return $mail;
    } catch (Throwable $error) {
        write_log('Email setup failed: ' . $error->getMessage());
        return null;
    }
}

// ---------------------------------------------------------------------------------------------------------------
// The ONE place where an email is sent. Every sender below uses it, so validation, logging and error handling are the same everywhere.
// Returns [true, ''] or [false, 'short reason for the administrator']. Never throws, never prints the SMTP password.
// $label is only for the error log ("password reset"). $mail may be a mailer made earlier by configured_mailer().
// ---------------------------------------------------------------------------------------------------------------
function mail_send($toEmail, $toName, $subject, $html, $text, $label, $mail = null) {
    $toEmail = trim((string)$toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return [false, 'The recipient email address is not valid.'];
    $mail = $mail ?: configured_mailer();
    if (!$mail) return [false, 'Email is not configured. Check MAIL_HOST, MAIL_FROM, MAIL_USERNAME and MAIL_PASSWORD in .env, and that "composer install" was run.'];
    try {
        $mail->addAddress($toEmail, (string)$toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
        return [true, ''];
    } catch (Throwable $error) {
        // Log the reason and who it was for (masked), never the password or the whole message.
        $masked = preg_replace('/^(.).*(@.*)$/', '$1***$2', $toEmail);
        write_log("Email ($label) to $masked failed: " . preg_replace('/\s+/', ' ', $error->getMessage()));
        return [false, mail_friendly_error($error->getMessage())];
    }
}

// Turns PHPMailer's technical message into a hint the administrator can act on.
function mail_friendly_error($message) {
    $m = strtolower($message);
    if (strpos($m, 'authenticate') !== false || strpos($m, 'username and password') !== false) return 'The SMTP server refused the username or password (MAIL_USERNAME / MAIL_PASSWORD). Gmail needs an App Password.';
    if (strpos($m, 'connect') !== false || strpos($m, 'timed out') !== false) return 'Could not connect to the SMTP server. Check MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION (587 = tls, 465 = ssl) and that the host allows outgoing SMTP.';
    if (strpos($m, 'sender') !== false || strpos($m, 'from address') !== false) return 'The SMTP server refused the sender address. MAIL_FROM usually must be the same mailbox as MAIL_USERNAME.';
    if (strpos($m, 'recipient') !== false) return 'The SMTP server refused the recipient address.';
    return 'The email server did not accept the message. See storage/error.log.';
}

function mail_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function send_student_account_email($email, $fullName, $username, $password) {
    [$ok] = mail_send($email, $fullName, 'Your COLM student account',
        '<p>Hello ' . mail_h($fullName) . ',</p><p>Your COLM student portal account is ready.</p>'
        . '<p>Student ID / username: <strong>' . mail_h($username) . '</strong><br>Temporary password: <strong>' . mail_h($password) . '</strong></p>'
        . '<p>Sign in to the COLM student portal and change your temporary password when prompted.</p>',
        "Hello {$fullName},\nYour COLM student portal account is ready.\nStudent ID / username: {$username}\nTemporary password: {$password}\n"
        . 'Sign in to the COLM student portal and change your temporary password when prompted.', 'student account');
    return $ok;
}

function send_student_approval_email($email, $fullName, $studentId) {
    [$ok] = mail_send($email, $fullName, 'Your COLM student account is approved',
        '<p>Hello ' . mail_h($fullName) . ',</p><p>Your COLM student portal account has been approved. You can now sign in using your Student ID <strong>' . mail_h($studentId) . '</strong> and the password you created during registration.</p>',
        "Hello {$fullName},\nYour COLM student portal account has been approved. You can now sign in using your Student ID {$studentId} and the password you created during registration.", 'account approval');
    return $ok;
}

function send_password_reset_email($email, $fullName, $resetUrl, $mail = null) {
    [$ok] = mail_send($email, $fullName, 'Reset your COLM portal password',
        '<p>Hello ' . mail_h($fullName) . ',</p><p>We received a request to reset your COLM portal password.</p>'
        . '<p><a href="' . mail_h($resetUrl) . '">Reset your password</a></p>'
        . '<p>This link expires in 30 minutes and can only be used once. If you did not request this, you can ignore this email.</p>',
        "Hello {$fullName},\nWe received a request to reset your COLM portal password.\nUse this link within 30 minutes (one use only):\n{$resetUrl}\nIf you did not request this, you can ignore this email.",
        'password reset', $mail);
    return $ok;
}

// "Your document is ready for pickup". Returns [true, ''] or [false, 'reason'].
function send_ready_for_pickup_email($email, $fullName, $trackingNo, $documents, $expectedRelease, $claimBy, $paymentNote) {
    $list = $documents ? implode(', ', $documents) : 'your requested document';
    $lines = ["Request reference: $trackingNo", "Document: $list"];
    if ($expectedRelease !== '') $lines[] = "Expected release date: $expectedRelease";
    if ($claimBy !== '') $lines[] = "Please claim it on or before: $claimBy (unclaimed and unpaid requests are cancelled after 14 days)";
    $pickup = 'Pickup: Office of the Registrar, 9:00 A.M. - 4:00 P.M. Bring a valid ID. If you cannot come, your representative can bring your valid ID, a letter of authorization and a copy of your ID.';
    if ($paymentNote !== '') $pickup .= ' ' . $paymentNote;
    return mail_send($email, $fullName, "Your document is ready for pickup ($trackingNo)",
        '<p>Hello ' . mail_h($fullName) . ',</p><p>Your requested document is ready for pickup.</p><p>' . implode('<br>', array_map('mail_h', $lines)) . '</p><p>' . mail_h($pickup) . '</p>',
        "Hello $fullName,\n\nYour requested document is ready for pickup.\n\n" . implode("\n", $lines) . "\n\n$pickup", 'ready for pickup');
}
