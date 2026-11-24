<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
[$vis, $vp] = visibility_sql($u);
$ST = statuses();

$periods = ['today' => 'Сегодня', '7' => '7 дней', '30' => '30 дней', '90' => '90 дней'];
$per = isset($periods[$_GET['p'] ?? '']) ? $_GET['p'] : '7';
$from = $per === 'today' ? date('Y-m-d 00:00:00') : date('Y-m-d 00:00:00', strtotime('-' . ((int)$per - 1) . ' days'));
$days = $per === 'today' ? 14 : max(14, min(90, (int)$per));

$one = fn(string $sql, array $p = []) => (int)q($sql, $p)->fetchColumn();

// --- Плитки ---
$total   = $one("SELECT COUNT(*) FROM clients c WHERE $vis", $vp);
$newP    = $one("SELECT COUNT(*) FROM clients c WHERE $vis AND c.created_at >= ?", [...$vp, $from]);
$dueCb   = $one("SELECT COUNT(*) FROM clients c WHERE $vis AND c.callback_at IS NOT NULL AND c.callback_at <= NOW()", $vp);
$todayCb = $one("SELECT COUNT(*) FROM clients c WHERE $vis AND c.callback_at > NOW() AND c.callback_at < CURDATE() + INTERVAL 1 DAY", $vp);
$cmP     = $one("SELECT COUNT(*) FROM comments cm JOIN clients c ON c.id = cm.client_id WHERE $vis AND cm.created_at >= ?", [...$vp, $from]);
$noOwner = sees_all($u) ? $one('SELECT COUNT(*) FROM clients WHERE assigned_to IS NULL') : 0;

// --- Новые клиенты по дням ---
$byDay = q("SELECT DATE(c.created_at) d, COUNT(*) n FROM clients c WHERE $vis AND c.created_at >= CURDATE() - INTERVAL " . ($days - 1) . " DAY GROUP BY d",
    $vp)->fetchAll(PDO::FETCH_KEY_PAIR);
$series = [];
for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $series[$d] = (int)($byDay[$d] ?? 0); }
$maxDay = max(1, max($series));

// --- По статусам ---
$byStatus = q("SELECT COALESCE(c.status_id,0), COUNT(*) FROM clients c WHERE $vis GROUP BY c.status_id", $vp)->fetchAll(PDO::FETCH_KEY_PAIR);
$maxSt = max(1, $byStatus ? max($byStatus) : 1);

// --- Страны ---
$geos = q("SELECT c.geo_iso, c.geo_name, COUNT(*) n FROM clients c WHERE $vis GROUP BY c.geo_iso, c.geo_name ORDER BY n DESC LIMIT 10", $vp)->fetchAll();
$maxGeo = max(1, $geos ? (int)$geos[0]['n'] : 1);

