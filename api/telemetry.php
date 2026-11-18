<?php
/**
 * api/telemetry.php
 * Authenticated telemetry gateway endpoint for browsers.
 * Never exposes device host/IP, API key, SHT40 readings, or client-forbidden diagnostics.
 */

require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/device_gateway.php';

if ($conn === false) {
    jsonResponse([
        'ok'      => false,
        'error'   => 'DATABASE_OFFLINE',
        'message' => 'Database connection unavailable.'
    ], 503);
}

// Passive telemetry poll checks session/checkout without extending idle timeout
requireLoginApi(false);

$role    = $_SESSION['smartscent_role'] ?? 'client';
$room    = $_SESSION['smartscent_room'] ?? null;
$isAdmin = isAdmin($role);

if ($isAdmin) {
    $deviceId = sanitizeDeviceId($_GET['device'] ?? 'SFD-01') ?? 'SFD-01';
} else {
    // Clients always use their session room device; ignore any query param
    $deviceId = getDeviceForRoom($room) ?? '';
}

// Release PHP session lock immediately so parallel requests never stall
session_write_close();

$device = getDevice($conn, $deviceId);
if (!$device) {
    jsonResponse([
        'ok'      => false,
        'error'   => 'DEVICE_NOT_FOUND',
        'message' => 'Assigned device not found.'
    ], 404);
}

$cached     = getTelemetryCached($conn, $device);
$normalized = normalizeTelemetry($cached['raw'], $isAdmin);

$lastSeen = $cached['last_seen_iso'];
if ($lastSeen === null && isset($device['last_seen']) && $device['last_seen'] instanceof DateTimeInterface) {
    $lastSeen = $device['last_seen']->format('Y-m-d H:i:s');
}

jsonResponse([
    'ok'          => true,
    'device_id'   => (string)$device['device_id'],
    'room_number' => $device['room_number'],
    'enabled'     => ((int)$device['is_enabled'] === 1),
    'online'      => (bool)$cached['online'],
    'stale_ms'    => (int)$cached['stale_ms'],
    'last_seen'   => $lastSeen,
    'server_time' => date('c'),
    'data'        => $normalized,
    'permissions' => getPermissions($role),
], 200);