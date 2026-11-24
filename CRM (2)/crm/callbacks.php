<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
[$vis, $vp] = visibility_sql($u);

// Чьи перезвоны показывать: менеджер — свои; остальные — можно выбрать
$who = $u['role'] === 'manager' ? (string)$u['id'] : (string)($_GET['who'] ?? (string)$u['id']);
$assignable = assignable_users($u);
$where = [$vis, 'c.callback_at IS NOT NULL'];
$params = $vp;
if ($who !== 'all') { $where[] = 'c.assigned_to = ?'; $params[] = (int)$who; }
$W = implode(' AND ', $where);

$rows = q("SELECT c.*, us.name owner_name FROM clients c LEFT JOIN users us ON us.id = c.assigned_to
           WHERE $W AND c.callback_at < CURDATE() + INTERVAL 8 DAY ORDER BY c.callback_at", $params)->fetchAll();
$later = (int)q("SELECT COUNT(*) FROM clients c WHERE $W AND c.callback_at >= CURDATE() + INTERVAL 8 DAY", $params)->fetchColumn();

$groups = ['due' => [], 'today' => [], 'tomorrow' => [], 'week' => []];
$now = time();
$todayEnd = strtotime('tomorrow');
$tomorrowEnd = strtotime('tomorrow +1 day');
foreach ($rows as $r) {
    $t = strtotime($r['callback_at']);
    if ($t <= $now) $groups['due'][] = $r;
    elseif ($t < $todayEnd) $groups['today'][] = $r;
    elseif ($t < $tomorrowEnd) $groups['tomorrow'][] = $r;
    else $groups['week'][] = $r;
}
$titles = ['due' => 'Просрочено / пора звонить', 'today' => 'Сегодня', 'tomorrow' => 'Завтра', 'week' => 'На неделе'];
$self = 'callbacks.php' . ($who !== (string)$u['id'] ? '?who=' . urlencode($who) : '');

layout_header($u, 'Перезвоны', 'callbacks');
?>
<h1 class="page-title">Перезвоны</h1>

<?php if ($u['role'] !== 'manager'): ?>
<form class="toolbar" method="get">
    <select name="who" onchange="this.form.submit()" style="width:auto;height:44px">
        <option value="<?= $u['id'] ?>" <?= $who === (string)$u['id'] ? 'selected' : '' ?>>Мои</option>
        <option value="all" <?= $who === 'all' ? 'selected' : '' ?>>Все доступные</option>
        <?php foreach ($assignable as $a): if ((int)$a['id'] === (int)$u['id']) continue; ?>
            <option value="<?= $a['id'] ?>" <?= $who === (string)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<?php foreach ($groups as $g => $list): ?>
    <div class="cb-group <?= $g ?>">
        <h3><?= $titles[$g] ?> <span class="muted"><?= count($list) ?></span></h3>
        <?php if (!$list): ?><p class="muted">Нет</p><?php endif; ?>
        <?php foreach ($list as $c): ?>
            <div class="cb-item">
                <span class="cb-time"><?= fmt_dt($c['callback_at'], $g === 'week' || $g === 'due' ? 'd.m H:i' : 'H:i') ?></span>
                <a href="client.php?id=<?= $c['id'] ?>" class="name-link"><?= e($c['full_name'] ?: 'Без имени') ?></a>
                <span class="flag"><?= flag($c['geo_iso']) ?></span>
                <span class="mono"><?= e($c['phone']) ?></span><?= copy_btn('+' . $c['phone_norm'], 'Копировать номер') ?>
                <?= status_badge($c['status_id'] ? (int)$c['status_id'] : null) ?>
                <?php if ($who !== (string)$u['id']): ?><span class="muted"><?= e($c['owner_name'] ?? '—') ?></span><?php endif; ?>
                <span class="cb-acts">
                    <a class="tbtn sm" href="tel:+<?= e($c['phone_norm']) ?>"><?= icon('phone', 16) ?> Позвонить</a>
                    <form method="post" action="client.php?id=<?= $c['id'] ?>" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="callback_done"><input type="hidden" name="back" value="<?= e($self) ?>">
                        <button class="tbtn sm">✓ Выполнен</button></form>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php if ($later): ?><p class="muted">И ещё <?= $later ?> перезвонов позже чем через неделю — <a href="index.php?cb=any&sort=callback&dir=asc">смотреть в списке</a>.</p><?php endif; ?>
<?php layout_footer();
