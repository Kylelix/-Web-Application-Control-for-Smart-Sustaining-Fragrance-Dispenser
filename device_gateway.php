<?php
/**
 * device_gateway.php
 * Server-side ESP32-S3 telemetry caching, HTTP gateway, state-transition logging,
 * and strict payload normalization / permission derivation.
 */

require_once __DIR__ . '/config.php';

function sanitizeDeviceId(?string $deviceId): ?string {
    if ($deviceId === null) {
        return null;
    }
    $clean = strtoupper(trim($deviceId));
    if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $clean)) {
        return null;
    }
    return $clean;
}

function getDevice($conn, string $deviceId): ?array {
    $cleanId = sanitizeDeviceId($deviceId);
    if ($cleanId === null || !$conn) {
        return null;
    }
    $stmt = @sqlsrv_query(
        $conn,
        'SELECT device_id, room_number, host, is_enabled, firmware, last_seen, created_at
         FROM devices WHERE device_id = ?',
        [$cleanId]
    );
    if ($stmt === false) {
        return null;
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function getAllDevices($conn, bool $onlyEnabled = false): array {
    if (!$conn) {
        return [];
    }
    $sql = 'SELECT device_id, room_number, host, is_enabled, firmware, last_seen, created_at FROM devices';
    if ($onlyEnabled) {
        $sql .= ' WHERE is_enabled = 1';
    }
    $sql .= ' ORDER BY device_id ASC';
    $stmt = @sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return [];
    }
    $list = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $list[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $list;
}

function vocStatus(?int $vocIndex, ?array $thresholds = null): string {
    if ($vocIndex === null) {
        return 'UNKNOWN';
    }
    $low  = (int)($thresholds['low']  ?? VOC_THRESHOLD_LOW);
    $high = (int)($thresholds['high'] ?? VOC_THRESHOLD_HIGH);
    if ($vocIndex < $low) {
        return 'LOW';
    }
    if ($vocIndex < $high) {
        return 'NORMAL';
    }
    return 'HIGH';
}

function tankStatus(bool $waterOk): string {
    return $waterOk ? 'OK' : 'EMPTY';
}

function getPermissions(string $role): array {
    $admin = (strtolower($role) === 'admin');
    return [
        'power'         => true,
        'profile'       => true,
        'spray'         => true,
        'mode'          => $admin,
        'interval'      => $admin,
        'fan'           => $admin,
        'baseline'      => $admin,
        'export'        => $admin,
        'switch_device' => $admin,
    ];
}

function normalizeTelemetry(?array $raw, bool $includeAdminSensors = false): array {
    $raw = $raw ?? [];

    $allowedModes    = ['MANUAL', 'TIMED', 'SENSOR', 'AUTO'];
    $allowedProfiles = ['MILD', 'STRONG'];
    $allowedStates   = ['OFF', 'IDLE', 'MISTING', 'COOLDOWN', 'LOCKED_OUT'];
    $allowedPhases   = ['NONE', 'FAN_PRE', 'MIST', 'FAN_POST'];
    $allowedSources  = ['SGP41', 'SIMULATED', 'NONE'];

    $power = (bool)($raw['power'] ?? false);

    $mode = strtoupper((string)($raw['mode'] ?? 'SENSOR'));
    if (!in_array($mode, $allowedModes, true)) {
        $mode = 'SENSOR';
    }

    $profile = strtoupper((string)($raw['profile'] ?? 'MILD'));
    if (!in_array($profile, $allowedProfiles, true)) {
        $profile = 'MILD';
    }

    $intervalMin = (int)($raw['interval_min'] ?? 60);
    if (!in_array($intervalMin, [30, 60, 120], true)) {
        $intervalMin = 60;
    }

    $water = (bool)($raw['water'] ?? false);
    $fan   = (bool)($raw['fan'] ?? false);
    $mist  = (bool)($raw['mist'] ?? false);

    $lockRaw   = is_array($raw['lock'] ?? null) ? $raw['lock'] : [];
    $emptyLock = isset($lockRaw['empty_tank']) ? (bool)$lockRaw['empty_tank'] : !$water;
    $highLock  = (bool)($lockRaw['high_voc'] ?? false);

    $state = strtoupper((string)($raw['state'] ?? ($power ? 'IDLE' : 'OFF')));
    if (!in_array($state, $allowedStates, true)) {
        $state = $power ? 'IDLE' : 'OFF';
    }
    if (!$power) {
        $state = 'OFF';
    } elseif ($emptyLock || $highLock) {
        $state = 'LOCKED_OUT';
    }

    $phase = strtoupper((string)($raw['phase'] ?? 'NONE'));
    if (!in_array($phase, $allowedPhases, true)) {
        $phase = 'NONE';
    }

    $vocReady = (bool)($raw['voc_ready'] ?? false);
    $vocIndex = ($vocReady && isset($raw['voc_index']) && is_numeric($raw['voc_index']))
        ? (int)$raw['voc_index']
        : null;

    $vocSource = strtoupper((string)($raw['voc_source'] ?? 'NONE'));
    if (!in_array($vocSource, $allowedSources, true)) {
        $vocSource = 'NONE';
    }

    $rawThresh = is_array($raw['thresholds'] ?? null) ? $raw['thresholds'] : [];
    $thresholds = [
        'low'  => (int)($rawThresh['low']  ?? VOC_THRESHOLD_LOW),
        'high' => (int)($rawThresh['high'] ?? VOC_THRESHOLD_HIGH),
    ];

    $profiles = DEFAULT_PROFILES;
    if (is_array($raw['profiles'] ?? null)) {
        foreach (['MILD', 'STRONG'] as $pKey) {
            if (isset($raw['profiles'][$pKey]) && is_array($raw['profiles'][$pKey])) {
                $profiles[$pKey] = [
                    'mist_s'     => (int)($raw['profiles'][$pKey]['mist_s']     ?? DEFAULT_PROFILES[$pKey]['mist_s']),
                    'cooldown_s' => (int)($raw['profiles'][$pKey]['cooldown_s'] ?? DEFAULT_PROFILES[$pKey]['cooldown_s']),
                ];
            }
        }
    }

    $normalized = [
        'power'                => $power,
        'mode'                 => $mode,
        'profile'              => $profile,
        'interval_min'         => $intervalMin,
        'state'                => $state,
        'phase'                => $phase,
        'water'                => $water,
        'tank_status'          => tankStatus($water),
        'fan'                  => $fan,
        'mist'                 => $mist,
        'voc_index'            => $vocIndex,
        'voc_status'           => vocStatus($vocIndex, $thresholds),
        'voc_ready'            => $vocReady,
        'voc_source'           => $vocSource,
        'baseline'             => isset($raw['baseline']) && is_numeric($raw['baseline']) ? (int)$raw['baseline'] : 100,
        'thresholds'           => $thresholds,
        'lock'                 => [
            'empty_tank' => $emptyLock,
            'high_voc'   => $highLock,
        ],
        'cooldown_remaining_s' => max(0, (int)($raw['cooldown_remaining_s'] ?? 0)),
        'next_timed_s'         => max(0, (int)($raw['next_timed_s'] ?? 0)),
        'profiles'             => $profiles,
    ];

    if ($includeAdminSensors) {
        $rawSensors = is_array($raw['sensors'] ?? null) ? $raw['sensors'] : [];
        $sgpStatus = strtoupper((string)($rawSensors['sgp41'] ?? ($vocSource === 'SIMULATED' ? 'SIMULATED' : ($vocSource === 'SGP41' ? 'OK' : 'FAIL'))));
        if (!in_array($sgpStatus, ['OK', 'SIMULATED', 'FAIL'], true)) {
            $sgpStatus = 'FAIL';
        }
        $shtStatus = strtoupper((string)($rawSensors['sht40'] ?? 'MISSING'));
        if (!in_array($shtStatus, ['OK', 'MISSING'], true)) {
            $shtStatus = 'MISSING';
        }

        $normalized['sensors'] = [
            'sgp41' => $sgpStatus,
            'sht40' => $shtStatus,
        ];
        $normalized['device_meta'] = [
            'fw'       => substr((string)($raw['fw'] ?? ''), 0, 20),
            'uptime_s' => max(0, (int)($raw['uptime_s'] ?? 0)),
            'rssi'     => isset($raw['rssi']) && is_numeric($raw['rssi']) ? (int)$raw['rssi'] : null,
        ];
    }

    return $normalized;
}

function deviceRequest(string $host, string $method, string $path, ?array $body = null, array $extraHeaders = []): array {
    $host = trim($host);
    if (!preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host)) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'INVALID_HOST', 'data' => null];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'CURL_MISSING', 'data' => null];
    }

    $url = 'http://' . $host . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'CURL_INIT_FAILED', 'data' => null];
    }

    $headers = array_merge(['Accept: application/json'], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_FOLLOWLOCATION    => false,
        CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT_MS => DEVICE_CONNECT_TIMEOUT_MS,
        CURLOPT_TIMEOUT_MS        => DEVICE_TIMEOUT_MS,
        CURLOPT_NOSIGNAL          => 1,
    ]);

    if (strtoupper($method) === 'POST') {
        $jsonPayload = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $responseBody = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0 || $responseBody === false) {
        return [
            'ok'        => false,
            'http_code' => 0,
            'error'     => $curlError ?: 'DEVICE_UNREACHABLE',
            'data'      => null,
        ];
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return [
            'ok'        => false,
            'http_code' => $httpCode,
            'error'     => 'INVALID_JSON',
            'data'      => null,
            'raw'       => substr($responseBody, 0, 200),
        ];
    }

    return [
        'ok'        => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'error'     => null,
        'data'      => $decoded,
    ];
}

