<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim((string)($_POST['name'] ?? ''));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#3b82f6';

    if ($action === 'create' && $name !== '') {
        $max = (int)q('SELECT COALESCE(MAX(sort_order), 0) FROM statuses')->fetchColumn();
        q('INSERT INTO statuses (name, color, sort_order) VALUES (?,?,?)', [mb_substr($name, 0, 60), $color, $max + 10]);
        flash('Статус добавлен');
    }
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        if ($name !== '') q('UPDATE statuses SET name = ?, color = ? WHERE id = ?', [mb_substr($name, 0, 60), $color, $id]);
        flash('Сохранено');
    }
    if ($action === 'default') {
        q('UPDATE statuses SET is_default = (id = ?)', [(int)$_POST['id']]);
        flash('Статус по умолчанию изменён');
    }
    if ($action === 'move') {
        $list = array_keys(statuses());
        $id = (int)$_POST['id'];
        $i = array_search($id, $list, true);
        $j = $i + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        if ($i !== false && isset($list[$j])) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            foreach ($list as $pos => $sid) q('UPDATE statuses SET sort_order = ? WHERE id = ?', [$pos * 10, $sid]);
        }
    }
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $to = ($_POST['move_to'] ?? '') !== '' ? (int)$_POST['move_to'] : null;
        if ($to === $id) $to = null;
        q('UPDATE clients SET status_id = ? WHERE status_id = ?', [$to, $id]);
        q('DELETE FROM statuses WHERE id = ?', [$id]);
        flash('Статус удалён');
    }
    activity('statuses', $action . ($name !== '' ? ': ' . $name : ''));
    redirect('statuses.php');
}

$ST = statuses();
$cnt = q('SELECT status_id, COUNT(*) FROM clients GROUP BY status_id')->fetchAll(PDO::FETCH_KEY_PAIR);

layout_header($u, 'Статусы', 'statuses');
?>
<h1 class="page-title">Статусы клиентов</h1>
<p class="muted">Категории, которые сотрудники ставят каждому клиенту. Цвет и название меняются сразу у всех клиентов.</p>

<form method="post" class="panel new-status">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <b>Новый статус</b>
    <input type="color" name="color" value="#a855f7" class="color">
    <input name="name" required maxlength="60" placeholder="Название, напр. Горячий" class="sm-input">
    <button class="tbtn primary sm">Добавить</button>
</form>
<div class="panel p0 table-wrap">
    <table class="tbl">
        <thead><tr><th style="width:70px">Порядок</th><th>Статус</th><th>Клиентов</th><th>Изменить</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ST as $id => $s): ?>
            <tr>
                <td class="nowrap">
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $id ?>">
                        <button class="icon-btn sm" name="dir" value="up" title="Выше">▲</button><button class="icon-btn sm" name="dir" value="down" title="Ниже">▼</button></form>
                </td>
                <td class="nowrap"><?= status_badge($id) ?> <?php if ($s['is_default']): ?><span class="tag">по умолчанию</span><?php endif; ?></td>
                <td><a href="index.php?status=<?= $id ?>"><?= (int)($cnt[$id] ?? 0) ?></a></td>
                <td>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
                        <input type="color" name="color" value="<?= e($s['color']) ?>" class="color">
                        <input name="name" value="<?= e($s['name']) ?>" class="sm-input">
                        <button class="tbtn sm">Сохранить</button></form>
                </td>
                <td class="nowrap">
                    <?php if (!$s['is_default']): ?>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="id" value="<?= $id ?>">
                            <button class="tbtn sm ghost" title="Новые клиенты получают этот статус">По умолч.</button></form>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
                            <select name="move_to" class="sm-input" title="Куда перевести клиентов с этим статусом">
                                <option value="">клиентов → без статуса</option>
                                <?php foreach ($ST as $oid => $o): if ($oid === $id) continue; ?><option value="<?= $oid ?>">клиентов → <?= e($o['name']) ?></option><?php endforeach; ?>
                            </select>
                            <button class="icon-btn sm danger" data-confirm="Удалить статус «<?= e($s['name']) ?>»?" title="Удалить"><?= icon('trash', 16) ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php layout_footer();
