<?php
/**
 * tools/mock_esp32.php
 * Hardware-free ESP32-S3 simulator implementing API Contract v2 & Amendment 1 timings.
 * Run:
 *   php -S 127.0.0.1:8081 tools/mock_esp32.php   (simulates SFD-01)
 *   php -S 127.0.0.1:8082 tools/mock_esp32.php   (simulates SFD-02)
 */

$port = (int)($_SERVER['SERVER_PORT'] ?? 8081);
$deviceId = getenv('MOCK_DEVICE_ID') ?: ($port === 8082 ? 'SFD-02' : 'SFD-01');
$apiKeyExpected = getenv('SMARTSCENT_API_KEY') ?: 'CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026';

const MOCK_FAN_PRE_S        = 0;
const MOCK_FAN_POST_S       = 5;
const MOCK_MILD_MIST_S      = 5;
const MOCK_MILD_CD_S        = 25;
const MOCK_STRONG_MIST_S    = 15;
const MOCK_STRONG_CD_S      = 15;
const MOCK_SETTLE_S         = 30;
const MOCK_MAX_10MIN_ON_S   = 300;
const MOCK_VOC_LOW          = 100;
const MOCK_VOC_HIGH         = 180;
const MOCK_VOC_HIGH_CLEAR   = 170;

$stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "smartscent_mock_{$deviceId}_{$port}.json";
$lockFile  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "smartscent_mock_{$deviceId}_{$port}.lock";

function mockDefaultState(string $deviceId, float $now): array {
    return [
        'device_id'            => $deviceId,
        'fw'                   => '2.0.0-mock',
        'boot_at'              => $now,
        'last_tick'            => $now,
        'power'                => true,
        'mode'                 => 'SENSOR',
        'profile'              => 'MILD',
        'interval_min'         => 60,
        'water'                => true,
        'voc_index'            => ($deviceId === 'SFD-02') ? 118 : 108,
        'voc_ready'            => true,
        'voc_source'           => 'SIMULATED',
        'voc_surge'            => 0.0,
        'voc_manual_hold'      => false,
        'baseline'             => 105,
        'high_voc_lock'        => false,
        'stage'                => 'IDLE', // IDLE | MIST | FAN_POST
        'stage_end'            => 0.0,
        'cooldown_end'         => 0.0,
        'last_spray_end'       => 0.0,
        'manual_fan_end'       => 0.0,
        'timed_next'           => $now + 3600.0,
        'mist_windows'         => [],
        'rolling_extra_on_s'   => 0.0,
        'sensors'              => ['sgp41' => 'SIMULATED', 'sht40' => 'MISSING'],
        'rssi'                 => ($deviceId === 'SFD-02') ? -62 : -56,
    ];
}

function mockRollingOnSeconds(array &$st, float $now): float {
    $cutoff = $now - 600.0;
    $kept = [];
    $sum = (float)($st['rolling_extra_on_s'] ?? 0.0);
    foreach ($st['mist_windows'] as $w) {
        if ($w['end'] <= $cutoff) {
            continue;
        }
        $s = max($w['start'], $cutoff);
        $e = min($w['end'], $now);
        if ($e > $s) {
            $sum += ($e - $s);
        }
        $kept[] = $w;
    }
    $st['mist_windows'] = $kept;
    return $sum;
}

