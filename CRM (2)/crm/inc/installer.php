<?php
/** Общая логика установки (веб-установщик и консольный install_cli.php). */
function crm_install(string $root, array $db, string $adminLogin, string $adminName, string $adminPass): void
{
    $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    foreach (array_filter(array_map('trim', explode(';', file_get_contents($root . '/inc/schema.sql')))) as $sql) {
        $pdo->exec($sql);
    }

    if (!(int)$pdo->query('SELECT COUNT(*) FROM statuses')->fetchColumn()) {
        $seed = [
            ['Новый', '#3b82f6', 1], ['Потенциальный', '#eab308', 0], ['В работе', '#2dd4bf', 0],
            ['Перезвонить', '#fbbf24', 0], ['Не дозвон', '#94a3b8', 0], ['Дубликат', '#f43f5e', 0],
            ['Неинтересно', '#ef4444', 0], ['Успешно', '#22c55e', 0],
        ];
        $ss = $pdo->prepare('INSERT INTO statuses (name, color, sort_order, is_default) VALUES (?,?,?,?)');
        foreach ($seed as $i => [$n, $c, $d]) $ss->execute([$n, $c, $i * 10, $d]);
    }

    $st = $pdo->prepare("INSERT INTO users (login, password_hash, name, role) VALUES (?,?,?,'admin')");
    $st->execute([$adminLogin, password_hash($adminPass, PASSWORD_DEFAULT), $adminName]);

    $sample = file_get_contents($root . '/config.sample.php');
    $repl = [
        "'db_host' => '127.0.0.1'" => "'db_host' => " . var_export($db['host'], true),
        "'db_name' => 'crm'" => "'db_name' => " . var_export($db['name'], true),
        "'db_user' => 'crm'" => "'db_user' => " . var_export($db['user'], true),
        "'db_pass' => 'ПАРОЛЬ_ОТ_БАЗЫ'" => "'db_pass' => " . var_export($db['pass'], true),
    ];
    file_put_contents($root . '/config.php', strtr($sample, $repl));
    @chmod($root . '/config.php', 0640);
    file_put_contents($root . '/install.lock', date('c'));
}