function logEvent($conn, string $deviceId, string $event, ?string $actorRole, array $normSnap, ?string $detail = null): void {
    if (!$conn) {
        return;
    }

    $allowedEvents = ['SAMPLE', 'COMMAND', 'MIST_START', 'MIST_END', 'LOCK_ON', 'LOCK_OFF', 'OFFLINE', 'ONLINE', 'BASELINE'];
    if (!in_array($event, $allowedEvents, true)) {
        return;
    }

    $actor = $actorRole ? substr(strtolower($actorRole), 0, 10) : 'system';
    if (!in_array($actor, ['admin', 'client', 'system'], true)) {
        $actor = 'system';
    }

    $vocIdx   = isset($normSnap['voc_index']) && is_numeric($normSnap['voc_index']) ? (int)$normSnap['voc_index'] : null;
    $vocStat  = isset($normSnap['voc_status']) ? substr((string)$normSnap['voc_status'], 0, 10) : null;
    $waterOk  = isset($normSnap['water']) ? ((bool)$normSnap['water'] ? 1 : 0) : null;
    $mode     = isset($normSnap['mode']) ? substr((string)$normSnap['mode'], 0, 10) : null;
    $profile  = isset($normSnap['profile']) ? substr((string)$normSnap['profile'], 0, 10) : null;
    $state    = isset($normSnap['state']) ? substr((string)$normSnap['state'], 0, 12) : null;
    $cleanDet = $detail !== null ? substr(trim($detail), 0, 120) : null;

    $sql = 'INSERT INTO activity_log
            (device_id, event, actor_role, voc_index, voc_status, water_ok, mode, profile, state, detail)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $params = [$deviceId, $event, $actor, $vocIdx, $vocStat, $waterOk, $mode, $profile, $state, $cleanDet];
    $stmt = @sqlsrv_query($conn, $sql, $params);
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
}

