<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$owners = visible_owner_ids($u);
$assignable = assignable_users($u);
$ST = statuses();

/* ---------- Массовые действия ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $action = $_POST['action'] ?? '';
    $n = 0;
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = q("SELECT * FROM clients WHERE id IN ($in)", $ids)->fetchAll();
        if ($action === 'assign' && $assignable) {
            $to = (int)($_POST['to'] ?? 0);
            if (can_assign_to($u, $to)) {
                foreach ($rows as $c) {
                    if (can_view_client($u, $c) && assign_client($c, $to, $u, false)) $n++;
                }
                if ($n && $to !== (int)$u['id']) notify($to, $u['name'] . " передал вам клиентов: $n");
                flash("Передано клиентов: $n");
            } else {
                flash('Выберите, кому передать', 'err');
            }
        } elseif ($action === 'status') {
            $sid = (int)($_POST['status_id'] ?? 0);
            foreach ($rows as $c) if (can_view_client($u, $c) && set_client_status($c, $sid, (int)$u['id'])) $n++;
            flash("Статус «" . status_name($sid) . "» поставлен: $n");
        } elseif ($action === 'delete' && $u['role'] === 'admin') {
            activity('delete', 'Массовое удаление: ' . count($ids) . ' (ID ' . implode(', ', array_slice($ids, 0, 50)) . ')');
            q("DELETE FROM clients WHERE id IN ($in)", $ids);
            flash('Удалено клиентов: ' . count($ids));
        }
    }
    redirect('index.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

/* ---------- Фильтры ---------- */
$f = filters_from_get();
[$where, $params, $whereNoStatus, $paramsNoStatus] = client_filters($u, $f);

$W = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$Wns = $whereNoStatus ? 'WHERE ' . implode(' AND ', $whereNoStatus) : '';

$counts = q("SELECT COALESCE(c.status_id, 0), COUNT(*) FROM clients c $Wns GROUP BY c.status_id", $paramsNoStatus)->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll = array_sum($counts);

$total = (int)q("SELECT COUNT(*) FROM clients c $W", $params)->fetchColumn();
$per = in_array((int)($_GET['per'] ?? 0), [25, 50, 100, 200], true) ? (int)$_GET['per'] : (int)($CONFIG['per_page'] ?? 50);
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$off = ($page - 1) * $per;

