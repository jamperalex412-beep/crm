<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($old, $u['password_hash'])) $err = 'Текущий пароль неверный.';
    elseif (strlen($new) < 6) $err = 'Новый пароль — минимум 6 символов.';
    elseif ($new !== ($_POST['new2'] ?? '')) $err = 'Пароли не совпадают.';
    else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        activity('password', 'Сменил свой пароль');
        flash('Пароль изменён');
        redirect('profile.php');
    }
}
layout_header($u, 'Настройки', 'profile');
?>
<h1 class="page-title">Настройки</h1>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<form method="post" class="card form narrow">
    <?= csrf_field() ?>
    <h3>Смена пароля</h3>
    <p class="muted small">Логин: <b class="mono"><?= e($u['login']) ?></b> · Роль: <?= e(role_name($u['role'])) ?></p>
    <label>Текущий пароль<input type="password" name="old" required></label>
    <label>Новый пароль<input type="password" name="new" required minlength="6"></label>
    <label>Повторите новый пароль<input type="password" name="new2" required minlength="6"></label>
    <button class="btn primary">Сменить пароль</button>
</form>
<?php layout_footer();
