<?php
/**
 * admin_devices.php
 * Admin-only Hardware Registry & Diagnostics Console.
 * Device IPs/hosts and SHT40/SGP41 presence diagnostics are visible ONLY here (never to Clients or dashboard).
 */
require_once 'db_connection.php';
require_once 'auth_helpers.php';
require_once 'device_gateway.php';

requireAdmin();

$userName = $_SESSION['smartscent_fullname'] ?? 'System Administrator';
$csrf     = csrfToken();
$errorMsg = '';
$infoMsg  = '';
$probeResults = [];

function formatUptime(?int $sec): string {
    if ($sec === null) return '--';
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($d > 0) return "{$d}d {$h}h {$m}m";
    if ($h > 0) return "{$h}h {$m}m {$s}s";
    if ($m > 0) return "{$m}m {$s}s";
    return "{$s}s";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck($_POST['csrf_token'] ?? null)) {
        $errorMsg = 'Security token validation failed. Please refresh and try again.';
    } elseif ($conn === false) {
        $errorMsg = 'Database connection unavailable.';
    } else {
        $action   = strtolower(trim((string)($_POST['action'] ?? '')));
        $deviceId = sanitizeDeviceId($_POST['device_id'] ?? null);

        if ($deviceId === null) {
            $errorMsg = 'Invalid device identifier.';
        } elseif ($action === 'save') {
            $host = trim((string)($_POST['host'] ?? ''));
            $enabled = isset($_POST['is_enabled']) ? 1 : 0;

            if (!preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host)) {
                $errorMsg = "Invalid host address for {$deviceId}. Use IPv4 or IPv4:port (e.g., 192.168.1.15 or 127.0.0.1:8081).";
            } else {
                $stmt = @sqlsrv_query(
                    $conn,
                    'UPDATE devices SET host = ?, is_enabled = ? WHERE device_id = ?',
                    [$host, $enabled, $deviceId]
                );
                if ($stmt !== false && sqlsrv_rows_affected($stmt) > 0) {
                    sqlsrv_free_stmt($stmt);
                    // Remove stale cache file when host changes
                    $cacheFile = CACHE_DIR . DIRECTORY_SEPARATOR . $deviceId . '.json';
                    if (is_file($cacheFile)) {
                        @unlink($cacheFile);
                    }
                    $infoMsg = "Saved configuration for {$deviceId} ({$host}).";
                } else {
                    if ($stmt !== false) sqlsrv_free_stmt($stmt);
                    $errorMsg = "Could not update {$deviceId}.";
                }
            }
        } elseif ($action === 'test') {
            $dev = getDevice($conn, $deviceId);
            if (!$dev) {
                $errorMsg = "Device {$deviceId} not found.";
            } else {
                $t0 = microtime(true);
                $resp = deviceRequest((string)$dev['host'], 'GET', '/telemetry');
                $ms = (int)round((microtime(true) - $t0) * 1000);
                if ($resp['ok'] && is_array($resp['data'])) {
                    $norm = normalizeTelemetry($resp['data'], true);
                    $probeResults[$deviceId] = [
                        'online' => true,
                        'ms'     => $ms,
                        'data'   => $norm,
                    ];
                    $infoMsg = "Connection OK for {$deviceId} ({$dev['host']}) in {$ms} ms.";
                } else {
                    $probeResults[$deviceId] = [
                        'online' => false,
                        'ms'     => $ms,
                        'error'  => (string)($resp['error'] ?? ('HTTP ' . $resp['http_code'])),
                    ];
                    $errorMsg = "Connection FAILED for {$deviceId} ({$dev['host']}): " . ($resp['error'] ?: ('HTTP ' . $resp['http_code']));
                }
            }
        }
    }
}

