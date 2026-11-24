<?php
// Колокольчик: наступившие перезвоны + непрочитанные уведомления.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$u = current_user();
if (!$u) { http_response_code(401); exit('{}'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$u['id']]);
    exit('{"ok":true}');
}

$cbs = array_map(fn($c) => [
    'id' => (int)$c['id'],
    'name' => $c['full_name'] ?: 'Без имени',
    'phone' => $c['phone'],
    'at' => fmt_dt($c['callback_at'], 'd.m H:i'),
], due_callbacks((int)$u['id']));

$notes = array_map(fn($n) => [
    'id' => (int)$n['id'],
    'body' => $n['body'],
    'client' => $n['client_id'] ? (int)$n['client_id'] : null,
    'at' => fmt_dt($n['created_at'], 'd.m H:i'),
    'unread' => !$n['is_read'],
], q('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$u['id']])->fetchAll());

$unread = (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']])->fetchColumn();
$dueTotal = (int)q('SELECT COUNT(*) FROM clients WHERE assigned_to = ? AND callback_at IS NOT NULL AND callback_at <= NOW()', [$u['id']])->fetchColumn();

echo json_encode(['callbacks' => $cbs, 'due' => $dueTotal, 'notes' => $notes, 'unread' => $unread], JSON_UNESCAPED_UNICODE);
