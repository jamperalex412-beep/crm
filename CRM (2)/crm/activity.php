<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['admin']);

$f = [
    'user'   => (string)($_GET['user'] ?? ''),
    'action' => (string)($_GET['action'] ?? ''),
    'from'   => (string)($_GET['from'] ?? ''),
    'to'     => (string)($_GET['to'] ?? ''),
    'client' => (string)($_GET['client'] ?? ''),
];
$where = [];
$params = [];
if ($f['user'] !== '') { $where[] = 'a.user_id = ?'; $params[] = (int)$f['user']; }
if (isset(ACTIVITY_NAMES[$f['action']])) { $where[] = 'a.action = ?'; $params[] = $f['action']; }
if ($f['from'] !== '' && strtotime($f['from'])) { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($f['from'])); }
if ($f['to'] !== '' && strtotime($f['to'])) { $where[] = 'a.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($f['to'])); }
if ($f['client'] !== '') { $where[] = 'a.client_id = ?'; $params[] = (int)$f['client']; }
$W = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)q("SELECT COUNT(*) FROM activity_log a $W", $params)->fetchColumn();
$per = 100;
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$rows = q("SELECT a.*, us.name, us.role, c.full_name client_name FROM activity_log a
           LEFT JOIN users us ON us.id = a.user_id LEFT JOIN clients c ON c.id = a.client_id
           $W ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();
$users = q('SELECT id, name, role FROM users ORDER BY name')->fetchAll();

// Автоочистка: храним 180 дней
if (random_int(1, 50) === 1) q('DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL 180 DAY');

$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge($_GET, $o), fn($v) => $v !== '' && $v !== null));
$warn = ['login_fail', 'delete', 'export', 'user'];

layout_header($u, 'Журнал действий', 'activity');
?>
<h1 class="page-title">Журнал действий <span class="muted small"><?= $total ?></span></h1>

<form class="filters open" method="get">
    <select name="user">
        <option value="">Все сотрудники</option>
        <?php foreach ($users as $x): ?><option value="<?= $x['id'] ?>" <?= $f['user'] === (string)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?> (<?= e(role_name($x['role'])) ?>)</option><?php endforeach; ?>
    </select>
    <select name="action">
        <option value="">Все действия</option>
        <?php foreach (ACTIVITY_NAMES as $k => $v): ?><option value="<?= $k ?>" <?= $f['action'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
    <label class="date-f">с <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="date-f">по <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <input name="client" placeholder="ID клиента" value="<?= e($f['client']) ?>" style="max-width:140px">
    <button class="tbtn primary">Показать</button>
    <?php if (array_filter($f, fn($v) => $v !== '')): ?><a class="tbtn ghost" href="activity.php">Сбросить</a><?php endif; ?>
</form>

<div class="grid-card">
<div class="table-wrap" style="min-height:0">
<table class="tbl">
    <thead><tr><th>Время</th><th>Сотрудник</th><th>Действие</th><th>Клиент</th><th>Подробности</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="nowrap"><?= fmt_dt($r['created_at'], 'd.m.Y H:i:s') ?></td>
            <td class="nowrap"><?php if ($r['user_id']): ?><a href="<?= e($qs(['user' => $r['user_id'], 'page' => ''])) ?>"><?= e($r['name'] ?? 'удалён #' . $r['user_id']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><span class="tag <?= in_array($r['action'], $warn, true) ? 'tag-warn' : '' ?>"><?= e(ACTIVITY_NAMES[$r['action']] ?? $r['action']) ?></span></td>
            <td class="nowrap"><?php if ($r['client_id']): ?>
                <?php if ($r['client_name'] !== null): ?><a href="client.php?id=<?= $r['client_id'] ?>" class="name-link"><?= e($r['client_name'] ?: 'Без имени') ?></a><?php else: ?><span class="muted">#<?= $r['client_id'] ?> (удалён)</span><?php endif; ?>
            <?php endif; ?></td>
            <td class="clip" style="max-width:520px" title="<?= e($r['details']) ?>"><?= e($r['details']) ?></td>
            <td class="muted mono small"><?= e($r['ip']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Записей нет</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<div class="tfoot"><span class="muted">Стр. <?= $page ?> из <?= $pages ?></span>
    <span class="pg">
        <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= e($qs(['page' => max(1, $page - 1)])) ?>">‹</a>
        <a class="<?= $page >= $pages ? 'dis' : '' ?>" href="<?= e($qs(['page' => min($pages, $page + 1)])) ?>">›</a>
    </span></div>
<?php endif; ?>
</div>
<?php layout_footer();