function mockAdvance(array &$st, float $now): void {
    $dt = max(0.0, $now - (float)$st['last_tick']);
    $st['last_tick'] = $now;

    // 1. Power off safety override
    if (!$st['power']) {
        $st['stage'] = 'IDLE';
        $st['manual_fan_end'] = 0.0;
        $st['cooldown_end'] = 0.0;
    }

    // 2. Water empty or high VOC lock safety override
    if (!$st['water'] || $st['high_voc_lock']) {
        if ($st['stage'] === 'MIST') {
            $profile = $st['profile'];
            $cd = ($profile === 'STRONG') ? MOCK_STRONG_CD_S : MOCK_MILD_CD_S;
            $st['last_spray_end'] = $now;
            $st['cooldown_end'] = $now + $cd;
            if ($st['power']) {
                $st['stage'] = 'FAN_POST';
                $st['stage_end'] = $now + MOCK_FAN_POST_S;
            } else {
                $st['stage'] = 'IDLE';
            }
        }
    }

    // 3. Stage transitions (MIST -> FAN_POST -> IDLE)
    if ($st['stage'] === 'MIST' && $now >= (float)$st['stage_end']) {
        $cd = ($st['profile'] === 'STRONG') ? MOCK_STRONG_CD_S : MOCK_MILD_CD_S;
        $st['last_spray_end'] = (float)$st['stage_end'];
        $st['cooldown_end']   = (float)$st['stage_end'] + $cd;
        $st['stage']          = 'FAN_POST';
        $st['stage_end']      = (float)$st['stage_end'] + MOCK_FAN_POST_S;
    }

    if ($st['stage'] === 'FAN_POST' && $now >= (float)$st['stage_end']) {
        $st['stage'] = 'IDLE';
    }

    if ((float)$st['manual_fan_end'] > 0 && $now >= (float)$st['manual_fan_end']) {
        $st['manual_fan_end'] = 0.0;
    }

    // 4. Simulated VOC drift (unless pinned by __mock_set test)
    if (!$st['voc_manual_hold'] && $st['voc_source'] === 'SIMULATED' && $dt >= 0.8) {
        $steps = (int)min(60, floor($dt));
        for ($i = 0; $i < $steps; $i++) {
            if ($st['stage'] === 'MIST') {
                $st['voc_surge'] = min(60.0, (float)$st['voc_surge'] + 3.0);
            } else {
                $st['voc_surge'] = (float)$st['voc_surge'] * 0.972;
                if ($st['voc_surge'] < 0.2) {
                    $st['voc_surge'] = 0.0;
                }
            }
        }
        $base = ($st['device_id'] === 'SFD-02') ? 116 : 106;
        $noise = (($steps > 0) ? random_int(-2, 2) : 0);
        $st['voc_index'] = max(1, min(500, (int)round($base + (float)$st['voc_surge'] + $noise)));
    }

    // 5. High VOC lock hysteresis
    if ($st['voc_ready'] && $st['voc_index'] !== null) {
        if ((int)$st['voc_index'] >= MOCK_VOC_HIGH) {
            $st['high_voc_lock'] = true;
        } elseif ((int)$st['voc_index'] < MOCK_VOC_HIGH_CLEAR) {
            $st['high_voc_lock'] = false;
        }
    }

    // 6. Auto/Sensor/Timed automatic spray checks
    if ($st['power'] && $st['water'] && !$st['high_voc_lock'] && $st['stage'] === 'IDLE' && $now >= (float)$st['cooldown_end']) {
        $mode = $st['mode'];
        $wantSpray = false;

        if (($mode === 'TIMED') || ($mode === 'AUTO' && !$st['voc_ready'])) {
            if ($now >= (float)$st['timed_next']) {
                $wantSpray = true;
                $st['timed_next'] = $now + ($st['interval_min'] * 60);
            }
        } elseif (($mode === 'SENSOR' || $mode === 'AUTO') && $st['voc_ready']) {
            $settled = ($st['last_spray_end'] == 0.0) || (($now - (float)$st['last_spray_end']) >= MOCK_SETTLE_S);
            if ((int)$st['voc_index'] < MOCK_VOC_LOW && $settled) {
                $wantSpray = true;
            }
        }

        if ($wantSpray) {
            $mistS = ($st['profile'] === 'STRONG') ? MOCK_STRONG_MIST_S : MOCK_MILD_MIST_S;
            if ((mockRollingOnSeconds($st, $now) + $mistS) <= MOCK_MAX_10MIN_ON_S) {
                $st['stage'] = 'MIST';
                $st['stage_end'] = $now + $mistS;
                $st['mist_windows'][] = ['start' => $now, 'end' => $now + $mistS];
            }
        }
    }
}

