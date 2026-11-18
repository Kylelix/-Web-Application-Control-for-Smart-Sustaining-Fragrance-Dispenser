<?php
/**
 * api/export_csv.php
 * Admin-only CSV exporter for [activity_log] with UTF-8 BOM and formula-injection defense.
 */

require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/device_gateway.php';

requireLogin();
enforceIdleTimeout(false, true);
verifyAccountStillActive(false);

if (!isAdmin($_SESSION['smartscent_role'] ?? '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: Administrator privileges required.';
    exit();
}

session_write_close();

if ($conn === false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database unavailable.';
    exit();
}

function csvSafeCell($value): string {
    if ($value === null) {
        return '';
    }
    $str = (string)$value;
    if ($str !== '' && preg_match('/^[=+\-@\t\r]/', $str)) {
        return "'" . $str;
    }
    return $str;
}

$conditions = ['1=1'];
$params = [];

$devParam = strtoupper(trim((string)($_GET['device'] ?? '')));
if ($devParam !== '' && $devParam !== 'ALL') {
    $devId = sanitizeDeviceId($devParam);
    if ($devId === null) {
        http_response_code(400);
        exit('Invalid device parameter.');
    }
    $conditions[] = 'device_id = ?';
    $params[] = $devId;
}

$from = trim((string)($_GET['from'] ?? ''));
if ($from !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        http_response_code(400);
        exit('Invalid from date.');
    }
    $conditions[] = 'CAST(logged_at AS DATE) >= ?';
    $params[] = $from;
}

$to = trim((string)($_GET['to'] ?? ''));
if ($to !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        http_response_code(400);
        exit('Invalid to date.');
    }
    $conditions[] = 'CAST(logged_at AS DATE) <= ?';
    $params[] = $to;
}

$whereSql = implode(' AND ', $conditions);
$sql = "SELECT logged_at, device_id, event, voc_index, voc_status, water_ok, mode, profile, state, actor_role, detail
        FROM activity_log
        WHERE {$whereSql}
        ORDER BY logged_at DESC, id DESC";

$stmt = @sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    exit('Database query failed.');
}

$filename = 'smartscent_activity_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
// UTF-8 BOM for clean Excel rendering
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'timestamp',
    'device_id',
    'event',
    'voc_index',
    'voc_status',
    'tank',
    'mode',
    'profile',
    'state',
    'actor_role',
    'detail'
]);

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $ts = $row['logged_at'] instanceof DateTimeInterface ? $row['logged_at']->format('Y-m-d H:i:s') : '';
    $tank = $row['water_ok'] === null ? '' : ((int)$row['water_ok'] === 1 ? 'OK' : 'EMPTY');

    fputcsv($out, [
        csvSafeCell($ts),
        csvSafeCell($row['device_id']),
        csvSafeCell($row['event']),
        csvSafeCell($row['voc_index']),
        csvSafeCell($row['voc_status']),
        csvSafeCell($tank),
        csvSafeCell($row['mode']),
        csvSafeCell($row['profile']),
        csvSafeCell($row['state']),
        csvSafeCell($row['actor_role']),
        csvSafeCell($row['detail']),
    ]);
}

sqlsrv_free_stmt($stmt);
fclose($out);
exit();