<?php
// Быстрая смена статуса из списка клиентов (AJAX).
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$u = current_user();
if (!$u || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(401); exit('{"ok":false}'); }
csrf_check();

$c = q('SELECT * FROM clients WHERE id = ?', [(int)($_POST['client_id'] ?? 0)])->fetch();
$sid = (int)($_POST['status_id'] ?? 0);
if (!$c || !can_view_client($u, $c) || !isset(statuses()[$sid])) {
    http_response_code(403);
    exit('{"ok":false}');
}
set_client_status($c, $sid, (int)$u['id']);
echo json_encode(['ok' => true, 'name' => status_name($sid)], JSON_UNESCAPED_UNICODE);
