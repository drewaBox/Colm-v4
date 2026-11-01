<?php
// The audit trail: one line for every important action (payment, release date, cancellation, archive ...).
// request_history (status changes the student can see) is separate and unchanged.
require_once __DIR__ . '/database.php';

// $userId = null means "the system did it" (for example the automatic cancellation).
function audit_log($action, $entity, $entityId, $requestId, $userId, $oldValue = null, $newValue = null, $details = '') {
    query('INSERT INTO audit_log (action, entity, entity_id, request_id, user_id, old_value, new_value, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [mb_substr($action, 0, 40), mb_substr($entity, 0, 30), $entityId, $requestId, $userId,
         $oldValue === null ? null : mb_substr((string)$oldValue, 0, 255),
         $newValue === null ? null : mb_substr((string)$newValue, 0, 255),
         mb_substr((string)$details, 0, 500), PHP_SAPI === 'cli' ? 'cli' : request_ip()]);
}
