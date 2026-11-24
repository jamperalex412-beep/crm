<?php
// Установщик: создаёт config.php, таблицы и первого администратора.
// После установки создаётся install.lock — повторно запустить нельзя.
declare(strict_types=1);
$root = __DIR__;
if (is_file($root . '/install.lock')) {
    exit('CRM уже установлена. Для переустановки удалите install.lock (и лучше сам install.php).');
}

$err = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = (string)($_POST['db_pass'] ?? '');
    $aLogin = trim($_POST['admin_login'] ?? '');
    $aName = trim($_POST['admin_name'] ?? '');
    $aPass = (string)($_POST['admin_pass'] ?? '');

    if ($name === '' || $user === '' || $aLogin === '' || $aName === '') {
        $err = 'Заполните все поля.';
    } elseif (strlen($aPass) < 8) {
        $err = 'Пароль администратора — минимум 8 символов.';
    } else {
        try {
            require $root . '/inc/installer.php';
            crm_install($root, ['host' => $host, 'name' => $name, 'user' => $user, 'pass' => $pass], $aLogin, $aName, $aPass);
            $done = true;
        } catch (Throwable $ex) {
            $err = 'Ошибка: ' . $ex->getMessage();
        }
    }
}
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Установка CRM</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="auth">
<div class="auth-card wide">
    <h1>Установка CRM</h1>
    <?php if ($done): ?>
        <div class="alert ok">Готово! Удалите файл <b>install.php</b> с сервера и войдите.</div>
        <a class="btn primary block" href="login.php">Перейти ко входу</a>
    <?php else: ?>
        <?php if ($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <form method="post" class="form">
            <h3>База данных MySQL</h3>
            <label>Хост<input name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1') ?>"></label>
            <label>Имя базы<input name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'crm') ?>" required></label>
            <label>Пользователь<input name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required></label>
            <label>Пароль<input type="password" name="db_pass"></label>
            <h3>Главный администратор</h3>
            <label>Имя<input name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'Администратор') ?>" required></label>
            <label>Логин<input name="admin_login" value="<?= htmlspecialchars($_POST['admin_login'] ?? 'admin') ?>" required></label>
            <label>Пароль (мин. 8 символов)<input type="password" name="admin_pass" required minlength="8"></label>
            <button class="btn primary block">Установить</button>
        </form>
    <?php endif; ?>
</div>
</body></html>