function mockBuildTelemetry(array $st, float $now): array {
    $mist = ($st['power'] && $st['water'] && !$st['high_voc_lock'] && $st['stage'] === 'MIST');
    $fan  = ($st['power'] && ($st['stage'] === 'MIST' || $st['stage'] === 'FAN_POST' || ($now < (float)$st['manual_fan_end'])));

    $emptyLock = !$st['water'];
    $highLock  = (bool)$st['high_voc_lock'];

    if (!$st['power']) {
        $state = 'OFF';
    } elseif ($emptyLock || $highLock) {
        $state = 'LOCKED_OUT';
    } elseif ($st['stage'] === 'MIST') {
        $state = 'MISTING';
    } elseif ($now < (float)$st['cooldown_end']) {
        $state = 'COOLDOWN';
    } else {
        $state = 'IDLE';
    }

    $phase = match ($st['stage']) {
        'MIST'     => 'MIST',
        'FAN_POST' => 'FAN_POST',
        default    => 'NONE',
    };

    $cdRem = ($now < (float)$st['cooldown_end']) ? (int)ceil((float)$st['cooldown_end'] - $now) : 0;
    $usesTimed = ($st['mode'] === 'TIMED') || ($st['mode'] === 'AUTO' && !$st['voc_ready']);
    $nextTimed = ($st['power'] && $usesTimed) ? max(0, (int)ceil((float)$st['timed_next'] - $now)) : 0;

    return [
        'device_id'            => $st['device_id'],
        'fw'                   => $st['fw'],
        'uptime_s'             => max(0, (int)floor($now - (float)$st['boot_at'])),
        'power'                => (bool)$st['power'],
        'mode'                 => $st['mode'],
        'profile'              => $st['profile'],
        'interval_min'         => (int)$st['interval_min'],
        'state'                => $state,
        'phase'                => $phase,
        'status'               => $state,
        'water'                => (bool)$st['water'],
        'fan'                  => $fan,
        'mist'                 => $mist,
        'voc_index'            => $st['voc_ready'] ? (int)$st['voc_index'] : null,
        'voc_ready'            => (bool)$st['voc_ready'],
        'voc_source'           => $st['voc_source'],
        'baseline'             => (int)$st['baseline'],
        'thresholds'           => ['low' => MOCK_VOC_LOW, 'high' => MOCK_VOC_HIGH],
        'lock'                 => ['empty_tank' => $emptyLock, 'high_voc' => $highLock],
        'cooldown_remaining_s' => $cdRem,
        'next_timed_s'         => $nextTimed,
        'profiles'             => [
            'MILD'   => ['mist_s' => MOCK_MILD_MIST_S,   'cooldown_s' => MOCK_MILD_CD_S],
            'STRONG' => ['mist_s' => MOCK_STRONG_MIST_S, 'cooldown_s' => MOCK_STRONG_CD_S],
        ],
        'sensors'              => $st['sensors'],
        'rssi'                 => (int)$st['rssi'],
    ];
}

function mockSendJson(int $code, array $payload): void {
    http_response_code($code);
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'OPTIONS' && $uriPath === '/api/control') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');
    http_response_code(204);
    exit();
}

if ($method === 'GET' && $uriPath === '/') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo "{$deviceId} API v2: /telemetry, /api/control";
    exit();
}

// Acquire lock around shared mock state
$lockFp = fopen($lockFile, 'c+');
flock($lockFp, LOCK_EX);
$now = microtime(true);

$st = null;
if (is_file($stateFile)) {
    $loaded = json_decode((string)file_get_contents($stateFile), true);
    if (is_array($loaded)) {
        $st = $loaded;
    }
}
if ($st === null) {
    $st = mockDefaultState($deviceId, $now);
}

mockAdvance($st, $now);

if ($method === 'GET' && $uriPath === '/telemetry') {
    file_put_contents($stateFile, json_encode($st));
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    mockSendJson(200, mockBuildTelemetry($st, $now));
}

if ($method === 'POST' && $uriPath === '/__mock_set') {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!empty($body['reboot'])) {
        // Simulate mid-spray reboot: persisted settings survive, hardware outputs OFF
        $savedPower    = $st['power'];
        $savedMode     = $st['mode'];
        $savedProfile  = $st['profile'];
        $savedInterval = $st['interval_min'];
        $savedBase     = $st['baseline'];
        $st = mockDefaultState($deviceId, $now);
        $st['power']        = $savedPower;
        $st['mode']         = $savedMode;
        $st['profile']      = $savedProfile;
        $st['interval_min'] = $savedInterval;
        $st['baseline']     = $savedBase;
    }
    if (array_key_exists('water', $body))       $st['water'] = (bool)$body['water'];
    if (array_key_exists('voc_index', $body)) {
        $st['voc_index'] = (int)$body['voc_index'];
        $st['voc_manual_hold'] = true;
        $st['high_voc_lock'] = ((int)$body['voc_index'] >= MOCK_VOC_HIGH);
    }
    if (array_key_exists('voc_manual_hold', $body)) $st['voc_manual_hold'] = (bool)$body['voc_manual_hold'];
    if (array_key_exists('voc_ready', $body))       $st['voc_ready'] = (bool)$body['voc_ready'];
    if (array_key_exists('voc_source', $body))      $st['voc_source'] = (string)$body['voc_source'];
    if (array_key_exists('rolling_extra_on_s', $body)) $st['rolling_extra_on_s'] = (float)$body['rolling_extra_on_s'];
    if (array_key_exists('cooldown_end', $body))    $st['cooldown_end'] = (float)$body['cooldown_end'];
    if (is_array($body['sensors'] ?? null))         $st['sensors'] = array_merge($st['sensors'], $body['sensors']);

    mockAdvance($st, $now);
    file_put_contents($stateFile, json_encode($st));
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    mockSendJson(200, ['ok' => true, 'state' => mockBuildTelemetry($st, $now)]);
}

