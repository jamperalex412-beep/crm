<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['admin', 'shift']);

$users = q("SELECT us.*, (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = us.id) cnt
            FROM users us WHERE us.role IN ('shift','teamlead','manager') ORDER BY us.name")->fetchAll();
$unassigned = (int)q('SELECT COUNT(*) FROM clients WHERE assigned_to IS NULL')->fetchColumn();

$leads = array_filter($users, fn($x) => $x['role'] === 'teamlead');
$shifts = array_filter($users, fn($x) => $x['role'] === 'shift');
$freeManagers = array_filter($users, fn($x) => $x['role'] === 'manager' && !$x['team_lead_id']);

layout_header($u, 'Команды', 'teams');
?>
<div class="page-head"><h1>Структура и команды</h1>
    <?php if ($unassigned): ?><a href="index.php?owner=none" class="btn">Не назначено: <?= $unassigned ?></a><?php endif; ?>
</div>

<?php if ($shifts): ?>
<div class="card"><h3>Шифты</h3>
    <div class="people"><?php foreach ($shifts as $s): ?>
        <a class="person" href="index.php?owner=<?= $s['id'] ?>"><b><?= e($s['name']) ?></b><small><?= (int)$s['cnt'] ?> клиентов</small></a>
    <?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="teams">
<?php foreach ($leads as $tl):
    $members = array_filter($users, fn($x) => (int)$x['team_lead_id'] === (int)$tl['id']);
    $sum = (int)$tl['cnt'] + array_sum(array_column($members, 'cnt')); ?>
    <div class="card team">
        <div class="team-head">
            <div><span class="role r-teamlead">Тимлидер</span><h3><?= e($tl['name']) ?></h3></div>
            <div class="big-num"><?= $sum ?><small>клиентов в команде</small></div>
        </div>
        <a class="person lead" href="index.php?owner=<?= $tl['id'] ?>"><b><?= e($tl['name']) ?></b><small><?= (int)$tl['cnt'] ?> лично</small></a>
        <div class="people">
            <?php foreach ($members as $m): ?>
                <a class="person <?= $m['is_active'] ? '' : 'disabled' ?>" href="index.php?owner=<?= $m['id'] ?>"><b><?= e($m['name']) ?></b><small><?= (int)$m['cnt'] ?> клиентов</small></a>
            <?php endforeach; ?>
            <?php if (!$members): ?><p class="muted small">Нет менеджеров</p><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$leads): ?><div class="card"><p class="muted">Тимлидеров пока нет. Создайте пользователя с ролью «Тимлидер» в разделе «Пользователи».</p></div><?php endif; ?>
</div>

<?php if ($freeManagers): ?>
<div class="card"><h3>Менеджеры без команды</h3>
    <div class="people"><?php foreach ($freeManagers as $m): ?>
        <a class="person" href="index.php?owner=<?= $m['id'] ?>"><b><?= e($m['name']) ?></b><small><?= (int)$m['cnt'] ?> клиентов</small></a>
    <?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php layout_footer();
