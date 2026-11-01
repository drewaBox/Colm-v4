<?php
// Audit log viewer (Admin only, read-only): GET ?page=1&action=&request_id=&from=YYYY-MM-DD&to=YYYY-MM-DD
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/audit.php';
register_error_handlers();
require_method('GET');
require_login(['admin']);

$where = []; $params = [];
$action = clean_text($_GET['action'] ?? '', 40);
if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }
if ((int)($_GET['request_id'] ?? 0) > 0) { $where[] = 'a.request_id = ?'; $params[] = (int)$_GET['request_id']; }
foreach (['from' => ['>=', ' 00:00:00'], 'to' => ['<=', ' 23:59:59']] as $key => [$op, $time]) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$key] ?? '')) { $where[] = "a.created_at $op ?"; $params[] = $_GET[$key] . $time; }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)fetch_one("SELECT COUNT(*) AS c FROM audit_log a $whereSql", $params)['c'];
$rows = fetch_all("SELECT a.id, a.action, a.entity, a.entity_id, a.request_id, a.old_value, a.new_value, a.details, a.created_at, COALESCE(u.full_name, 'System') AS by_name
                   FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $whereSql ORDER BY a.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params);
json_response(['ok' => true, 'entries' => $rows, 'total' => $total, 'page' => $page, 'per_page' => 50]);
