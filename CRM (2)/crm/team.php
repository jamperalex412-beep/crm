<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['teamlead']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = (int)($_POST['manager_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'add') {
        $ok = q("UPDATE users SET team_lead_id = ? WHERE id = ? AND role = 'manager' AND team_lead_id IS NULL AND is_active = 1",
            [$u['id'], $mid])->rowCount();
        if ($ok) activity('team', 'Добавил в команду: ' . user_name($mid));
        flash($ok ? 'Менеджер добавлен в команду: ' . user_name($mid) : 'Этого менеджера нельзя добавить', $ok ? 'ok' : 'err');
    }
    if (($_POST['action'] ?? '') === 'remove') {
        $ok = q('UPDATE users SET team_lead_id = NULL WHERE id = ? AND team_lead_id = ?', [$mid, $u['id']])->rowCount();
        if ($ok && !empty($_POST['take_clients'])) {
            $ids = q('SELECT id FROM clients WHERE assigned_to = ?', [$mid])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $cid) log_history((int)$cid, (int)$u['id'], 'assign', user_name($mid) . ' → ' . $u['name']);
            q('UPDATE clients SET assigned_to = ? WHERE assigned_to = ?', [$u['id'], $mid]);
        }
        if ($ok) activity('team', 'Убрал из команды: ' . user_name($mid));
        flash($ok ? 'Менеджер убран из команды' : 'Ошибка', $ok ? 'ok' : 'err');
    }
    redirect('team.php');
}

$members = q("SELECT us.*, (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = us.id) cnt
              FROM users us WHERE us.team_lead_id = ? ORDER BY us.name", [$u['id']])->fetchAll();
$free = q("SELECT id, name FROM users WHERE role = 'manager' AND team_lead_id IS NULL AND is_active = 1 ORDER BY name")->fetchAll();

// Статистика по статусам для каждого участника + себя
$ids = array_merge([(int)$u['id']], array_map(fn($m) => (int)$m['id'], $members));
$in = implode(',', array_fill(0, count($ids), '?'));
$stat = [];
foreach (q("SELECT assigned_to, COALESCE(status_id,0) status, COUNT(*) n FROM clients WHERE assigned_to IN ($in) GROUP BY assigned_to, status_id", $ids) as $r) {
    $stat[$r['assigned_to']][$r['status']] = (int)$r['n'];
}

layout_header($u, 'Моя команда', 'team');
?>
<h1 class="page-title">Моя команда <span class="muted">(<?= count($members) ?>)</span></h1>

<div class="grid-2 wide-left">
<div class="col">
    <div class="table-wrap card p0">
    <table class="tbl">
        <thead><tr><th>Сотрудник</th><th>Всего</th>
            <?php foreach (statuses() as $sid => $s): ?><th><?= status_badge($sid) ?></th><?php endforeach; ?>
            <th></th></tr></thead>
        <tbody>
        <tr class="me-row">
            <td class="strong"><?= e($u['name']) ?> <span class="tag">вы</span></td>
            <td><a href="index.php?owner=<?= $u['id'] ?>"><?= array_sum($stat[$u['id']] ?? []) ?></a></td>
            <?php foreach (statuses() as $k => $_): ?><td><?= $stat[$u['id']][$k] ?? 0 ?></td><?php endforeach; ?>
            <td></td>
        </tr>
        <?php foreach ($members as $m): ?>
            <tr class="<?= $m['is_active'] ? '' : 'disabled' ?>">
                <td class="strong"><?= e($m['name']) ?><?= $m['is_active'] ? '' : ' <span class="tag">заблокирован</span>' ?></td>
                <td><a href="index.php?owner=<?= $m['id'] ?>"><?= (int)$m['cnt'] ?></a></td>
                <?php foreach (statuses() as $k => $_): ?><td><?= $stat[$m['id']][$k] ?? 0 ?></td><?php endforeach; ?>
                <td class="nowrap">
                    <form method="post" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove"><input type="hidden" name="manager_id" value="<?= $m['id'] ?>">
                        <label class="small muted"><input type="checkbox" name="take_clients" value="1" checked> забрать клиентов</label>
                        <button class="btn sm ghost" data-confirm="Убрать <?= e($m['name']) ?> из команды?">Убрать</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?><tr><td colspan="99" class="empty">В команде пока никого — добавьте менеджеров справа</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<div class="col">
    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <h3>Добавить менеджера в команду</h3>
        <?php if ($free): ?>
            <select name="manager_id" required>
                <?php foreach ($free as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn primary">Добавить</button>
        <?php else: ?>
            <p class="muted">Свободных менеджеров нет. Попросите администратора создать аккаунт.</p>
        <?php endif; ?>
    </form>
    <div class="card"><h3>Как передать лидов</h3>
        <p class="muted">На странице «Клиенты» отметьте галочками нужных клиентов → «Передать…» → выберите менеджера из своей команды.</p></div>
</div>
</div>
<?php layout_footer();
