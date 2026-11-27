<?php
/**
 * api/control.php
 * Authenticated, CSRF-protected, role-checked command gateway to ESP32-S3 devices.
 */

require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/device_gateway.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required.'], 405);
}

if ($conn === false) {
    jsonResponse(['ok' => false, 'error' => 'DATABASE_OFFLINE', 'message' => 'Database connection unavailable.'], 503);
}

requireLoginApi(true);

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) === 0 || strlen($rawBody) > 2048) {
    jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Invalid request payload size.'], 400);
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    jsonResponse(['ok' => false, 'error' => 'INVALID_JSON', 'message' => 'Malformed JSON payload.'], 400);
}

if (!csrfCheck($input['csrf'] ?? null)) {
    jsonResponse(['ok' => false, 'error' => 'CSRF_FAILED', 'message' => 'Security token invalid or expired. Refresh the page.'], 403);
}

$role    = $_SESSION['smartscent_role'] ?? 'client';
$room    = $_SESSION['smartscent_room'] ?? null;
$isAdmin = isAdmin($role);

// Rate limit: max 5 commands per 10 s per session
$now = time();
$rateLog = $_SESSION['ctrl_rate_ts'] ?? [];
$rateLog = array_values(array_filter($rateLog, fn($ts) => is_int($ts) && ($now - $ts) < CONTROL_RATE_LIMIT_WIN_S));
if (count($rateLog) >= CONTROL_RATE_LIMIT_MAX) {
    $_SESSION['ctrl_rate_ts'] = $rateLog;
    session_write_close();
    jsonResponse([
        'ok'      => false,
        'error'   => 'RATE_LIMITED',
        'message' => 'Too many commands sent. Please wait a few seconds.'
    ], 429);
}
$rateLog[] = $now;
$_SESSION['ctrl_rate_ts'] = $rateLog;

// Resolve device and reject Client device tampering
$ownDevice = getDeviceForRoom($room);
if (!$isAdmin) {
    if (isset($input['device'])) {
        $requested = sanitizeDeviceId((string)$input['device']);
        if ($requested !== $ownDevice) {
            session_write_close();
            jsonResponse([
                'ok'      => false,
                'error'   => 'FORBIDDEN',
                'message' => 'Guests can only control their assigned room device.'
            ], 403);
        }
    }
    $targetDeviceId = $ownDevice ?? '';
} else {
    $targetDeviceId = isset($input['device']) ? (sanitizeDeviceId((string)$input['device']) ?? '') : 'SFD-01';
}

// Release session lock before validating/forwarding
session_write_close();

$allCommands   = ['POWER', 'SET_MODE', 'SET_PROFILE', 'SET_INTERVAL', 'SPRAY_NOW', 'FAN', 'SET_BASELINE'];
$clientAllowed = ['POWER', 'SET_MODE', 'SET_PROFILE', 'SET_INTERVAL', 'SPRAY_NOW'];

$command = strtoupper(trim((string)($input['command'] ?? '')));
if (!in_array($command, $allCommands, true)) {
    jsonResponse(['ok' => false, 'error' => 'INVALID_COMMAND', 'message' => 'Unknown control command.'], 400);
}

if (!$isAdmin && !in_array($command, $clientAllowed, true)) {
    jsonResponse([
        'ok'      => false,
        'error'   => 'FORBIDDEN',
        'message' => 'Only the administrator can change this setting.'
    ], 403);
}

// Strict payload whitelist validation before contacting device
$forwardPayload = ['command' => $command];
$detailText = $command;

switch ($command) {
    case 'POWER':
        if (!array_key_exists('value', $input) || !is_bool($input['value'])) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'POWER requires boolean value.'], 400);
        }
        $forwardPayload['value'] = $input['value'];
        $detailText = $input['value'] ? 'Power ON command' : 'Power OFF command';
        break;

    case 'SET_MODE':
        $mode = strtoupper(trim((string)($input['mode'] ?? '')));
        if (!in_array($mode, ['MANUAL', 'TIMED', 'SENSOR', 'AUTO'], true)) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Invalid operating mode.'], 400);
        }
        $forwardPayload['mode'] = $mode;
        $detailText = 'Mode set to ' . $mode;
        break;

    case 'SET_PROFILE':
        $profile = strtoupper(trim((string)($input['profile'] ?? '')));
        if (!in_array($profile, ['MILD', 'STRONG'], true)) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Profile must be MILD or STRONG.'], 400);
        }
        $forwardPayload['profile'] = $profile;
        $detailText = 'Dose profile set to ' . $profile;
        break;

    case 'SET_INTERVAL':
        $rawMin = $input['minutes'] ?? null;
        if (is_bool($rawMin) || !is_numeric($rawMin) || (int)$rawMin != $rawMin) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Interval must be 30, 60, or 120.'], 400);
        }
        $minutes = (int)$rawMin;
        if (!in_array($minutes, [30, 60, 120], true)) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Interval must be 30, 60, or 120 minutes.'], 400);
        }
        $forwardPayload['minutes'] = $minutes;
        $detailText = "Interval set to {$minutes} min";
        break;

    case 'SPRAY_NOW':
        $detailText = 'Manual Spray Now command';
        break;

    case 'FAN':
        if (!array_key_exists('value', $input) || !is_bool($input['value'])) {
            jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'FAN requires boolean value.'], 400);
        }
        $forwardPayload['value'] = $input['value'];
        if ($input['value']) {
            $rawDur = $input['duration_s'] ?? 5;
            if (is_bool($rawDur) || !is_numeric($rawDur) || (int)$rawDur != $rawDur || (int)$rawDur < 1 || (int)$rawDur > 10) {
                jsonResponse(['ok' => false, 'error' => 'INVALID_VALUE', 'message' => 'Fan duration_s must be 1 to 10.'], 400);
            }
            $forwardPayload['duration_s'] = (int)$rawDur;
            $detailText = 'Fan test ON (' . (int)$rawDur . ' s)';
        } else {
            $detailText = 'Fan test OFF';
        }
        break;

    case 'SET_BASELINE':
        $detailText = 'VOC baseline calibration updated';
        break;
}