function processTransitions($conn, string $deviceId, ?array $prevCache, array &$currCache): void {
    $currOnline = (bool)($currCache['online'] ?? false);
    $prevOnline = $prevCache !== null ? ($prevCache['online'] ?? null) : null;

    $currNorm = normalizeTelemetry($currCache['raw'] ?? [], false);
    $prevNorm = normalizeTelemetry($prevCache['raw'] ?? [], false);

    if ($prevOnline === true && !$currOnline) {
        $offlineSnap = $currNorm;
        $offlineSnap['state'] = 'OFFLINE';
        logEvent($conn, $deviceId, 'OFFLINE', 'system', $offlineSnap, 'Device unreachable or timed out');
    } elseif ($prevOnline === false && $currOnline) {
        logEvent($conn, $deviceId, 'ONLINE', 'system', $currNorm, 'Device connection restored');
    }

    if (!$currOnline) {
        return;
    }

    if ($prevCache !== null && $prevOnline === true) {
        $prevMist = (bool)($prevNorm['mist'] ?? false);
        $currMist = (bool)($currNorm['mist'] ?? false);

        if (!$prevMist && $currMist) {
            logEvent($conn, $deviceId, 'MIST_START', 'system', $currNorm, 'Mist cycle started (' . $currNorm['profile'] . ')');
        } elseif ($prevMist && !$currMist) {
            logEvent($conn, $deviceId, 'MIST_END', 'system', $currNorm, 'Mist ended; cooldown active');
        }

        $prevEmpty = (bool)($prevNorm['lock']['empty_tank'] ?? false);
        $currEmpty = (bool)($currNorm['lock']['empty_tank'] ?? false);
        if (!$prevEmpty && $currEmpty) {
            logEvent($conn, $deviceId, 'LOCK_ON', 'system', $currNorm, 'Empty tank lock activated');
        } elseif ($prevEmpty && !$currEmpty) {
            logEvent($conn, $deviceId, 'LOCK_OFF', 'system', $currNorm, 'Tank refilled; empty lock cleared');
        }

        $prevHigh = (bool)($prevNorm['lock']['high_voc'] ?? false);
        $currHigh = (bool)($currNorm['lock']['high_voc'] ?? false);
        if (!$prevHigh && $currHigh) {
            logEvent($conn, $deviceId, 'LOCK_ON', 'system', $currNorm, 'High VOC lock activated');
        } elseif ($prevHigh && !$currHigh) {
            logEvent($conn, $deviceId, 'LOCK_OFF', 'system', $currNorm, 'High VOC lock cleared');
        }
    }

    $nowSec = time();
    $lastSample = (int)($currCache['last_sample_ts'] ?? 0);
    if (($nowSec - $lastSample) >= SAMPLE_LOG_INTERVAL_S) {
        $currCache['last_sample_ts'] = $nowSec;
        logEvent($conn, $deviceId, 'SAMPLE', 'system', $currNorm, 'Periodic VOC telemetry sample');
    }
}

