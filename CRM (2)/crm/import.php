<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
require_role($u, ['admin', 'shift']);
$assignable = assignable_users($u);

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['csv']['tmp_name'] ?? '';
    $targets = array_values(array_filter(array_map('intval', (array)($_POST['to'] ?? [])), fn($id) => can_assign_to($u, $id)));
    $source = trim((string)($_POST['source'] ?? ''));

    if (!$file || !is_uploaded_file($file)) {
        flash('Выберите CSV-файл', 'err');
        redirect('import.php');
    }
    $raw = file_get_contents($file);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);          // BOM
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251'); // Excel
    $lines = preg_split('/\r\n|\n|\r/', trim($raw));
    $first = $lines[0] ?? '';
    $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    if (substr_count($first, "\t") > substr_count($first, $delim)) $delim = "\t";

    $header = array_map(fn($h) => mb_strtolower(trim($h)), str_getcsv($first, $delim));
    $map = ['name' => null, 'phone' => null, 'email' => null, 'desc' => null, 'source' => null];
    foreach ($header as $i => $h) {
        if ($map['name'] === null && preg_match('/фио|имя|name|клиент/u', $h)) $map['name'] = $i;
        elseif ($map['phone'] === null && preg_match('/тел|phone|номер|mobile/u', $h)) $map['phone'] = $i;
        elseif ($map['email'] === null && preg_match('/mail|почта/u', $h)) $map['email'] = $i;
        elseif ($map['desc'] === null && preg_match('/опис|desc|коммент|note|примеч/u', $h)) $map['desc'] = $i;
        elseif ($map['source'] === null && preg_match('/источ|source/u', $h)) $map['source'] = $i;
    }
    $hasHeader = $map['phone'] !== null;
    if (!$hasHeader) { $map = ['name' => 0, 'phone' => 1, 'email' => 2, 'desc' => 3, 'source' => 4]; } // без заголовка: ФИО;Телефон;Email;Описание;Источник

    $defStatus = isset(statuses()[(int)($_POST['status_id'] ?? 0)]) ? (int)$_POST['status_id'] : default_status_id();
    $perUser = [];
    $report = ['added' => 0, 'dup' => 0, 'bad' => 0, 'dups' => []];
    $k = 0;
    db()->beginTransaction();
    foreach (array_slice($lines, $hasHeader ? 1 : 0) as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line, $delim);
        $get = fn($key) => ($map[$key] !== null && isset($row[$map[$key]])) ? trim($row[$map[$key]]) : '';
        $phone = $get('phone');
        $norm = phone_normalize($phone);
        if (strlen($norm) < 7) { $report['bad']++; continue; }
        if (q('SELECT 1 FROM clients WHERE phone_norm = ?', [$norm])->fetchColumn()) {
            $report['dup']++;
            if (count($report['dups']) < 30) $report['dups'][] = $phone;
            continue;
        }
        $to = $targets ? $targets[$k++ % count($targets)] : null; // равномерно по кругу
        if ($to) $perUser[$to] = ($perUser[$to] ?? 0) + 1;
        $geo = geo_from_phone($norm);
        q('INSERT INTO clients (full_name, phone, phone_norm, email, geo_iso, geo_name, description, source, status_id, assigned_to, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            mb_substr($get('name'), 0, 200), phone_pretty($norm), $norm, mb_substr($get('email'), 0, 160), $geo['iso'], $geo['name'],
            $get('desc'), mb_substr($get('source') ?: $source, 0, 120), $defStatus, $to, $u['id']]);
        q('INSERT INTO client_history (client_id, user_id, action, details) VALUES (?,?,?,?)', [(int)db()->lastInsertId(), $u['id'], 'import', 'Ответственный: ' . user_name($to)]);
        $report['added']++;
    }
    db()->commit();
    foreach ($perUser as $uid => $cnt) if ($uid !== (int)$u['id']) notify($uid, "Вам назначено новых клиентов из импорта: $cnt");
    activity('import', "Добавлено {$report['added']}, дублей {$report['dup']}, ошибок {$report['bad']}");
}

layout_header($u, 'Импорт', 'import');
?>
<h1 class="page-title">Импорт клиентов из CSV</h1>

<?php if ($report): ?>
<div class="alert ok">Добавлено: <b><?= $report['added'] ?></b> · Дубликатов пропущено: <b><?= $report['dup'] ?></b> · Некорректных номеров: <b><?= $report['bad'] ?></b>
    <?php if ($report['dups']): ?><br><span class="small">Дубли: <?= e(implode(', ', $report['dups'])) ?><?= $report['dup'] > 30 ? '…' : '' ?></span><?php endif; ?>
</div>
<?php endif; ?>

<div class="grid-2">
<form method="post" enctype="multipart/form-data" class="panel form">
    <?= csrf_field() ?>
    <label>CSV-файл<input type="file" name="csv" accept=".csv,.txt" required></label>
    <label>Источник (если нет в файле)<input name="source" placeholder="напр. Facebook 24.11"></label>
    <label>Статус для загружаемых клиентов</label>
    <?= status_picker(default_status_id()) ?>
    <label class="mt">Распределить между <span class="muted">(можно выбрать несколько — лиды раздадутся по кругу поровну; пусто = не назначены)</span>
        <select name="to[]" multiple size="10">
            <?php foreach ($assignable as $a): ?>
                <option value="<?= $a['id'] ?>"><?= e($a['name']) ?> (<?= e(role_name($a['role'])) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn primary">Импортировать</button>
</form>
<div class="panel">
    <h3>Формат файла</h3>
    <p class="muted">Первая строка — заголовки. Колонки распознаются по названию: <b>ФИО</b>, <b>Телефон</b>, <b>Email</b>, <b>Описание</b>, <b>Источник</b>. Разделитель «;» или «,». Подходит сохранение из Excel «CSV (разделители — точка с запятой)».</p>
<pre class="code">ФИО;Телефон;Email;Описание
Иванов Иван;+380671234567;ivan@mail.com;Интересовался
Müller Hans;+49 151 2345678;;Перезвонить вечером</pre>
    <p class="muted small">ГЕО определяется автоматически по номеру. Номера, которые уже есть в базе, пропускаются.</p>
</div>
</div>
<?php layout_footer();