$devices = [];
if ($conn !== false) {
    $devices = getAllDevices($conn, false);
    foreach ($devices as $dev) {
        $did = (string)$dev['device_id'];
        if (!isset($probeResults[$did]) && (int)$dev['is_enabled'] === 1) {
            $cached = getTelemetryCached($conn, $dev, false);
            $probeResults[$did] = [
                'online' => $cached['online'],
                'ms'     => null,
                'data'   => normalizeTelemetry($cached['raw'], true),
            ];
        }
    }
} else {
    $errorMsg = 'Database connection unavailable.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SmartScent — Device Registry &amp; Diagnostics</title>
  <link rel="stylesheet" href="assets/inter.css" />
  <link rel="stylesheet" href="assets/fontawesome.css" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="logo-block">
        <div class="logo-mark"><i class="fa-solid fa-leaf"></i></div>
        <div>
          <h1>SmartScent</h1>
          <p>VOC-response guided fragrance dispenser</p>
        </div>
      </div>

      <nav class="side-nav" aria-label="Admin Navigation">
        <a href="index.php"><i class="fa-solid fa-gauge-high nav-icon"></i>Dashboard</a>
        <a href="admin_rooms.php"><i class="fa-solid fa-key nav-icon"></i>Room Access</a>
        <a class="active" href="admin_devices.php"><i class="fa-solid fa-microchip nav-icon"></i>Devices</a>
      </nav>

      <div class="user-card" style="margin-top:auto;">
        <div class="user-info">
          <div class="user-avatar"><i class="fa-solid fa-user-shield"></i></div>
          <div class="user-details">
            <span class="user-name"><?= htmlspecialchars($userName) ?></span>
            <span class="user-role-badge role-admin"><i class="fa-solid fa-shield-halved"></i> Administrator</span>
          </div>
        </div>
        <a href="logout.php" class="logout-link"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
      </div>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div>
          <p class="eyebrow">Hardware Gateway Configuration</p>
          <h2>Prototype Devices &amp; Sensor Diagnostics</h2>
          <p class="top-desc">Configure ESP32-S3 IP addresses and inspect firmware, signal strength, and sensor readiness.</p>
        </div>
        <div class="top-actions">
          <a href="index.php" class="ghost-btn" style="text-decoration:none;"><i class="fa-solid fa-gauge-high btn-icon"></i>Back to Dashboard</a>
        </div>
      </header>

      <?php if ($errorMsg !== ''): ?>
        <div class="banner-alert" role="alert">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span><?= htmlspecialchars($errorMsg) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($infoMsg !== ''): ?>
        <div class="client-notice" role="status">
          <i class="fa-solid fa-circle-check"></i>
          <span><?= htmlspecialchars($infoMsg) ?></span>
        </div>
      <?php endif; ?>

      <section class="admin-grid">
        <?php foreach ($devices as $dev): ?>
          <?php
            $did      = (string)$dev['device_id'];
            $roomNo   = $dev['room_number'] ? (string)$dev['room_number'] : 'Unassigned';
            $host     = (string)$dev['host'];
            $enabled  = ((int)$dev['is_enabled'] === 1);
            $lastSeen = ($dev['last_seen'] instanceof DateTimeInterface) ? $dev['last_seen']->format('Y-m-d H:i:s') : 'Never';
            $probe    = $probeResults[$did] ?? null;
            $online   = (bool)($probe['online'] ?? false);
            $meta     = $probe['data']['device_meta'] ?? [];
            $sensors  = $probe['data']['sensors'] ?? [];
            $fw       = !empty($meta['fw']) ? (string)$meta['fw'] : (string)($dev['firmware'] ?? '--');
            $uptime   = isset($meta['uptime_s']) && $online ? formatUptime((int)$meta['uptime_s']) : '--';
            $rssi     = isset($meta['rssi']) && $online && $meta['rssi'] !== null ? ($meta['rssi'] . ' dBm') : '--';
            $sgpDiag  = $online ? (string)($sensors['sgp41'] ?? '--') : '--';
            $shtDiag  = $online ? (string)($sensors['sht40'] ?? '--') : '--';
          ?>
          <article class="panel">
            <div class="panel-title-row">
              <div>
                <h3><i class="fa-solid fa-microchip"></i> <?= htmlspecialchars($did) ?></h3>
                <p>Assigned Room: <strong>Room <?= htmlspecialchars($roomNo) ?></strong></p>
              </div>
              <span class="status-pill <?= !$enabled ? 'neutral' : ($online ? 'good' : 'alert') ?>">
                <?= !$enabled ? 'Disabled' : ($online ? 'Online' : 'Offline') ?>
              </span>
            </div>

            <form method="POST" action="admin_devices.php">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
              <input type="hidden" name="action" value="save" />
              <input type="hidden" name="device_id" value="<?= htmlspecialchars($did) ?>" />

              <label class="admin-field">
                <span>Device Host (IPv4 or IPv4:port)</span>
                <input type="text" name="host" value="<?= htmlspecialchars($host) ?>" required placeholder="e.g. 192.168.1.15" />
              </label>

              <label class="switch-label" style="margin-bottom:14px;">
                <input type="checkbox" name="is_enabled" value="1" <?= $enabled ? 'checked' : '' ?> />
                <i></i>
                <span>Gateway Polling Enabled</span>
              </label>

              <div class="control-actions-row" style="margin-bottom:16px;">
                <button type="submit" class="primary-btn" style="flex:1;">
                  <i class="fa-solid fa-circle-check btn-icon"></i>Save Device Settings
                </button>
              </div>
            </form>

            <form method="POST" action="admin_devices.php" style="margin-bottom:16px;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
              <input type="hidden" name="action" value="test" />
              <input type="hidden" name="device_id" value="<?= htmlspecialchars($did) ?>" />
              <button type="submit" class="ghost-btn" style="width:100%;">
                <i class="fa-solid fa-arrows-rotate btn-icon"></i>Test Connection
              </button>
            </form>

            <!-- Read-only Hardware & Sensor Diagnostics (Admin Only) -->
            <div class="mini-stats">
              <div><span>Firmware</span><strong><?= htmlspecialchars($fw) ?></strong></div>
              <div><span>Uptime</span><strong><?= htmlspecialchars($uptime) ?></strong></div>
              <div><span>Wi-Fi Signal (RSSI)</span><strong><?= htmlspecialchars($rssi) ?></strong></div>
              <div><span>Last Seen</span><strong><?= htmlspecialchars($lastSeen) ?></strong></div>
              <div><span>SGP41 VOC Sensor</span><strong><?= htmlspecialchars($sgpDiag) ?></strong></div>
              <div><span>SHT40 Compensation</span><strong><?= htmlspecialchars($shtDiag) ?></strong></div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    </main>
  </div>
</body>
</html>