<?php
// Shows the drawn signature of a request (a picture). Only the student who owns the request and the admin/registrar can open it.
// There is no way to upload, change or delete a signature here: it is saved once, when the request is submitted.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
register_error_handlers();
require_method('GET');
$user = require_login(['student', 'admin', 'registrar']);

$row = fetch_one('SELECT s.stored_name, s.sha256, r.student_id FROM request_signatures s JOIN requests r ON r.id = s.request_id WHERE s.request_id = ?', [(int)($_GET['request_id'] ?? 0)]);
if (!$row || ($user['role'] === 'student' && (int)$row['student_id'] !== (int)$user['id'])) fail('Signature not found.', 404);
$path = stored_file_path($row['stored_name']);
if (!is_file($path)) fail('The signature file is missing on the server.', 404);
if (!hash_equals($row['sha256'], hash_file('sha256', $path))) {
    write_log('Signature file changed on disk for ' . $row['stored_name']);
    fail('The signature file does not match its record. Contact the administrator.', 500);
}
send_stored_file(['stored_name' => $row['stored_name'], 'original_name' => 'signature.png', 'mime_type' => 'image/png'], true);