function ensureCacheDir(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
}

function getTelemetryCached($conn, array $device, bool $forceRefresh = false): array {
    ensureCacheDir();
    $deviceId  = (string)$device['device_id'];
    $cacheFile = CACHE_DIR . DIRECTORY_SEPARATOR . $deviceId . '.json';
    $lockFile  = CACHE_DIR . DIRECTORY_SEPARATOR . $deviceId . '.lock';

    $lockFp = @fopen($lockFile, 'c+');
    if ($lockFp !== false) {
        @flock($lockFp, LOCK_EX);
    }

    $nowMs = (int)round(microtime(true) * 1000);
    $prevCache = null;
    if (is_file($cacheFile)) {
        $rawJson = @file_get_contents($cacheFile);
        $parsed  = $rawJson ? json_decode($rawJson, true) : null;
        if (is_array($parsed)) {
            $prevCache = $parsed;
        }
    }

    if ((int)($device['is_enabled'] ?? 1) !== 1) {
        $lastOkMs = (int)($prevCache['last_ok_ms'] ?? 0);
        if ($lockFp !== false) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
        return [
            'online'        => false,
            'stale_ms'      => $lastOkMs > 0 ? max(0, $nowMs - $lastOkMs) : OFFLINE_AFTER_MS + 1,
            'last_seen_iso' => $prevCache['last_seen_iso'] ?? null,
            'raw'           => $prevCache['raw'] ?? [],
        ];
    }

    if (
        !$forceRefresh
        && $prevCache !== null
        && isset($prevCache['fetched_at_ms'])
        && ($nowMs - (int)$prevCache['fetched_at_ms']) < TELEMETRY_CACHE_MS
    ) {
        $lastOkMs = (int)($prevCache['last_ok_ms'] ?? 0);
        $staleMs  = $lastOkMs > 0 ? max(0, $nowMs - $lastOkMs) : OFFLINE_AFTER_MS + 1;
        $online   = (bool)($prevCache['online'] ?? false) && ($staleMs <= OFFLINE_AFTER_MS);
        if ($lockFp !== false) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
        return [
            'online'        => $online,
            'stale_ms'      => $staleMs,
            'last_seen_iso' => $prevCache['last_seen_iso'] ?? null,
            'raw'           => $prevCache['raw'] ?? [],
        ];
    }

    $resp  = deviceRequest((string)$device['host'], 'GET', '/telemetry');
    $nowMs = (int)round(microtime(true) * 1000);

    if ($resp['ok'] && is_array($resp['data']) && isset($resp['data']['water'])) {
        $nowIso = date('Y-m-d H:i:s');
        $currCache = [
            'fetched_at_ms'  => $nowMs,
            'last_ok_ms'     => $nowMs,
            'last_seen_iso'  => $nowIso,
            'last_sample_ts' => (int)($prevCache['last_sample_ts'] ?? 0),
            'online'         => true,
            'raw'            => $resp['data'],
            'last_db_ping'   => (int)($prevCache['last_db_ping'] ?? 0),
        ];

        $nowSec = time();
        if ($conn && ($nowSec - $currCache['last_db_ping']) >= 5) {
            $fw = isset($resp['data']['fw']) ? substr((string)$resp['data']['fw'], 0, 20) : null;
            $upd = @sqlsrv_query(
                $conn,
                'UPDATE devices SET last_seen = SYSDATETIME(), firmware = COALESCE(?, firmware) WHERE device_id = ?',
                [$fw, $deviceId]
            );
            if ($upd !== false) {
                sqlsrv_free_stmt($upd);
                $currCache['last_db_ping'] = $nowSec;
            }
        }

        processTransitions($conn, $deviceId, $prevCache, $currCache);
        @file_put_contents($cacheFile, json_encode($currCache, JSON_UNESCAPED_SLASHES));

        if ($lockFp !== false) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
        return [
            'online'        => true,
            'stale_ms'      => 0,
            'last_seen_iso' => $currCache['last_seen_iso'],
            'raw'           => $currCache['raw'],
        ];
    }

    $lastOkMs = (int)($prevCache['last_ok_ms'] ?? 0);
    $staleMs  = $lastOkMs > 0 ? max(0, $nowMs - $lastOkMs) : OFFLINE_AFTER_MS + 1;
    $currCache = [
        'fetched_at_ms'  => $nowMs,
        'last_ok_ms'     => $lastOkMs,
        'last_seen_iso'  => $prevCache['last_seen_iso'] ?? null,
        'last_sample_ts' => (int)($prevCache['last_sample_ts'] ?? 0),
        'online'         => false,
        'raw'            => $prevCache['raw'] ?? [],
        'last_db_ping'   => (int)($prevCache['last_db_ping'] ?? 0),
    ];

    processTransitions($conn, $deviceId, $prevCache, $currCache);
    @file_put_contents($cacheFile, json_encode($currCache, JSON_UNESCAPED_SLASHES));

    if ($lockFp !== false) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }

    return [
        'online'        => false,
        'stale_ms'      => $staleMs,
        'last_seen_iso' => $currCache['last_seen_iso'],
        'raw'           => $currCache['raw'],
    ];
}

