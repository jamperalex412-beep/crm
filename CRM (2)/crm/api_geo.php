<?php
// Живое определение ГЕО при вводе номера в карточке клиента.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { http_response_code(401); exit('{}'); }
$g = geo_from_phone((string)($_GET['phone'] ?? ''));
echo json_encode(['flag' => flag($g['iso']), 'name' => $g['name'], 'iso' => $g['iso']], JSON_UNESCAPED_UNICODE);
