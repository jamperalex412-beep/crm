<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$assignable = assignable_users($u);
$isNew = isset($_GET['new']);
$id = (int)($_GET['id'] ?? 0);
$client = null;

if (!$isNew) {
    $client = q('SELECT * FROM clients WHERE id = ?', [$id])->fetch();
    if (!$client || !can_view_client($u, $client)) {
        http_response_code(404);
        layout_header($u, 'Не найдено');
        echo '<div class="panel"><h3>Клиент не найден или нет доступа</h3><a href="index.php" class="btn">← К списку</a></div>';
        layout_footer();
        exit;
    }
}

$errors = [];
$form = $client ?? ['full_name' => '', 'phone' => '', 'email' => '', 'description' => '', 'source' => '',
                    'status_id' => default_status_id(), 'assigned_to' => ($u['role'] === 'admin' ? null : $u['id'])];

/** Проверка телефона/email и дубля. */
function validate_personal(array &$form, array &$errors, int $selfId): string
{
    $norm = phone_normalize($form['phone']);
    if (strlen($norm) >= 7) $form['phone'] = phone_pretty($norm); // храним в едином виде
    if (strlen($norm) < 7) $errors[] = 'Укажите корректный номер телефона (с кодом страны).';
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Неверный email.';
    $dup = q('SELECT id, assigned_to FROM clients WHERE phone_norm = ? AND id <> ?', [$norm, $selfId])->fetch();
    if ($dup) $errors[] = 'Клиент с таким номером уже есть (ID ' . $dup['id'] . ', ответственный: ' . user_name($dup['assigned_to'] ? (int)$dup['assigned_to'] : null) . ').';
    return $norm;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $in = fn($k) => trim((string)($_POST[$k] ?? ''));

    /* --- Создание --- */
    if ($action === 'create' && $isNew) {
        foreach (['full_name', 'phone', 'email', 'description', 'source'] as $k) $form[$k] = $in($k);
        $sid = (int)($_POST['status_id'] ?? 0);
        $form['status_id'] = isset(statuses()[$sid]) ? $sid : default_status_id();
        $norm = validate_personal($form, $errors, 0);

        $to = $_POST['assigned_to'] ?? '';
        if ($assignable) {
            $form['assigned_to'] = ($to === '' && sees_all($u)) ? null : (int)$to;
            if ($form['assigned_to'] !== null && !can_assign_to($u, $form['assigned_to'])) $errors[] = 'Нельзя назначить этому сотруднику.';
            if ($form['assigned_to'] === null && !sees_all($u)) $form['assigned_to'] = (int)$u['id'];
        } else {
            $form['assigned_to'] = (int)$u['id'];
        }
        if (!$errors) {
            $geo = geo_from_phone($norm);
            q('INSERT INTO clients (full_name, phone, phone_norm, email, geo_iso, geo_name, description, source, status_id, assigned_to, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
                $form['full_name'], $form['phone'], $norm, $form['email'], $geo['iso'], $geo['name'],
                $form['description'], $form['source'], $form['status_id'], $form['assigned_to'], $u['id']]);
            $newId = (int)db()->lastInsertId();
            log_history($newId, (int)$u['id'], 'create', 'Ответственный: ' . user_name($form['assigned_to']));
            if ($form['assigned_to'] && (int)$form['assigned_to'] !== (int)$u['id']) {
                notify((int)$form['assigned_to'], $u['name'] . ' назначил вам нового клиента: ' . ($form['full_name'] ?: '#' . $newId), $newId);
            }
            flash('Клиент создан');
            redirect('client.php?id=' . $newId);
        }
    }

    /* --- Личные данные --- */
    if ($action === 'save_personal' && $client) {
        foreach (['full_name', 'phone', 'email'] as $k) $form[$k] = $in($k);
        $norm = validate_personal($form, $errors, (int)$client['id']);
        if (!$errors) {
            $changes = [];
            foreach (['full_name' => 'ФИО', 'phone' => 'Телефон', 'email' => 'Email'] as $k => $label) {
                if ((string)$client[$k] !== $form[$k]) $changes[] = $label;
            }
            $geo = geo_from_phone($norm);
            q('UPDATE clients SET full_name=?, phone=?, phone_norm=?, email=?, geo_iso=?, geo_name=? WHERE id=?',
                [$form['full_name'], $form['phone'], $norm, $form['email'], $geo['iso'], $geo['name'], $client['id']]);
            if ($changes) log_history((int)$client['id'], (int)$u['id'], 'edit', 'Изменено: ' . implode(', ', $changes));
            flash('Личные данные сохранены');
            redirect('client.php?id=' . $client['id']);
        }
    }

    /* --- Продажи: статус, ответственный, источник, описание --- */
    if ($action === 'save_sales' && $client) {
        set_client_status($client, (int)($_POST['status_id'] ?? 0), (int)$u['id']);
        $changes = [];
        foreach (['source' => 'Источник', 'description' => 'Описание'] as $k => $label) {
            if ((string)$client[$k] !== $in($k)) $changes[] = $label;
        }
        q('UPDATE clients SET source = ?, description = ? WHERE id = ?', [mb_substr($in('source'), 0, 120), $in('description'), $client['id']]);
        if ($changes) log_history((int)$client['id'], (int)$u['id'], 'edit', 'Изменено: ' . implode(', ', $changes));

        // Перезвон
        $cbRaw = trim((string)($_POST['callback_at'] ?? ''));
        $cb = $cbRaw !== '' && strtotime($cbRaw) ? date('Y-m-d H:i:00', strtotime($cbRaw)) : null;
        if ($cb !== ($client['callback_at'] ? date('Y-m-d H:i:00', strtotime($client['callback_at'])) : null)) {
            q('UPDATE clients SET callback_at = ? WHERE id = ?', [$cb, $client['id']]);
            log_history((int)$client['id'], (int)$u['id'], 'callback', $cb ? 'Перезвон на ' . fmt_dt($cb) : 'Перезвон снят');
        }

        $to = (int)($_POST['assigned_to'] ?? 0);
        if ($assignable && $to && can_assign_to($u, $to)) assign_client($client, $to, $u);
        flash('Сохранено');
        $fresh = q('SELECT * FROM clients WHERE id = ?', [$client['id']])->fetch();
        redirect(can_view_client($u, $fresh) ? 'client.php?id=' . $client['id'] : 'index.php');
    }

    /* --- Комментарии --- */
    if ($action === 'comment' && $client) {
        $body = $in('body');
        if ($body !== '') {
            q('INSERT INTO comments (client_id, user_id, body) VALUES (?,?,?)', [$client['id'], $u['id'], mb_substr($body, 0, 5000)]);
            q('UPDATE clients SET updated_at = NOW() WHERE id = ?', [$client['id']]);
            activity('comment', mb_strimwidth($body, 0, 120, '…'), (int)$client['id']);
        }
        redirect('client.php?id=' . $client['id']);
    }
    if ($action === 'del_comments' && $client) {
        $ids = array_map('intval', (array)($_POST['cids'] ?? []));
        foreach ($ids as $cid) {
            $cm = q('SELECT * FROM comments WHERE id = ? AND client_id = ?', [$cid, $client['id']])->fetch();
            if ($cm && ($u['role'] === 'admin' || (int)$cm['user_id'] === (int)$u['id'])) q('DELETE FROM comments WHERE id = ?', [$cid]);
        }
        redirect('client.php?id=' . $client['id']);
    }

    /* --- Перезвон выполнен --- */
    if ($action === 'callback_done' && $client) {
        q('UPDATE clients SET callback_at = NULL WHERE id = ?', [$client['id']]);
        log_history((int)$client['id'], (int)$u['id'], 'callback', 'Перезвон выполнен');
        $back = (string)($_POST['back'] ?? '');
        redirect(preg_match('/^[a-z_]+\.php(\?[\w=&%.-]*)?$/', $back) ? $back : 'client.php?id=' . $client['id']);
    }

    /* --- Удаление клиента --- */
    if ($action === 'delete' && $client && $u['role'] === 'admin') {
        activity('delete', ($client['full_name'] ?: 'Без имени') . ', ' . $client['phone'], (int)$client['id']);
        q('DELETE FROM clients WHERE id = ?', [$client['id']]);
        flash('Клиент удалён');
        redirect('index.php');
    }
}

