<?php
// Экспорт клиентов (текущие фильтры списка) в CSV для Excel.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['admin', 'shift']);

$f = filters_from_get();
[$w, $params] = client_filters($u, $f);
$W = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$st = q("SELECT c.*, us.name owner_name, cr.name creator_name,
           (SELECT body FROM comments WHERE client_id = c.id ORDER BY id DESC LIMIT 1) last_comment,
           (SELECT COUNT(*) FROM comments WHERE client_id = c.id) comments_cnt
         FROM clients c
         LEFT JOIN users us ON us.id = c.assigned_to
         LEFT JOIN users cr ON cr.id = c.created_by
         $W ORDER BY c.id DESC", $params);

$cnt = 0;
$fname = 'clients_' . date('Y-m-d_H-i') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM — чтобы Excel открыл кириллицу правильно
$cols = ['ID', 'ФИО', 'Телефон', 'Email', 'Страна', 'Код страны', 'Статус', 'Ответственный', 'Источник', 'Описание',
         'Перезвон', 'Комментариев', 'Последний комментарий', 'Создан', 'Создал', 'Обновлён'];
fputcsv($out, $cols, ';');

// Защита от формул в Excel (=, +, -, @ в начале ячейки)
$safe = function ($v) {
    $v = (string)$v;
    return ($v !== '' && strpbrk($v[0], '=+-@') !== false && !preg_match('/^\+?\d[\d\s]*$/', $v)) ? "'" . $v : $v;
};
while ($c = $st->fetch()) {
    fputcsv($out, array_map($safe, [
        $c['id'], $c['full_name'], $c['phone'], $c['email'], $c['geo_name'], $c['geo_iso'],
        status_name($c['status_id'] ? (int)$c['status_id'] : null), $c['owner_name'] ?? '', $c['source'], $c['description'],
        $c['callback_at'] ? fmt_dt($c['callback_at']) : '', $c['comments_cnt'], $c['last_comment'],
        fmt_dt($c['created_at']), $c['creator_name'] ?? '', fmt_dt($c['updated_at']),
    ]), ';');
    $cnt++;
}
fclose($out);

$desc = array_filter($f, fn($v) => $v !== '');
activity('export', "Выгружено клиентов: $cnt" . ($desc ? '; фильтры: ' . json_encode($desc, JSON_UNESCAPED_UNICODE) : ''));
