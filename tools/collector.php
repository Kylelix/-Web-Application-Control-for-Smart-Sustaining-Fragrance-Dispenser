<?php
/**
 * tools/collector.php
 * CLI background telemetry collector: polls all enabled devices every 2 seconds
 * and persists transitions + 30s SAMPLE rows to SQL Server even without open browsers.
 * Usage: php tools/collector.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/device_gateway.php';

echo "SmartScent Telemetry Collector started (2s loop). Press Ctrl+C to stop.\n";

while (true) {
    if ($conn === false) {
        $conn = @sqlsrv_connect($serverName, $connectionOptions);
        if ($conn === false) {
            echo "[" . date('H:i:s') . "] Database offline; retrying in 2s...\n";
            sleep(2);
            continue;
        }
    }

    $devices = getAllDevices($conn, true);
    foreach ($devices as $dev) {
        $res = getTelemetryCached($conn, $dev, true);
        $state = $res['online'] ? ($res['raw']['state'] ?? 'ONLINE') : 'OFFLINE';
        $voc = ($res['online'] && isset($res['raw']['voc_index'])) ? $res['raw']['voc_index'] : '--';
        echo "[" . date('H:i:s') . "] {$dev['device_id']} ({$dev['host']}) -> {$state} | VOC={$voc}\n";
    }

    sleep(2);
}