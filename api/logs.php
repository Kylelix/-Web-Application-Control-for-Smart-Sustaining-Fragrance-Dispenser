<?php
/**
 * api/logs.php
 * Fetches live activity logs, notifications, and VOC trend samples from SQL Server.
 * Also supports Admin-only log clearing (POST + CSRF).
 */

require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/device_gateway.php';

if ($conn === false) {
    jsonResponse(['ok' => false, 'error' => 'DATABASE_OFFLINE', 'message' => 'Database connection unavailable.'], 503);
}

// POST: Admin-only clear logs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminApi();
    $rawBody = file_get_contents('php://input');
    $input = $rawBody ? json_decode($rawBody, true) : [];
    if (!is_array($input) || !csrfCheck($input['csrf'] ?? null)) {
        session_write_close();
        jsonResponse(['ok' => false, 'error' => 'CSRF_FAILED', 'message' => 'Security token invalid.'], 403);
    }
    session_write_close();

    $action = strtolower(trim((string)($input['action'] ?? '')));
    if ($action !== 'clear') {
        jsonResponse(['ok' => false, 'error' => 'INVALID_COMMAND', 'message' => 'Unsupported logs action.'], 400);
    }

    $devParam = strtoupper(trim((string)($input['device'] ?? 'ALL')));
    if ($devParam === 'ALL' || $devParam === '') {
        $stmt = @sqlsrv_query($conn, 'DELETE FROM activity_log');
    } else {
        $devId = sanitizeDeviceId($devParam);
        if ($devId === null) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Invalid device ID.'], 400);
        }
        $stmt = @sqlsrv_query($conn, 'DELETE FROM activity_log WHERE device_id = ?', [$devId]);
    }

    if ($stmt === false) {
        jsonResponse(['ok' => false, 'error' => 'DB_ERROR', 'message' => 'Failed to clear logs.'], 500);
    }
    $deleted = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    jsonResponse([
        'ok'      => true,
        'deleted' => max(0, (int)$deleted),
        'message' => 'Activity logs cleared.'
    ], 200);
}

// GET: Read logs / notifications / VOC trend
requireLoginApi(false);

$role    = $_SESSION['smartscent_role'] ?? 'client';
$room    = $_SESSION['smartscent_room'] ?? null;
$isAdmin = isAdmin($role);

$reqDevice = strtoupper(trim((string)($_GET['device'] ?? '')));
if ($isAdmin) {
    if ($reqDevice === 'ALL') {
        $deviceFilter = 'ALL';
    } else {
        $deviceFilter = sanitizeDeviceId($reqDevice !== '' ? $reqDevice : 'SFD-01') ?? 'SFD-01';
    }
} else {
    $deviceFilter = getDeviceForRoom($room) ?? '';
}

session_write_close();

$type = strtolower(trim((string)($_GET['type'] ?? 'table')));

if ($type === 'notifications') {
    $where = "(event IN ('LOCK_ON','LOCK_OFF','OFFLINE','ONLINE','COMMAND','BASELINE') OR water_ok = 0)";
    $params = [];
    if ($deviceFilter !== 'ALL') {
        $where .= ' AND device_id = ?';
        $params[] = $deviceFilter;
    }

    $sql = "SELECT TOP 5 id, logged_at, device_id, event, actor_role, voc_index, voc_status, water_ok, mode, profile, state, detail
            FROM activity_log
            WHERE {$where}
            ORDER BY logged_at DESC, id DESC";
    $stmt = @sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        jsonResponse(['ok' => false, 'error' => 'DB_ERROR', 'message' => 'Failed to load notifications.'], 500);
    }

    $items = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dt = $row['logged_at'] instanceof DateTimeInterface ? $row['logged_at']->format('H:i:s') : '';
        $ev = strtoupper((string)$row['event']);
        $title = match ($ev) {
            'ONLINE'   => 'Device connected',
            'OFFLINE'  => 'Device offline',
            'LOCK_ON'  => 'Safety lock activated',
            'LOCK_OFF' => 'Safety lock cleared',
            'BASELINE' => 'Baseline recorded',
            'COMMAND'  => 'Control command',
            default    => ($row['water_ok'] === 0 ? 'Tank empty' : $ev),
        };
        $msg = (string)($row['detail'] ?? '');
        if ($msg === '') {
            $msg = "{$row['device_id']} state: {$row['state']}";
        }
        $items[] = [
            'id'        => (int)$row['id'],
            'device_id' => (string)$row['device_id'],
            'event'     => $ev,
            'title'     => $title,
            'message'   => $msg,
            'time'      => $dt,
        ];
    }
    sqlsrv_free_stmt($stmt);

    jsonResponse(['ok' => true, 'items' => $items], 200);
}