$sorts = ['callback' => 'c.callback_at IS NULL, c.callback_at', 'id' => 'c.id', 'name' => 'c.full_name', 'geo' => 'c.geo_name', 'status' => 'c.status_id', 'created' => 'c.created_at', 'updated' => 'c.updated_at'];
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'id';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$clients = q("SELECT c.*, u.name AS owner_name,
                (SELECT body FROM comments WHERE client_id = c.id ORDER BY id DESC LIMIT 1) AS last_comment
              FROM clients c LEFT JOIN users u ON u.id = c.assigned_to
              $W ORDER BY {$sorts[$sort]} $dir, c.id DESC LIMIT $per OFFSET $off", $params)->fetchAll();

$geoSql = 'SELECT DISTINCT c.geo_iso, c.geo_name FROM clients c WHERE c.geo_iso IS NOT NULL';
if ($owners !== null) $geoSql .= ' AND c.assigned_to IN (' . implode(',', array_fill(0, count($owners), '?')) . ')';
$geos = q($geoSql . ' ORDER BY c.geo_name', $owners ?? [])->fetchAll();

function qs(array $over): string
{
    return '?' . http_build_query(array_filter(array_merge($_GET, $over), fn($v) => $v !== '' && $v !== null));
}
function th_sort(string $key, string $label): string
{
    $cur = $_GET['sort'] ?? 'id';
    $dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $next = ($cur === $key && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = $cur === $key ? ($dir === 'asc' ? '▲' : '▼') : '⇅';
    return '<a class="th-sort' . ($cur === $key ? ' on' : '') . '" href="' . qs(['sort' => $key, 'dir' => $next, 'page' => '']) . '">' . e($label) . ' <i>' . $arrow . '</i></a>';
}
$filtersOn = $f['geo'] !== '' || $f['owner'] !== '' || $f['q'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['cb'] !== '';
$filtersCnt = ($f['geo'] !== '') + ($f['owner'] !== '') + ($f['q'] !== '') + ($f['from'] !== '' || $f['to'] !== '') + ($f['cb'] !== '');

layout_header($u, 'Клиенты', 'clients');
?>
<h1 class="page-title">Клиенты</h1>

<div class="toolbar">
    <button type="button" class="tbtn <?= $filtersOn ? 'on' : '' ?>" onclick="document.getElementById('filters').classList.toggle('open')">
        <?= icon('filter', 18) ?> Фильтры <?php if ($filtersOn): ?><span class="cnt"><?= $filtersCnt ?></span><a href="index.php" class="x" title="Сбросить">✕</a><?php endif; ?></button>
    <a href="<?= e(qs([])) ?>" class="tbtn icon" title="Обновить"><?= icon('refresh', 18) ?></a>
    <?php if (sees_all($u)): ?><a href="export.php<?= e(qs(['page' => '', 'per' => ''])) ?>" class="tbtn" title="Выгрузить текущую выборку"><?= icon('import', 18) ?> Экспорт <span class="cnt"><?= $total ?></span></a><?php endif; ?>
    <a href="client.php?new=1" class="tbtn primary"><?= icon('add', 18) ?> Новый клиент</a>
</div>

<form id="filters" class="filters <?= $filtersOn ? 'open' : '' ?>" method="get">
    <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
    <input name="q" placeholder="ФИО, телефон, email, ID" value="<?= e($f['q']) ?>">
    <select name="geo">
        <option value="">Все страны</option>
        <?php foreach ($geos as $g): ?>
            <option value="<?= e($g['geo_iso']) ?>" <?= $f['geo'] === $g['geo_iso'] ? 'selected' : '' ?>><?= flag($g['geo_iso']) . ' ' . e($g['geo_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($u['role'] !== 'manager'): ?>
        <select name="owner">
            <option value="">Все ответственные</option>
            <?php if (sees_all($u)): ?><option value="none" <?= $f['owner'] === 'none' ? 'selected' : '' ?>>— Не назначен —</option><?php endif; ?>
            <?php foreach ($assignable as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $f['owner'] === (string)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e(role_name($a['role'])) ?>)</option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <select name="cb">
        <option value="">Перезвоны: все</option>
        <option value="due" <?= $f['cb'] === 'due' ? 'selected' : '' ?>>Пора звонить</option>
        <option value="today" <?= $f['cb'] === 'today' ? 'selected' : '' ?>>Перезвон сегодня</option>
        <option value="any" <?= $f['cb'] === 'any' ? 'selected' : '' ?>>С перезвоном</option>
    </select>
    <label class="date-f">Создан с <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="date-f">по <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <button class="tbtn primary">Применить</button>
</form>

<div class="chips">
    <a href="<?= qs(['status' => '', 'page' => '']) ?>" class="chip <?= $f['status'] === '' ? 'on' : '' ?>">ВСЕ <b><?= $totalAll ?></b></a>
    <?php foreach ($ST as $id => $s): ?>
        <a href="<?= qs(['status' => $id, 'page' => '']) ?>" class="chip <?= $f['status'] === (string)$id ? 'on' : '' ?>" style="--c:<?= e($s['color']) ?>">
            <?= e(mb_strtoupper($s['name'])) ?> <b><?= (int)($counts[$id] ?? 0) ?></b></a>
    <?php endforeach; ?>
    <?php if (!empty($counts[0])): ?>
        <a href="<?= qs(['status' => 'none', 'page' => '']) ?>" class="chip <?= $f['status'] === 'none' ? 'on' : '' ?>">БЕЗ СТАТУСА <b><?= (int)$counts[0] ?></b></a>
    <?php endif; ?>
</div>

<form method="post" id="bulk">
    <?= csrf_field() ?>
    <div class="bulkbar" hidden>
        <span>Выбрано: <b id="selCount">0</b></span>
        <?php if ($assignable): ?>
            <select name="to">
                <option value="">Передать сотруднику…</option>
                <?php foreach ($assignable as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?> (<?= e(role_name($a['role'])) ?>)</option><?php endforeach; ?>
            </select>
            <button class="tbtn" name="action" value="assign">Передать</button>
        <?php endif; ?>
        <?= status_picker(default_status_id(), 'status_id') ?>
        <button class="tbtn" name="action" value="status">Поставить статус</button>
        <?php if ($u['role'] === 'admin'): ?>
            <button class="tbtn danger" name="action" value="delete" data-confirm="Удалить выбранных клиентов безвозвратно?"><?= icon('trash', 16) ?> Удалить</button>
        <?php endif; ?>
    </div>

    <div class="grid-card">
    <div class="table-wrap">
    <table class="tbl">
        <thead><tr>
            <th class="w-chk"><input type="checkbox" id="chkAll"></th>
            <th><?= th_sort('id', 'ID') ?></th>
            <th>Действия</th>
            <th><?= th_sort('name', 'ФИО') ?></th>
            <th>Телефон</th>
            <th>Email</th>
            <th><?= th_sort('geo', 'Страна') ?></th>
            <th><?= th_sort('status', 'Статус') ?></th>
            <?php if ($u['role'] !== 'manager'): ?><th>Ответственный</th><?php endif; ?>
            <th><?= th_sort('callback', 'Перезвон') ?></th>
            <th>Последний комментарий</th>
            <th><?= th_sort('created', 'Создан') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$clients): ?><tr><td colspan="12" class="empty">Клиентов не найдено</td></tr><?php endif; ?>
        <?php foreach ($clients as $c): $ml = messenger_links($c['phone_norm']); ?>
            <tr>
                <td class="w-chk"><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="chk"></td>
                <td class="nowrap"><?= copy_btn((string)$c['id'], 'Копировать ID') ?> <?= $c['id'] ?></td>
                <td class="nowrap acts">
                    <a href="tel:+<?= e($c['phone_norm']) ?>" title="Позвонить"><?= icon('phone', 18) ?></a>
                    <a href="<?= e($ml['WhatsApp']) ?>" target="_blank" rel="noopener" title="WhatsApp"><?= icon('chat', 18) ?></a>
                    <a href="<?= e($ml['Telegram']) ?>" target="_blank" rel="noopener" title="Telegram"><?= icon('send', 18) ?></a>
                    <?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>" title="Email"><?= icon('mail', 18) ?></a><?php endif; ?>
                    <a href="client.php?id=<?= $c['id'] ?>" target="_blank" title="Открыть в новой вкладке"><?= icon('open', 18) ?></a>
                </td>
                <td class="nowrap"><a href="client.php?id=<?= $c['id'] ?>" class="name-link"><?= e($c['full_name'] ?: 'Без имени') ?></a></td>
                <td class="nowrap"><?= copy_btn('+' . $c['phone_norm'], 'Копировать номер') ?> <span class="mono"><?= e(phone_pretty($c['phone'])) ?></span></td>
                <td class="nowrap"><?php if ($c['email']): ?><?= copy_btn($c['email'], 'Копировать email') ?> <?= e($c['email']) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="nowrap"><span class="flag"><?= flag($c['geo_iso']) ?></span> <?= e($c['geo_name'] ?: '—') ?></td>
                <td><?= status_picker($c['status_id'] ? (int)$c['status_id'] : null, 's', (int)$c['id']) ?></td>
                <?php if ($u['role'] !== 'manager'): ?><td class="nowrap"><?= e($c['owner_name'] ?? '—') ?></td><?php endif; ?>
                <td class="nowrap"><?php if ($c['callback_at']): $due = strtotime($c['callback_at']) <= time(); ?><span class="cb-chip sm <?= $due ? 'due' : '' ?>">⏰ <?= fmt_dt($c['callback_at'], 'd.m H:i') ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="muted clip" title="<?= e((string)$c['last_comment']) ?>"><?= e(mb_strimwidth((string)$c['last_comment'], 0, 60, '…')) ?></td>
                <td class="nowrap"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="tfoot">
        <span class="muted">Строк на странице:</span>
        <select onchange="location.href='<?= e(qs(['per' => '', 'page' => ''])) ?>&per='+this.value">
            <?php foreach ([25, 50, 100, 200] as $n): ?><option <?= $n === $per ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
        </select>
        <span class="muted"><?= $total ? ($off + 1) . '–' . min($off + $per, $total) : 0 ?> из <?= $total ?></span>
        <span class="muted">Стр.: <b class="txt"><?= $page ?></b></span>
        <span class="pg">
            <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= qs(['page' => 1]) ?>">|‹</a>
            <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= qs(['page' => max(1, $page - 1)]) ?>">‹</a>
            <a class="<?= $page >= $pages ? 'dis' : '' ?>" href="<?= qs(['page' => min($pages, $page + 1)]) ?>">›</a>
            <a class="<?= $page >= $pages ? 'dis' : '' ?>" href="<?= qs(['page' => $pages]) ?>">›|</a>
        </span>
    </div>
    </div>
</form>
<?php layout_footer();
