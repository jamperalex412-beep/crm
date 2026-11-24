<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

if (!is_file(ROOT . '/config.php')) {
    header('Location: install.php');
    exit;
}
$CONFIG = require ROOT . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Europe/Berlin');

require __DIR__ . '/geo.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/filters.php';

session_name('crm_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4",
            $CONFIG['db_user'],
            $CONFIG['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            exit('Сессия устарела. Обновите страницу и попробуйте снова.');
        }
    }
}

/* ---------- Роли ---------- */
const ROLES = [
    'admin'    => 'Администратор',
    'shift'    => 'Шифт',
    'teamlead' => 'Тимлидер',
    'manager'  => 'Менеджер',
];

function role_name(string $r): string { return ROLES[$r] ?? $r; }

/** Все статусы: id => [id, name, color, sort_order, is_default] (в порядке сортировки). */
function statuses(bool $reload = false): array
{
    static $list = null;
    if ($list === null || $reload) {
        $list = [];
        foreach (q('SELECT * FROM statuses ORDER BY sort_order, id')->fetchAll() as $s) $list[(int)$s['id']] = $s;
    }
    return $list;
}

function default_status_id(): ?int
{
    foreach (statuses() as $id => $s) if ($s['is_default']) return $id;
    $all = statuses();
    return $all ? (int)array_key_first($all) : null;
}

function status_name(?int $id): string
{
    return $id && isset(statuses()[$id]) ? statuses()[$id]['name'] : 'Без статуса';
}

function status_badge(?int $id): string
{
    $s = $id ? (statuses()[$id] ?? null) : null;
    $color = $s['color'] ?? '#64748b';
    return '<span class="badge" style="--c:' . e($color) . '">' . e($s['name'] ?? 'Без статуса') . '</span>';
}

/** Выпадающий список статусов с цветными плашками. $ajaxClientId — менять сразу через api_status.php */
function status_picker(?int $current, string $inputName = 'status_id', ?int $ajaxClientId = null): string
{
    $h = '<div class="spick" ' . ($ajaxClientId ? 'data-client="' . $ajaxClientId . '"' : '') . '>';
    $h .= '<input type="hidden" name="' . e($inputName) . '" value="' . (int)$current . '">';
    $h .= '<button type="button" class="spick-btn">' . status_badge($current) . '<i class="caret"></i></button><div class="spick-menu">';
    foreach (statuses() as $id => $s) {
        $h .= '<button type="button" class="spick-opt' . ($id === $current ? ' on' : '') . '" data-id="' . $id . '">'
            . status_badge($id) . '</button>';
    }
    return $h . '</div></div>';
}

/** Смена статуса с записью в историю. */
function set_client_status(array $client, int $statusId, int $userId): bool
{
    if (!isset(statuses()[$statusId]) || (int)$client['status_id'] === $statusId) return false;
    q('UPDATE clients SET status_id = ? WHERE id = ?', [$statusId, $client['id']]);
    log_history((int)$client['id'], $userId, 'status',
        status_name($client['status_id'] ? (int)$client['status_id'] : null) . ' → ' . status_name($statusId));
    return true;
}

/* ---------- Авторизация ---------- */
function current_user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['uid']])->fetch() ?: null;
            if (!$u) { session_destroy(); }
        }
    }
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    csrf_check();
    return $u;
}

function require_role(array $u, array $roles): void
{
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Нет доступа');
    }
}

/** Видят всех клиентов. */
function sees_all(array $u): bool
{
    return in_array($u['role'], ['admin', 'shift'], true);
}

