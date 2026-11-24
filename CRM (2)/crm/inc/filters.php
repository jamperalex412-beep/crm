<?php
/** Условия выборки клиентов по фильтрам (используется в списке и экспорте). */
function filters_from_get(): array
{
    return [
        'q'      => trim((string)($_GET['q'] ?? '')),
        'status' => (string)($_GET['status'] ?? ''),
        'geo'    => (string)($_GET['geo'] ?? ''),
        'owner'  => (string)($_GET['owner'] ?? ''),
        'cb'     => (string)($_GET['cb'] ?? ''),
        'from'   => (string)($_GET['from'] ?? ''),
        'to'     => (string)($_GET['to'] ?? ''),
    ];
}

function client_filters(array $u, array $f): array
{
    $owners = visible_owner_ids($u);
    $where = [];
    $params = [];
    if ($owners !== null) {
        $where[] = 'c.assigned_to IN (' . implode(',', array_fill(0, count($owners), '?')) . ')';
        array_push($params, ...$owners);
    }
    if ($f['q'] !== '') {
        $digits = preg_replace('/\D+/', '', $f['q']);
        if (ctype_digit($f['q']) && strlen($f['q']) <= 7) {          // похоже на ID
            $where[] = '(c.id = ? OR c.phone_norm LIKE ?)';
            array_push($params, (int)$f['q'], "%$digits%");
        } elseif (strlen($digits) >= 4) {
            $where[] = '(c.full_name LIKE ? OR c.phone_norm LIKE ? OR c.email LIKE ?)';
            array_push($params, "%{$f['q']}%", "%$digits%", "%{$f['q']}%");
        } else {
            $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.description LIKE ?)';
            array_push($params, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
        }
    }
    if (($f['cb'] ?? '') === 'due') { $where[] = 'c.callback_at IS NOT NULL AND c.callback_at <= NOW()'; }
    if (($f['cb'] ?? '') === 'today') { $where[] = 'c.callback_at >= CURDATE() AND c.callback_at < CURDATE() + INTERVAL 1 DAY'; }
    if (($f['cb'] ?? '') === 'any') { $where[] = 'c.callback_at IS NOT NULL'; }
    if (!empty($f['from']) && strtotime($f['from'])) { $where[] = 'c.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($f['from'])); }
    if (!empty($f['to']) && strtotime($f['to'])) { $where[] = 'c.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($f['to'])); }
    if ($f['geo'] !== '') { $where[] = 'c.geo_iso = ?'; $params[] = $f['geo']; }
    if ($f['owner'] === 'none') { $where[] = 'c.assigned_to IS NULL'; }
    elseif ($f['owner'] !== '') { $where[] = 'c.assigned_to = ?'; $params[] = (int)$f['owner']; }

    $whereNoStatus = $where;
    $paramsNoStatus = $params;
    if ($f['status'] === 'none') { $where[] = 'c.status_id IS NULL'; }
    elseif ($f['status'] !== '') { $where[] = 'c.status_id = ?'; $params[] = (int)$f['status']; }
    return [$where, $params, $whereNoStatus, $paramsNoStatus];
}
