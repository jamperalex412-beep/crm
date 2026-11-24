<?php
require __DIR__ . '/inc/bootstrap.php';
csrf_check();

if (current_user()) redirect('dashboard.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $ip = client_ip();

    // Защита от перебора: не более 8 неудачных попыток за 15 минут с одного IP
    $fails = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0
                     AND created_at > NOW() - INTERVAL 15 MINUTE', [$ip])->fetchColumn();
    if ($fails >= 8) {
        $err = 'Слишком много попыток. Подождите 15 минут.';
    } else {
        $u = q('SELECT * FROM users WHERE login = ?', [$login])->fetch();
        $ok = $u && $u['is_active'] && password_verify($pass, $u['password_hash']);
        q('INSERT INTO login_attempts (ip, login, success) VALUES (?,?,?)', [$ip, mb_substr($login, 0, 64), $ok ? 1 : 0]);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            activity('login', $_SERVER['HTTP_USER_AGENT'] ?? '', null, (int)$u['id']);
            redirect('dashboard.php');
        }
        activity('login_fail', 'Логин: ' . mb_substr($login, 0, 64), null, $u ? (int)$u['id'] : null);
        $err = ($u && !$u['is_active']) ? 'Аккаунт заблокирован.' : 'Неверный логин или пароль.';
    }
}
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Вход — <?= e($CONFIG['app_name']) ?></title><link rel="stylesheet" href="assets/style.css"></head>
<body class="auth">
<form class="auth-card form" method="post" autocomplete="off">
    <div class="logo big"><span>◆</span> <?= e($CONFIG['app_name']) ?></div>
    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Логин<input name="login" required autofocus value="<?= e($_POST['login'] ?? '') ?>"></label>
    <label>Пароль<input type="password" name="password" required></label>
    <button class="btn primary block">Войти</button>
    <p class="muted small center">Доступ выдаёт администратор</p>
</form>
</body></html>