/** ID пользователей, чьих клиентов видит $u (null = все). */
function visible_owner_ids(array $u): ?array
{
    if (sees_all($u)) return null;
    if ($u['role'] === 'teamlead') {
        $ids = q('SELECT id FROM users WHERE team_lead_id = ?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
        $ids[] = $u['id'];
        return array_map('intval', $ids);
    }
    return [(int)$u['id']];
}

function can_view_client(array $u, array $client): bool
{
    $ids = visible_owner_ids($u);
    return $ids === null || in_array((int)$client['assigned_to'], $ids, true);
}

/** Кому $u может передавать клиентов. */
function assignable_users(array $u): array
{
    if (sees_all($u)) {
        return q("SELECT id, name, role FROM users WHERE is_active = 1 AND role <> 'admin'
                  ORDER BY FIELD(role,'shift','teamlead','manager'), name")->fetchAll();
    }
    if ($u['role'] === 'teamlead') {
        return q("SELECT id, name, role FROM users WHERE is_active = 1 AND (id = ? OR team_lead_id = ?)
                  ORDER BY (id = ?) DESC, name", [$u['id'], $u['id'], $u['id']])->fetchAll();
    }
    return [];
}

function can_assign_to(array $u, int $targetId): bool
{
    foreach (assignable_users($u) as $a) if ((int)$a['id'] === $targetId) return true;
    return false;
}

function log_history(int $clientId, int $userId, string $action, string $details = ''): void
{
    q('INSERT INTO client_history (client_id, user_id, action, details) VALUES (?,?,?,?)',
        [$clientId, $userId, $action, mb_substr($details, 0, 500)]);
    activity($action, $details, $clientId, $userId);
}

/** Журнал действий сотрудников. */
function activity(string $action, string $details = '', ?int $clientId = null, ?int $userId = null): void
{
    $userId ??= isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
    q('INSERT INTO activity_log (user_id, action, client_id, details, ip) VALUES (?,?,?,?,?)',
        [$userId, $action, $clientId, mb_substr($details, 0, 500), client_ip()]);
}

const ACTIVITY_NAMES = [
    'login' => 'Вход', 'login_fail' => 'Неудачный вход', 'logout' => 'Выход', 'view' => 'Открыл клиента',
    'create' => 'Создал клиента', 'edit' => 'Изменил клиента', 'status' => 'Сменил статус', 'assign' => 'Передал клиента',
    'comment' => 'Комментарий', 'callback' => 'Перезвон', 'delete' => 'Удалил клиента', 'import' => 'Импорт',
    'export' => 'Экспорт', 'user' => 'Сотрудники', 'statuses' => 'Статусы', 'team' => 'Команда', 'password' => 'Смена пароля',
];

/** Уведомление сотруднику (колокольчик). */
function notify(int $userId, string $body, ?int $clientId = null): void
{
    q('INSERT INTO notifications (user_id, client_id, body) VALUES (?,?,?)', [$userId, $clientId, mb_substr($body, 0, 300)]);
}

/** Передача клиента: запись, история, уведомление новому ответственному. */
function assign_client(array $client, int $to, array $by, bool $notify = true): bool
{
    if ((int)$client['assigned_to'] === $to) return false;
    q('UPDATE clients SET assigned_to = ? WHERE id = ?', [$to, $client['id']]);
    log_history((int)$client['id'], (int)$by['id'], 'assign',
        user_name($client['assigned_to'] ? (int)$client['assigned_to'] : null) . ' → ' . user_name($to));
    if ($notify && $to !== (int)$by['id']) {
        notify($to, $by['name'] . ' передал вам клиента: ' . ($client['full_name'] ?: '#' . $client['id']), (int)$client['id']);
    }
    return true;
}

/** Перезвоны, срок которых наступил (для колокольчика). */
function due_callbacks(int $userId, int $limit = 20): array
{
    return q("SELECT id, full_name, phone, callback_at FROM clients
              WHERE assigned_to = ? AND callback_at IS NOT NULL AND callback_at <= NOW()
              ORDER BY callback_at LIMIT $limit", [$userId])->fetchAll();
}

/** SQL-условие видимости клиентов для пользователя (alias c). */
function visibility_sql(array $u): array
{
    $ids = visible_owner_ids($u);
    if ($ids === null) return ['1=1', []];
    return ['c.assigned_to IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function fmt_dt(?string $dt, string $f = 'd.m.Y H:i'): string
{
    return $dt ? date($f, strtotime($dt)) : '—';
}

function user_name(?int $id): string
{
    if (!$id) return '—';
    static $cache = [];
    if (!isset($cache[$id])) {
        $cache[$id] = (string)(q('SELECT name FROM users WHERE id = ?', [$id])->fetchColumn() ?: '—');
    }
    return $cache[$id];
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