if ($type === 'trend') {
    $limit = max(1, min(24, (int)($_GET['limit'] ?? 7)));
    $targetDev = ($deviceFilter === 'ALL') ? 'SFD-01' : $deviceFilter;

    $sql = "SELECT TOP (?) logged_at, voc_index, voc_status
            FROM activity_log
            WHERE device_id = ? AND event = 'SAMPLE' AND voc_index IS NOT NULL
            ORDER BY logged_at DESC, id DESC";
    $stmt = @sqlsrv_query($conn, $sql, [$limit, $targetDev]);
    if ($stmt === false) {
        jsonResponse(['ok' => false, 'error' => 'DB_ERROR', 'message' => 'Failed to load VOC trend.'], 500);
    }

    $points = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $timeStr = $row['logged_at'] instanceof DateTimeInterface ? $row['logged_at']->format('H:i') : '';
        $points[] = [
            'time'       => $timeStr,
            'voc_index'  => (int)$row['voc_index'],
            'voc_status' => strtoupper((string)($row['voc_status'] ?? 'NORMAL')),
        ];
    }
    sqlsrv_free_stmt($stmt);

    // Reverse to oldest -> newest for left-to-right chart rendering
    jsonResponse(['ok' => true, 'device_id' => $targetDev, 'points' => array_reverse($points)], 200);
}

// Default: paginated activity log table
$limit = max(1, min(50, (int)($_GET['limit'] ?? 8)));
$page  = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$conditions = ['1=1'];
$params = [];

if ($deviceFilter !== 'ALL') {
    $conditions[] = 'device_id = ?';
    $params[] = $deviceFilter;
}

$dateParam = trim((string)($_GET['date'] ?? ''));
if ($dateParam !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
        jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Invalid date filter (use YYYY-MM-DD).'], 400);
    }
    $conditions[] = 'CAST(logged_at AS DATE) = ?';
    $params[] = $dateParam;
}

$whereSql = implode(' AND ', $conditions);

// Count total matching rows
$countSql = "SELECT COUNT(*) AS cnt FROM activity_log WHERE {$whereSql}";
$countStmt = @sqlsrv_query($conn, $countSql, $params);
if ($countStmt === false) {
    jsonResponse(['ok' => false, 'error' => 'DB_ERROR', 'message' => 'Failed to count logs.'], 500);
}
$countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($countStmt);
$total = (int)($countRow['cnt'] ?? 0);
$pages = max(1, (int)ceil($total / $limit));

$dataSql = "SELECT id, logged_at, device_id, event, actor_role, voc_index, voc_status, water_ok, mode, profile, state, detail
            FROM activity_log
            WHERE {$whereSql}
            ORDER BY logged_at DESC, id DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
$dataParams = array_merge($params, [$offset, $limit]);
$stmt = @sqlsrv_query($conn, $dataSql, $dataParams);
if ($stmt === false) {
    jsonResponse(['ok' => false, 'error' => 'DB_ERROR', 'message' => 'Failed to query activity logs.'], 500);
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $tsShort = $row['logged_at'] instanceof DateTimeInterface ? $row['logged_at']->format('H:i:s') : '';
    $tsFull  = $row['logged_at'] instanceof DateTimeInterface ? $row['logged_at']->format('Y-m-d H:i:s') : '';
    $waterOk = $row['water_ok'];

    $rows[] = [
        'id'          => (int)$row['id'],
        'device_id'   => (string)$row['device_id'],
        'time'        => $tsShort,
        'timestamp'   => $tsFull,
        'event'       => strtoupper((string)$row['event']),
        'actor_role'  => (string)($row['actor_role'] ?? 'system'),
        'voc_index'   => $row['voc_index'] !== null ? (int)$row['voc_index'] : null,
        'voc_status'  => (string)($row['voc_status'] ?? 'UNKNOWN'),
        'water_ok'    => $waterOk !== null ? ((int)$waterOk === 1) : null,
        'tank_status' => $waterOk === null ? '--' : ((int)$waterOk === 1 ? 'OK' : 'EMPTY'),
        'mode'        => (string)($row['mode'] ?? '--'),
        'profile'     => (string)($row['profile'] ?? '--'),
        'state'       => (string)($row['state'] ?? '--'),
        'detail'      => (string)($row['detail'] ?? ''),
    ];
}
sqlsrv_free_stmt($stmt);

jsonResponse([
    'ok'        => true,
    'device_id' => $deviceFilter,
    'page'      => $page,
    'limit'     => $limit,
    'total'     => $total,
    'pages'     => $pages,
    'rows'      => $rows,
], 200);