if ($method === 'POST' && $uriPath === '/api/control') {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($apiKeyExpected, $providedKey)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        mockSendJson(401, ['ok' => false, 'error' => 'UNAUTHORIZED']);
    }

    $req = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($req) || empty($req['command'])) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        mockSendJson(400, ['ok' => false, 'error' => 'INVALID_COMMAND']);
    }

    $cmd = (string)$req['command'];
    switch ($cmd) {
        case 'POWER':
            if (!is_bool($req['value'] ?? null)) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(400, ['ok' => false, 'error' => 'INVALID_VALUE']);
            }
            $st['power'] = $req['value'];
            if (!$st['power']) {
                $st['stage'] = 'IDLE';
                $st['cooldown_end'] = 0.0;
                $st['manual_fan_end'] = 0.0;
            } else {
                $st['timed_next'] = $now + ($st['interval_min'] * 60);
            }
            break;

        case 'SET_MODE':
            $m = $req['mode'] ?? '';
            if (!in_array($m, ['MANUAL', 'TIMED', 'SENSOR', 'AUTO'], true)) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(400, ['ok' => false, 'error' => 'INVALID_VALUE']);
            }
            $st['mode'] = $m;
            $st['timed_next'] = $now + ($st['interval_min'] * 60);
            break;

        case 'SET_PROFILE':
            $p = $req['profile'] ?? '';
            if (!in_array($p, ['MILD', 'STRONG'], true)) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(400, ['ok' => false, 'error' => 'INVALID_VALUE']);
            }
            $st['profile'] = $p;
            break;

        case 'SET_INTERVAL':
            $mins = $req['minutes'] ?? null;
            if (!is_int($mins) || !in_array($mins, [30, 60, 120], true)) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(400, ['ok' => false, 'error' => 'INVALID_VALUE']);
            }
            $st['interval_min'] = $mins;
            $st['timed_next'] = $now + ($mins * 60);
            break;

        case 'SPRAY_NOW':
            if (!$st['power']) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'POWER_OFF']);
            }
            if (!$st['water']) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'TANK_EMPTY']);
            }
            if ($st['high_voc_lock']) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'HIGH_VOC_LOCK']);
            }
            if ($st['stage'] === 'MIST') {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'BUSY']);
            }
            if ($now < (float)$st['cooldown_end']) {
                $rem = (int)ceil((float)$st['cooldown_end'] - $now);
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'COOLDOWN_ACTIVE', 'remaining_s' => $rem]);
            }
            $mistS = ($st['profile'] === 'STRONG') ? MOCK_STRONG_MIST_S : MOCK_MILD_MIST_S;
            if ((mockRollingOnSeconds($st, $now) + $mistS) > MOCK_MAX_10MIN_ON_S) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'THERMAL_LIMIT']);
            }
            $st['manual_fan_end'] = 0.0;
            $st['stage'] = 'MIST';
            $st['stage_end'] = $now + $mistS;
            $st['timed_next'] = $now + ($st['interval_min'] * 60);
            $st['mist_windows'][] = ['start' => $now, 'end' => $now + $mistS];
            break;

        case 'FAN':
            if (!is_bool($req['value'] ?? null)) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(400, ['ok' => false, 'error' => 'INVALID_VALUE']);
            }
            if (!$st['power']) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'POWER_OFF']);
            }
            if ($st['stage'] === 'MIST' || $st['stage'] === 'FAN_POST') {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'BUSY']);
            }
            if ($req['value']) {
                $dur = $req['duration_s'] ?? 5;
                if (!is_int($dur) || $dur < 1 || $dur > 10) {
                    flock($lockFp, LOCK_UN); fclose($lockFp);
                    mockSendJson(400, ['ok' => false, 'error' => 'INVALID_VALUE']);
                }
                $st['manual_fan_end'] = $now + $dur;
            } else {
                $st['manual_fan_end'] = 0.0;
            }
            break;

        case 'SET_BASELINE':
            if (!$st['voc_ready']) {
                flock($lockFp, LOCK_UN); fclose($lockFp);
                mockSendJson(409, ['ok' => false, 'error' => 'BUSY']);
            }
            $st['baseline'] = (int)$st['voc_index'];
            break;

        default:
            flock($lockFp, LOCK_UN); fclose($lockFp);
            mockSendJson(400, ['ok' => false, 'error' => 'INVALID_COMMAND']);
    }

    file_put_contents($stateFile, json_encode($st));
    $out = mockBuildTelemetry($st, $now);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    mockSendJson(200, ['ok' => true, 'result' => 'ACCEPTED', 'state' => $out]);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);
mockSendJson(404, ['ok' => false, 'error' => 'NOT_FOUND']);