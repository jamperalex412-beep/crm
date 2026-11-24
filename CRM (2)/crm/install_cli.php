<?php
// Консольная установка (используется install.sh):
// php install_cli.php <db_host> <db_name> <db_user> <db_pass> <admin_login> <admin_name> <admin_pass>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = __DIR__;
if (is_file($root . '/install.lock')) { fwrite(STDERR, "Уже установлено (install.lock)\n"); exit(1); }
if ($argc < 8) { fwrite(STDERR, "Использование: php install_cli.php host db user pass admin_login admin_name admin_pass\n"); exit(1); }
require $root . '/inc/installer.php';
try {
    crm_install($root, ['host' => $argv[1], 'name' => $argv[2], 'user' => $argv[3], 'pass' => $argv[4]], $argv[5], $argv[6], $argv[7]);
    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
