<?php
/** Иконки (обводка 1.8, 24×24). */
function icon(string $name, int $size = 20): string
{
    static $p = [
        'clients'  => '<path d="M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1"/><circle cx="9" cy="7" r="3.5"/><path d="M22 19v-1a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'add'      => '<path d="M15 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1"/><circle cx="8.5" cy="7" r="3.5"/><path d="M19 8v6M22 11h-6"/>',
        'import'   => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'team'     => '<circle cx="12" cy="6" r="3"/><circle cx="5" cy="17" r="2.5"/><circle cx="19" cy="17" r="2.5"/><path d="M12 9v3M12 12l-6 3M12 12l6 3"/>',
        'users'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/><path d="M19 3l2 2-2 2"/>',
        'tag'      => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'phone'    => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'mail'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>',
        'chat'     => '<path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 21l2-5.8A8.4 8.4 0 1 1 21 11.5z"/>',
        'open'     => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
        'filter'   => '<path d="M3 5h18M6 12h12M10 19h4"/>',
        'refresh'  => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/>',
        'back'     => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'update'   => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/>',
        'more'     => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
        'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'trash'    => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'send'     => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
        'history'  => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 3"/>',
        'dash'     => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
        'log'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'web'      => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
    ];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . ($p[$name] ?? '') . '</svg>';
}

/** Кнопка копирования. */
function copy_btn(string $value, string $title = 'Копировать'): string
{
    return '<button type="button" class="cp" data-copy="' . e($value) . '" title="' . e($title) . '">' . icon('copy', 15) . '</button>';
}

/** Ссылки на мессенджеры по номеру. */
function messenger_links(string $phoneNorm): array
{
    return [
        'WhatsApp' => 'https://wa.me/' . $phoneNorm,
        'Telegram' => 'https://t.me/+' . $phoneNorm,
        'Viber'    => 'viber://chat?number=%2B' . $phoneNorm,
    ];
}

function layout_header(array $u, string $title, string $active = ''): void
{
    global $CONFIG;
    $nav = [
        'dashboard'=> ['dashboard.php', 'Дашборд', 'dash', ['admin', 'shift', 'teamlead', 'manager']],
        'clients'  => ['index.php', 'Клиенты', 'clients', ['admin', 'shift', 'teamlead', 'manager']],
        'callbacks'=> ['callbacks.php', 'Перезвоны', 'clock', ['admin', 'shift', 'teamlead', 'manager']],
        'add'      => ['client.php?new=1', 'Новый клиент', 'add', ['admin', 'shift', 'teamlead', 'manager']],
        'team'     => ['team.php', 'Моя команда', 'team', ['teamlead']],
        'teams'    => ['teams.php', 'Команды', 'team', ['admin', 'shift']],
        'import'   => ['import.php', 'Импорт', 'import', ['admin', 'shift']],
        'statuses' => ['statuses.php', 'Статусы', 'tag', ['admin']],
        'users'    => ['users.php', 'Сотрудники', 'users', ['admin']],
        'activity' => ['activity.php', 'Журнал', 'log', ['admin']],
    ];
    $dueCnt = (int)q('SELECT COUNT(*) FROM clients WHERE assigned_to = ? AND callback_at IS NOT NULL AND callback_at <= NOW()', [$u['id']])->fetchColumn();
    $unreadCnt = (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']])->fetchColumn();
    $bellCnt = $dueCnt + $unreadCnt;
    $f = flash();
    $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim($u['name'])), 0, 2))));
    ?><!doctype html>
<html lang="ru"><head>
<meta name="csrf" content="<?= csrf_token() ?>"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — <?= e($CONFIG['app_name']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=4">
</head><body>
<aside class="side">
    <button class="side-toggle" type="button" onclick="document.body.classList.toggle('side-open')"><?= icon('menu', 22) ?></button>
    <nav>
        <?php foreach ($nav as $key => [$href, $label, $ic, $roles]): if (!in_array($u['role'], $roles, true)) continue; ?>
            <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>"><span class="pill"><?= icon($ic, 22) ?><?php if ($key === 'callbacks' && $dueCnt): ?><i class="nbadge" id="sideDue"><?= $dueCnt ?></i><?php endif; ?></span><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
        <a href="profile.php" class="<?= $active === 'profile' ? 'active' : '' ?>"><span class="pill"><?= icon('settings', 22) ?></span><span>Настройки</span></a>
    </nav>
</aside>
<div class="main">
<header class="top">
    <button class="burger" type="button" onclick="document.body.classList.toggle('side-open')"><?= icon('menu', 22) ?></button>
    <a class="brand" href="index.php"><?= e($CONFIG['app_name']) ?></a>
    <form class="gsearch" action="index.php" method="get">
        <?= icon('search', 20) ?><input name="q" placeholder="Поиск: имя, телефон, email, ID" value="<?= e($_GET['q'] ?? '') ?>">
    </form>
    <div class="bell dd" id="bell">
        <button type="button" class="icon-btn bell-btn" title="Уведомления"><?= icon('bell', 22) ?><i class="nbadge" id="bellCnt" <?= $bellCnt ? '' : 'hidden' ?>><?= $bellCnt ?></i></button>
        <div class="dd-menu right bell-menu" id="bellMenu"><div class="bell-empty muted">Загрузка…</div></div>
    </div>
    <div class="me">
        <div class="me-name"><b><?= e($u['name']) ?></b><small><?= e(role_name($u['role'])) ?></small></div>
        <div class="avatar r-<?= e($u['role']) ?>"><?= e($initials) ?></div>
        <form method="post" action="logout.php"><?= csrf_field() ?><button class="icon-btn" title="Выйти"><?= icon('logout', 20) ?></button></form>
    </div>
</header>
<main class="wrap">
<?php if ($f): ?><div class="alert <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif;
}

function layout_footer(): void
{
    ?></main>
</div>
<div id="toast"></div>
<script src="assets/app.js?v=4"></script>
</body></html><?php
}