// --- Сотрудники ---
$staff = [];
if ($u['role'] !== 'manager') {
    $ids = visible_owner_ids($u);
    $staffSql = "SELECT us.id, us.name, us.role, us.last_login, us.is_active,
          (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = us.id) total,
          (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = us.id AND c.callback_at IS NOT NULL AND c.callback_at <= NOW()) due,
          (SELECT COUNT(*) FROM comments cm WHERE cm.user_id = us.id AND cm.created_at >= ?) cms,
          (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = us.id AND a.action = 'status' AND a.created_at >= ?) sts,
          (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = us.id AND a.action = 'view' AND a.created_at >= ?) views
        FROM users us WHERE us.role <> 'admin'";
    $sp = [$from, $from, $from];
    if ($ids !== null) { $staffSql .= ' AND us.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($sp, ...$ids); }
    $staff = q($staffSql . ' ORDER BY us.is_active DESC, total DESC, us.name', $sp)->fetchAll();
    // статусы по сотрудникам
    $stBy = [];
    foreach (q("SELECT assigned_to, COALESCE(status_id,0) s, COUNT(*) n FROM clients c WHERE $vis AND assigned_to IS NOT NULL GROUP BY assigned_to, status_id", $vp) as $r) {
        $stBy[$r['assigned_to']][$r['s']] = (int)$r['n'];
    }
}

function tile(string $label, $value, string $hint = '', string $href = '', string $cls = ''): string
{
    $tag = $href ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="tile ' . $cls . '"><small>' . e($label) . '</small><b>' . e((string)$value) . '</b>'
        . ($hint ? '<span class="muted small">' . e($hint) . '</span>' : '') . '</' . ($href ? 'a' : 'div') . '>';
}
$ruMonth = fn($d) => date('d.m', strtotime($d));

layout_header($u, 'Дашборд', 'dashboard');
?>
<div class="page-head">
    <h1 class="page-title" style="margin:0">Дашборд <?php if ($u['role'] === 'teamlead'): ?><span class="muted small">моя команда</span><?php elseif ($u['role'] === 'manager'): ?><span class="muted small">мои клиенты</span><?php endif; ?></h1>
    <div class="seg">
        <?php foreach ($periods as $k => $label): ?><a href="?p=<?= $k ?>" class="<?= $k === $per ? 'on' : '' ?>"><?= $label ?></a><?php endforeach; ?>
    </div>
</div>

<div class="tiles">
    <?= tile('Всего клиентов', $total, '', 'index.php') ?>
    <?= tile('Новых · ' . mb_strtolower($periods[$per]), $newP, '', 'index.php?from=' . substr($from, 0, 10)) ?>
    <?= tile('Пора перезвонить', $dueCb, $todayCb ? "ещё $todayCb сегодня" : 'на сегодня больше нет', 'callbacks.php' . ($u['role'] !== 'manager' ? '?who=all' : ''), $dueCb ? 'warn' : '') ?>
    <?= tile('Комментариев · ' . mb_strtolower($periods[$per]), $cmP) ?>
    <?php if (sees_all($u)): ?><?= tile('Без ответственного', $noOwner, '', 'index.php?owner=none', $noOwner ? 'warn' : '') ?><?php endif; ?>
</div>

<div class="dash-grid">
    <div class="panel">
        <div class="panel-head"><h3>Новые клиенты по дням</h3><span class="muted small">последние <?= $days ?> дн.</span></div>
        <div class="vbars" style="--n:<?= count($series) ?>">
            <?php foreach ($series as $d => $n): ?>
                <div class="vb" data-tip="<?= $ruMonth($d) ?>: <?= $n ?>">
                    <i style="height:<?= $n ? max(3, round($n / $maxDay * 100)) : 0 ?>%"></i>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="vb-axis"><span><?= $ruMonth(array_key_first($series)) ?></span><span>max <?= $maxDay ?> / день</span><span><?= $ruMonth(array_key_last($series)) ?></span></div>
    </div>

    <div class="panel">
        <div class="panel-head"><h3>По статусам</h3><span class="muted small">все клиенты</span></div>
        <div class="hbars">
            <?php foreach ($ST as $sid => $s): $n = (int)($byStatus[$sid] ?? 0); ?>
                <a class="hb" href="index.php?status=<?= $sid ?>" data-tip="<?= e($s['name']) ?>: <?= $n ?> (<?= $total ? round($n / $total * 100) : 0 ?>%)">
                    <span class="hb-l"><?= status_badge($sid) ?></span>
                    <span class="hb-t"><i style="width:<?= $n ? max(1, round($n / $maxSt * 100)) : 0 ?>%;background:<?= e($s['color']) ?>"></i></span>
                    <span class="hb-v"><?= $n ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!empty($byStatus[0])): ?>
                <div class="hb"><span class="hb-l"><?= status_badge(null) ?></span><span class="hb-t"><i style="width:<?= round($byStatus[0] / $maxSt * 100) ?>%;background:#64748b"></i></span><span class="hb-v"><?= (int)$byStatus[0] ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><h3>Страны</h3><span class="muted small">топ-10</span></div>
        <div class="hbars">
            <?php foreach ($geos as $g): ?>
                <a class="hb" href="index.php?geo=<?= e((string)$g['geo_iso']) ?>" data-tip="<?= e($g['geo_name'] ?: 'Не определено') ?>: <?= (int)$g['n'] ?>">
                    <span class="hb-l"><span class="flag"><?= flag($g['geo_iso']) ?></span> <?= e($g['geo_name'] ?: 'Не определено') ?></span>
                    <span class="hb-t"><i style="width:<?= max(1, round($g['n'] / $maxGeo * 100)) ?>%"></i></span>
                    <span class="hb-v"><?= (int)$g['n'] ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!$geos): ?><p class="muted">Нет данных</p><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($staff): ?>
<div class="panel p0">
    <div class="panel-head pad"><h3>Сотрудники</h3><span class="muted small">активность · <?= mb_strtolower($periods[$per]) ?></span></div>
    <div class="table-wrap" style="min-height:0">
    <table class="tbl">
        <thead><tr><th>Сотрудник</th><th>Клиентов</th>
            <?php foreach ($ST as $sid => $s): ?><th title="<?= e($s['name']) ?>"><span class="dot" style="background:<?= e($s['color']) ?>"></span><?= e(mb_strimwidth($s['name'], 0, 12, '…')) ?></th><?php endforeach; ?>
            <th>Пора звонить</th><th>Открыто карточек</th><th>Смен статуса</th><th>Комментариев</th><th>Последний вход</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $m): ?>
            <tr class="<?= $m['is_active'] ? '' : 'disabled' ?>">
                <td class="nowrap"><a href="index.php?owner=<?= $m['id'] ?>" class="name-link"><?= e($m['name']) ?></a> <span class="role r-<?= e($m['role']) ?>"><?= e(role_name($m['role'])) ?></span></td>
                <td><b><?= (int)$m['total'] ?></b></td>
                <?php foreach ($ST as $sid => $_): $n = $stBy[$m['id']][$sid] ?? 0; ?><td class="<?= $n ? '' : 'muted' ?>"><?= $n ?></td><?php endforeach; ?>
                <td class="<?= $m['due'] ? 'warn-txt' : 'muted' ?>"><?= (int)$m['due'] ?></td>
                <td><?= (int)$m['views'] ?></td><td><?= (int)$m['sts'] ?></td><td><?= (int)$m['cms'] ?></td>
                <td class="muted nowrap"><?= $m['last_login'] ? fmt_dt($m['last_login'], 'd.m H:i') : 'никогда' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php layout_footer();
