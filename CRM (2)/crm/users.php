<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['admin']);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $role = (string)($_POST['role'] ?? 'manager');
    if (!isset(ROLES[$role])) $role = 'manager';
    $tl = ($_POST['team_lead_id'] ?? '') !== '' ? (int)$_POST['team_lead_id'] : null;
    if ($role !== 'manager') $tl = null; // в команду подвязываются только менеджеры
    if ($tl !== null && !q("SELECT 1 FROM users WHERE id = ? AND role = 'teamlead'", [$tl])->fetchColumn()) $tl = null;

    if ($action === 'create') {
        $login = trim((string)($_POST['login'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.\-]{3,64}$/', $login)) $errors[] = 'Логин: 3–64 символа, латиница, цифры, _ . -';
        if ($name === '') $errors[] = 'Укажите имя.';
        if (strlen($pass) < 6) $errors[] = 'Пароль — минимум 6 символов.';
        if (q('SELECT 1 FROM users WHERE login = ?', [$login])->fetchColumn()) $errors[] = 'Такой логин уже занят.';
        if (!$errors) {
            q('INSERT INTO users (login, password_hash, name, role, team_lead_id) VALUES (?,?,?,?,?)',
                [$login, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $tl]);
            activity('user', "Создан сотрудник {$name} ({$login}), роль: " . role_name($role));
            flash("Пользователь «{$name}» создан. Логин: {$login}");
            redirect('users.php');
        }
    }

    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $target = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
        if ($target) {
            $name = trim((string)($_POST['name'] ?? '')) ?: $target['name'];
            if ((int)$target['id'] === (int)$u['id']) $role = 'admin'; // себе роль не понизить
            q('UPDATE users SET name = ?, role = ?, team_lead_id = ? WHERE id = ?', [$name, $role, $tl, $id]);
            // Если тимлидер перестал быть тимлидером — его команда освобождается
            if ($target['role'] === 'teamlead' && $role !== 'teamlead') {
                q('UPDATE users SET team_lead_id = NULL WHERE team_lead_id = ?', [$id]);
            }
            activity('user', "Изменён сотрудник {$name}, роль: " . role_name($role));
            $pass = (string)($_POST['password'] ?? '');
            if ($pass !== '') {
                activity('user', "Сброшен пароль сотруднику {$name}");
                if (strlen($pass) < 6) { flash('Пароль не изменён: минимум 6 символов', 'err'); redirect('users.php?edit=' . $id); }
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            flash('Сохранено');
        }
        redirect('users.php');
    }

    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$u['id']) {
            q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
            activity('user', 'Блокировка/разблокировка: ' . user_name($id));
            flash('Статус пользователя изменён');
        }
        redirect('users.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$u['id']) {
            $to = ($_POST['move_to'] ?? '') !== '' ? (int)$_POST['move_to'] : null;
            activity('user', 'Удалён сотрудник ' . user_name($id) . ', клиенты → ' . user_name($to));
            q('UPDATE clients SET assigned_to = ? WHERE assigned_to = ?', [$to, $id]);
            q('UPDATE users SET team_lead_id = NULL WHERE team_lead_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('Пользователь удалён, его клиенты переданы: ' . user_name($to));
        }
        redirect('users.php');
    }
}