function primeTelemetryCache($conn, string $deviceId, array $rawState): void {
    ensureCacheDir();
    $cacheFile = CACHE_DIR . DIRECTORY_SEPARATOR . $deviceId . '.json';
    $lockFile  = CACHE_DIR . DIRECTORY_SEPARATOR . $deviceId . '.lock';

    $lockFp = @fopen($lockFile, 'c+');
    if ($lockFp !== false) {
        @flock($lockFp, LOCK_EX);
    }

    $prevCache = null;
    if (is_file($cacheFile)) {
        $rawJson = @file_get_contents($cacheFile);
        $parsed  = $rawJson ? json_decode($rawJson, true) : null;
        if (is_array($parsed)) {
            $prevCache = $parsed;
        }
    }

    $nowMs = (int)round(microtime(true) * 1000);
    $currCache = [
        'fetched_at_ms'  => $nowMs,
        'last_ok_ms'     => $nowMs,
        'last_seen_iso'  => date('Y-m-d H:i:s'),
        'last_sample_ts' => (int)($prevCache['last_sample_ts'] ?? 0),
        'online'         => true,
        'raw'            => $rawState,
        'last_db_ping'   => (int)($prevCache['last_db_ping'] ?? 0),
    ];

    processTransitions($conn, $deviceId, $prevCache, $currCache);
    @file_put_contents($cacheFile, json_encode($currCache, JSON_UNESCAPED_SLASHES));

    if ($lockFp !== false) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}

function checkAndRecordSprayGap(string $deviceId): ?int {
    ensureCacheDir();
    $gapFile = CACHE_DIR . DIRECTORY_SEPARATOR . $deviceId . '_spray_gap.lock';
    $fp = @fopen($gapFile, 'c+');
    if ($fp === false) {
        return null;
    }
    @flock($fp, LOCK_EX);
    $contents = stream_get_contents($fp);
    $lastTs = (int)trim((string)$contents);
    $now = time();
    $elapsed = $now - $lastTs;
    if ($lastTs > 0 && $elapsed < SPRAY_MIN_GAP_S) {
        $remaining = SPRAY_MIN_GAP_S - $elapsed;
        @flock($fp, LOCK_UN);
        @fclose($fp);
        return $remaining;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$now);
    fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    return null;
}