$geo = geo_from_phone($form['phone'] ?? '');
$actionNames = ['create' => 'Создан', 'assign' => 'Передан', 'status' => 'Статус', 'edit' => 'Изменён', 'import' => 'Импорт', 'callback' => 'Перезвон'];

/* ---------- Новый клиент ---------- */
if ($isNew) {
    layout_header($u, 'Новый клиент', 'add');
    ?>
    <a href="index.php" class="backlink"><?= icon('back', 20) ?> К клиентам</a>
    <h1 class="page-title">Новый клиент</h1>
    <?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
    <form method="post" class="cols-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="panel form">
            <div class="panel-head"><h3>Личные данные</h3></div>
            <label>ФИО<input name="full_name" value="<?= e($form['full_name']) ?>" placeholder="Иванов Иван"></label>
            <label>Телефон (с кодом страны)
                <div class="phone-field"><span class="pf-flag" id="geoFlag"><?= $form['phone'] ? flag($geo['iso']) : '🌐' ?></span>
                    <input name="phone" id="phoneInput" value="<?= e($form['phone']) ?>" placeholder="+45 44 67 22 25" required></div>
                <small class="muted" id="geoName"><?= $form['phone'] ? e($geo['name']) : 'Страна определится по номеру' ?></small>
            </label>
            <label>Email<input name="email" type="email" value="<?= e($form['email']) ?>"></label>
        </div>
        <div class="panel form">
            <div class="panel-head"><h3>Продажи</h3><button class="btn primary"><?= icon('add', 18) ?> Создать</button></div>
            <?php if ($assignable): ?>
            <label>Ответственный
                <select name="assigned_to">
                    <?php if (sees_all($u)): ?><option value="">— Не назначен —</option><?php endif; ?>
                    <?php foreach ($assignable as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (int)$form['assigned_to'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e(role_name($a['role'])) ?>)</option>
                    <?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <label>Статус</label>
            <?= status_picker($form['status_id'] ? (int)$form['status_id'] : null) ?>
            <label class="mt">Источник<input name="source" value="<?= e($form['source']) ?>" placeholder="Facebook, сайт, база…"></label>
            <label>Описание<textarea name="description" rows="4"><?= e($form['description']) ?></textarea></label>
        </div>
    </form>
    <?php
    layout_footer();
    exit;
}

/* ---------- Карточка клиента ---------- */
$comments = q('SELECT cm.*, us.name, us.role FROM comments cm JOIN users us ON us.id = cm.user_id
               WHERE cm.client_id = ? ORDER BY cm.id ASC', [$client['id']])->fetchAll();
$history = q('SELECT h.*, us.name FROM client_history h LEFT JOIN users us ON us.id = h.user_id
              WHERE h.client_id = ? ORDER BY h.id DESC LIMIT 200', [$client['id']])->fetchAll();

// Соседние клиенты (стрелки ‹ ›) в пределах видимости
$owners = visible_owner_ids($u);
$ownSql = $owners === null ? '' : ' AND assigned_to IN (' . implode(',', array_map('intval', $owners)) . ')';
$prevId = q("SELECT id FROM clients WHERE id > ? $ownSql ORDER BY id ASC LIMIT 1", [$client['id']])->fetchColumn();
$nextId = q("SELECT id FROM clients WHERE id < ? $ownSql ORDER BY id DESC LIMIT 1", [$client['id']])->fetchColumn();
$ml = messenger_links($client['phone_norm']);
if (empty($_SESSION['viewed'][$client['id']]) || $_SESSION['viewed'][$client['id']] < time() - 600) {
    activity('view', $client['full_name'] ?: 'Без имени', (int)$client['id']);
    $_SESSION['viewed'][$client['id']] = time();
}
$cbOverdue = $client['callback_at'] && strtotime($client['callback_at']) <= time();

layout_header($u, $client['full_name'] ?: 'Клиент', 'clients');
?>
<div class="crumbs">
    <a href="index.php" class="backlink"><?= icon('back', 20) ?> К клиентам</a>
    <span class="navarrows">
        <a class="<?= $prevId ? '' : 'dis' ?>" href="<?= $prevId ? 'client.php?id=' . $prevId : '#' ?>" title="Предыдущий">‹</a>
        <a class="<?= $nextId ? '' : 'dis' ?>" href="<?= $nextId ? 'client.php?id=' . $nextId : '#' ?>" title="Следующий">›</a>
    </span>
</div>

<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<section class="client-head">
    <div class="ch-left">
        <div class="ch-title"><h1><?= e($client['full_name'] ?: 'Без имени') ?></h1>
            <span class="ch-id"><?= $client['id'] ?></span><?= copy_btn((string)$client['id'], 'Копировать ID') ?>
            <?= status_badge($client['status_id'] ? (int)$client['status_id'] : null) ?></div>
        <div class="ch-meta">
            <span>Страна: <b><?= flag($client['geo_iso']) ?> <?= e($client['geo_name'] ?: '—') ?></b></span>
            <span>Ответственный: <b><?= e(user_name($client['assigned_to'] ? (int)$client['assigned_to'] : null)) ?></b></span>
            <span>Создан: <b><?= date('d.m.Y H:i', strtotime($client['created_at'])) ?></b></span>
            <span>Создал: <b><?= e(user_name($client['created_by'] ? (int)$client['created_by'] : null)) ?></b></span>
            <?php if ($client['callback_at']): ?>
                <span class="cb-chip <?= $cbOverdue ? 'due' : '' ?>">⏰ Перезвон: <b><?= fmt_dt($client['callback_at']) ?></b>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="callback_done">
                        <button class="tbtn sm">✓ Выполнен</button></form></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="ch-actions">
        <a class="abtn" href="tel:+<?= e($client['phone_norm']) ?>"><?= icon('phone', 22) ?><span>Позвонить</span></a>
        <div class="dd">
            <button type="button" class="abtn dd-btn"><?= icon('chat', 22) ?><span>Мессенджер</span></button>
            <div class="dd-menu">
                <?php foreach ($ml as $name => $href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= e($name) ?></a><?php endforeach; ?>
            </div>
        </div>
        <?php if ($client['email']): ?>
            <a class="abtn" href="mailto:<?= e($client['email']) ?>"><?= icon('mail', 22) ?><span>Email</span></a>
        <?php endif; ?>
        <div class="dd">
            <button type="button" class="abtn dd-btn"><?= icon('more', 22) ?><span>Ещё</span></button>
            <div class="dd-menu right">
                <a href="#" data-copy="+<?= e($client['phone_norm']) ?>">Копировать номер</a>
                <?php if ($client['email']): ?><a href="#" data-copy="<?= e($client['email']) ?>">Копировать email</a><?php endif; ?>
                <?php if ($u['role'] === 'admin'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                        <button class="danger-item" data-confirm="Удалить клиента и все его комментарии?">Удалить клиента</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<nav class="tabs">
    <a href="#tab-card" class="on">Карточка</a>
    <a href="#tab-history">История <span class="muted"><?= count($history) ?></span></a>
</nav>

<div class="tab-pane on" id="tab-card">
<div class="client-grid">
    <form method="post" class="panel form">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_personal">
        <div class="panel-head"><h3>Личные данные</h3><button class="btn soft"><?= icon('update', 17) ?> Сохранить</button></div>
        <label>ФИО<input name="full_name" value="<?= e($form['full_name']) ?>"></label>
        <label>Страна
            <div class="readonly"><span class="flag"><?= flag($client['geo_iso']) ?></span> <?= e($client['geo_name'] ?: 'Не определено') ?>
                <small class="muted">— по номеру</small></div></label>
        <label>Телефон
            <div class="phone-field"><span class="pf-flag" id="geoFlag"><?= flag($geo['iso']) ?></span>
                <input name="phone" id="phoneInput" value="<?= e(phone_pretty($form['phone'])) ?>" required>
                <?= copy_btn('+' . $client['phone_norm'], 'Копировать номер') ?></div>
            <small class="muted" id="geoName"></small></label>
        <label>Email
            <div class="phone-field"><input name="email" type="email" value="<?= e($form['email']) ?>">
                <?php if ($client['email']): ?><?= copy_btn($client['email'], 'Копировать email') ?><?php endif; ?></div></label>
    </form>

    <form method="post" class="panel form">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_sales">
        <div class="panel-head"><h3>Продажи</h3><button class="btn primary"><?= icon('update', 17) ?> Сохранить</button></div>
        <label>Ответственный
            <?php if ($assignable): ?>
                <select name="assigned_to">
                    <?php if (!$client['assigned_to']): ?><option value="">— Не назначен —</option><?php endif; ?>
                    <?php foreach ($assignable as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (int)$client['assigned_to'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e(role_name($a['role'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div class="readonly"><?= e(user_name($client['assigned_to'] ? (int)$client['assigned_to'] : null)) ?></div>
            <?php endif; ?></label>
        <label>Статус</label>
        <?= status_picker($client['status_id'] ? (int)$client['status_id'] : null) ?>
        <label class="mt">Перезвонить
            <div class="cb-row"><input type="datetime-local" name="callback_at" id="cbInput" value="<?= $client['callback_at'] ? date('Y-m-d\TH:i', strtotime($client['callback_at'])) : '' ?>">
                <button type="button" class="tbtn sm" data-cb="60">+1 ч</button><button type="button" class="tbtn sm" data-cb="tomorrow">Завтра 10:00</button>
                <button type="button" class="tbtn sm ghost" data-cb="clear" title="Снять">✕</button></div></label>
        <label>Источник<input name="source" value="<?= e($client['source']) ?>" placeholder="—"></label>
        <label>Описание<textarea name="description" rows="5" placeholder="Дополнительная информация"><?= e($client['description']) ?></textarea></label>
    </form>

    <div class="panel comments-panel">
        <form method="post" id="cmDel" class="cm-top">
            <?= csrf_field() ?><input type="hidden" name="action" value="del_comments">
            <a href="client.php?id=<?= $client['id'] ?>" class="icon-btn" title="Обновить"><?= icon('refresh', 18) ?></a>
            <span class="muted small">Комментарии: <?= count($comments) ?></span>
            <button class="icon-btn danger" id="cmDelBtn" data-confirm="Удалить выбранные комментарии?" title="Удалить выбранные" hidden><?= icon('trash', 17) ?></button>
        </form>
        <div class="cm-list" id="cmList">
            <?php foreach ($comments as $cm): $mine = $u['role'] === 'admin' || (int)$cm['user_id'] === (int)$u['id']; ?>
                <div class="cm <?= (int)$cm['user_id'] === (int)$u['id'] ? 'own' : '' ?>">
                    <div class="cm-h">
                        <span class="avatar sm r-<?= e($cm['role']) ?>"><?= e(mb_strtoupper(mb_substr($cm['name'], 0, 1))) ?></span>
                        <b><?= e($cm['name']) ?></b><span class="muted small"><?= date('d.m.Y H:i', strtotime($cm['created_at'])) ?></span>
                        <?php if ($mine): ?><input type="checkbox" form="cmDel" name="cids[]" value="<?= $cm['id'] ?>" class="cm-chk"><?php endif; ?>
                    </div>
                    <div class="cm-b"><?= nl2br(e($cm['body'])) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$comments): ?>
                <div class="cm-empty"><?= icon('chat', 56) ?><p>Комментариев пока нет</p></div>
            <?php endif; ?>
        </div>
        <form method="post" class="cm-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="comment">
            <span class="avatar sm r-<?= e($u['role']) ?>"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
            <textarea name="body" rows="1" placeholder="Написать комментарий… (Enter — отправить, Shift+Enter — новая строка)" required></textarea>
            <button class="icon-btn primary" title="Отправить"><?= icon('send', 18) ?></button>
        </form>
    </div>
</div>
</div>

<div class="tab-pane" id="tab-history">
    <div class="panel p0">
        <table class="tbl">
            <thead><tr><th>Дата</th><th>Действие</th><th>Подробности</th><th>Сотрудник</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr><td class="nowrap"><?= date('d.m.Y H:i', strtotime($h['created_at'])) ?></td>
                    <td><span class="tag"><?= e($actionNames[$h['action']] ?? $h['action']) ?></span></td>
                    <td><?= e($h['details']) ?></td><td><?= e($h['name'] ?? '—') ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="4" class="empty">Пусто</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layout_footer();