$users = q("SELECT us.*, tl.name AS tl_name,
              (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = us.id) AS clients_cnt
            FROM users us LEFT JOIN users tl ON tl.id = us.team_lead_id
            ORDER BY FIELD(us.role,'admin','shift','teamlead','manager'), us.name")->fetchAll();
$teamleads = array_filter($users, fn($x) => $x['role'] === 'teamlead');
$edit = isset($_GET['edit']) ? q('SELECT * FROM users WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;

function tl_select(array $teamleads, $selected): string
{
    $h = '<select name="team_lead_id"><option value="">— без команды —</option>';
    foreach ($teamleads as $t) {
        $h .= '<option value="' . $t['id'] . '"' . ((int)$selected === (int)$t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>';
    }
    return $h . '</select>';
}
function role_select(string $selected, bool $lock = false): string
{
    $h = '<select name="role" class="role-sel"' . ($lock ? ' disabled' : '') . '>';
    foreach (ROLES as $k => $v) $h .= '<option value="' . $k . '"' . ($k === $selected ? ' selected' : '') . '>' . e($v) . '</option>';
    return $h . '</select>';
}

layout_header($u, 'Пользователи', 'users');
?>
<h1 class="page-title">Пользователи</h1>
<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<div class="grid-2 wide-left">
<div class="col">
    <div class="table-wrap card p0">
    <table class="tbl">
        <thead><tr><th>Имя</th><th>Логин</th><th>Роль</th><th>Команда</th><th>Клиентов</th><th>Последний вход</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $x): ?>
            <tr class="<?= $x['is_active'] ? '' : 'disabled' ?>">
                <td class="strong"><?= e($x['name']) ?></td>
                <td class="mono"><?= e($x['login']) ?></td>
                <td><span class="role r-<?= $x['role'] ?>"><?= e(role_name($x['role'])) ?></span></td>
                <td><?= $x['role'] === 'manager' ? e($x['tl_name'] ?? '—') : '' ?></td>
                <td><?= (int)$x['clients_cnt'] ?></td>
                <td class="muted small"><?= $x['last_login'] ? date('d.m.y H:i', strtotime($x['last_login'])) : 'никогда' ?></td>
                <td class="nowrap actions">
                    <a href="?edit=<?= $x['id'] ?>" class="btn sm">Изменить</a>
                    <?php if ((int)$x['id'] !== (int)$u['id']): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $x['id'] ?>">
                            <button class="btn sm ghost"><?= $x['is_active'] ? 'Блок' : 'Разблок' ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="col">
    <?php if ($edit): ?>
    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
        <h3>Изменить: <?= e($edit['name']) ?></h3>
        <p class="muted small">Логин: <b class="mono"><?= e($edit['login']) ?></b></p>
        <label>Имя<input name="name" value="<?= e($edit['name']) ?>"></label>
        <label>Роль<?= role_select($edit['role'], (int)$edit['id'] === (int)$u['id']) ?></label>
        <label class="tl-field">Тимлидер (команда)<?= tl_select($teamleads, $edit['team_lead_id']) ?></label>
        <label>Новый пароль <span class="muted">(оставьте пустым, чтобы не менять)</span>
            <div class="pw"><input name="password" type="text" autocomplete="new-password"><button type="button" class="btn sm gen-pw">Сгенерировать</button></div></label>
        <div class="row-inline"><button class="btn primary">Сохранить</button><a href="users.php" class="btn ghost">Отмена</a></div>
    </form>
    <?php if ((int)$edit['id'] !== (int)$u['id']): ?>
    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
        <h3>Удалить пользователя</h3>
        <label>Передать его клиентов
            <select name="move_to"><option value="">— Не назначен —</option>
                <?php foreach ($users as $x): if ((int)$x['id'] === (int)$edit['id'] || $x['role'] === 'admin') continue; ?>
                    <option value="<?= $x['id'] ?>"><?= e($x['name']) ?> (<?= e(role_name($x['role'])) ?>)</option>
                <?php endforeach; ?></select></label>
        <button class="btn danger" data-confirm="Удалить пользователя? Лучше просто заблокировать, если сомневаетесь.">Удалить</button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <h3>Новый пользователь</h3>
        <label>Имя<input name="name" required value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label>Логин<input name="login" required pattern="[a-zA-Z0-9_.\-]{3,64}" value="<?= e($_POST['login'] ?? '') ?>"></label>
        <label>Пароль
            <div class="pw"><input name="password" type="text" required minlength="6" autocomplete="new-password"><button type="button" class="btn sm gen-pw">Сгенерировать</button></div></label>
        <label>Роль<?= role_select($_POST['role'] ?? 'manager') ?></label>
        <label class="tl-field">Тимлидер (команда)<?= tl_select($teamleads, $_POST['team_lead_id'] ?? null) ?></label>
        <button class="btn primary">Создать</button>
        <p class="muted small">Логин и пароль передайте сотруднику — он сможет сменить пароль сам в настройках (⚙).</p>
    </form>
    <?php endif; ?>
</div>
</div>
<?php layout_footer();