$device = getDevice($conn, $targetDeviceId);
if (!$device) {
    jsonResponse(['ok' => false, 'error' => 'DEVICE_NOT_FOUND', 'message' => 'Target device not registered.'], 404);
}
if ((int)$device['is_enabled'] !== 1) {
    jsonResponse(['ok' => false, 'error' => 'DEVICE_DISABLED', 'message' => 'Target device is disabled by administrator.'], 503);
}

// Enforce server-side SPRAY_MIN_GAP_S guard for SPRAY_NOW
if ($command === 'SPRAY_NOW') {
    $gapRemain = checkAndRecordSprayGap($targetDeviceId);
    if ($gapRemain !== null) {
        jsonResponse([
            'ok'          => false,
            'error'       => 'COOLDOWN_ACTIVE',
            'remaining_s' => $gapRemain,
            'message'     => "Cooldown: {$gapRemain} s remaining before next spray request.",
        ], 409);
    }
}

$resp = deviceRequest(
    (string)$device['host'],
    'POST',
    '/api/control',
    $forwardPayload,
    ['X-Api-Key: ' . DEVICE_API_KEY]
);

if ($resp['http_code'] === 0 || $resp['data'] === null) {
    jsonResponse([
        'ok'      => false,
        'error'   => 'DEVICE_OFFLINE',
        'message' => 'Device is offline or unreachable.'
    ], 503);
}

$actorRole = $isAdmin ? 'admin' : 'client';
$devData   = $resp['data'];

if ($resp['http_code'] === 200 && !empty($devData['ok'])) {
    $rawState = is_array($devData['state'] ?? null) ? $devData['state'] : [];
    if (!empty($rawState)) {
        primeTelemetryCache($conn, $targetDeviceId, $rawState);
    }
    $normState = normalizeTelemetry($rawState, $isAdmin);
    logEvent($conn, $targetDeviceId, 'COMMAND', $actorRole, $normState, $detailText);
    if ($command === 'SET_BASELINE') {
        logEvent($conn, $targetDeviceId, 'BASELINE', $actorRole, $normState, 'Baseline set to ' . $normState['baseline']);
    }

    jsonResponse([
        'ok'      => true,
        'result'  => 'ACCEPTED',
        'message' => $detailText . ' accepted.',
        'data'    => $normState,
    ], 200);
}

// Map device refusal codes cleanly
$errCode = strtoupper((string)($devData['error'] ?? 'DEVICE_REFUSED'));
$remS    = isset($devData['remaining_s']) ? (int)$devData['remaining_s'] : 0;

$friendlyMap = [
    'TANK_EMPTY'      => 'Misting disabled due to empty tank lock.',
    'HIGH_VOC_LOCK'   => 'Misting disabled due to high VOC lock. Ventilation recommended.',
    'POWER_OFF'       => 'Device is powered off.',
    'COOLDOWN_ACTIVE' => "Cooldown: {$remS} s remaining.",
    'THERMAL_LIMIT'   => 'Atomizer cooling down to protect the device.',
    'BUSY'            => 'Device is currently busy with an active cycle.',
    'UNAUTHORIZED'    => 'Gateway API key rejected by device firmware.',
    'INVALID_COMMAND' => 'Device rejected command format.',
    'INVALID_VALUE'   => 'Device rejected command value.',
];

$httpStatus = match ($errCode) {
    'UNAUTHORIZED'                    => 502,
    'INVALID_COMMAND', 'INVALID_VALUE' => 400,
    default                           => ($resp['http_code'] >= 400 ? $resp['http_code'] : 409),
};

$out = [
    'ok'      => false,
    'error'   => $errCode,
    'message' => $friendlyMap[$errCode] ?? ("Device refused command ({$errCode})."),
];
if ($errCode === 'COOLDOWN_ACTIVE') {
    $out['remaining_s'] = $remS;
}

jsonResponse($out, $httpStatus);