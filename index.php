<?php
/**
 * index.php
 * SmartScent Dashboard — Main Application Page
 * Protected by SQL Server authentication session, idle timeout, and active-room enforcement.
 */
require_once 'db_connection.php';
require_once 'auth_helpers.php';
require_once 'device_gateway.php';

requireRoomAccess();

$userRole = $_SESSION['smartscent_role'] ?? 'client';
$userName = $_SESSION['smartscent_fullname'] ?? 'SmartScent User';
$userRoom = $_SESSION['smartscent_room'] ?? null;
$isAdmin  = isAdmin($userRole);

$sessionDevice = getDeviceForRoom($userRoom) ?? 'SFD-01';
$devicesList = ($isAdmin && $conn !== false) ? getAllDevices($conn, false) : [];
if ($isAdmin && empty($devicesList)) {
    $devicesList = [
        ['device_id' => 'SFD-01', 'room_number' => '101', 'is_enabled' => 1],
        ['device_id' => 'SFD-02', 'room_number' => '102', 'is_enabled' => 1],
    ];
}
$initialDevice = $isAdmin ? (string)($devicesList[0]['device_id'] ?? 'SFD-01') : $sessionDevice;

$appBootstrap = [
    'role'   => $userRole,
    'room'   => $userRoom,
    'device' => $initialDevice,
    'csrf'   => csrfToken(),
    'pollMs' => 1000,
];

$pageError = '';
if ($conn === false) {
    $pageError = 'Database connection is currently unavailable. Live logging and device configuration may be limited.';
} elseif (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $pageError = 'Administrator privileges are required to access that page.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SmartScent Dashboard — <?= $isAdmin ? 'Admin Console' : ('Room ' . htmlspecialchars((string)$userRoom) . ' Portal') ?></title>
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

      <nav class="side-nav" aria-label="Dashboard Navigation">
        <a class="active" href="#overview"><i class="fa-solid fa-gauge-high nav-icon"></i>Overview</a>
        <a href="#controls"><i class="fa-solid fa-sliders nav-icon"></i>Controls</a>
        <a href="#safety"><i class="fa-solid fa-shield-virus nav-icon"></i>Safety</a>
        <a href="#logs"><i class="fa-solid fa-clock-rotate-left nav-icon"></i>Logs</a>
        <?php if ($isAdmin): ?>
          <a href="admin_rooms.php"><i class="fa-solid fa-key nav-icon"></i>Room Access</a>
          <a href="admin_devices.php"><i class="fa-solid fa-microchip nav-icon"></i>Devices</a>
        <?php endif; ?>
      </nav>

      <!-- Prototype Device Identification / Admin Switcher (No IPs displayed here) -->
      <div class="device-card compact-card">
        <span class="tiny-label"><?= $isAdmin ? 'Select Prototype' : 'Assigned Device' ?></span>
        <?php if ($isAdmin): ?>
          <select id="adminDeviceSelect" class="device-selector" aria-label="Select prototype device">
            <?php foreach ($devicesList as $dev): ?>
              <?php
                $did = (string)$dev['device_id'];
                $drm = $dev['room_number'] ? 'Room ' . $dev['room_number'] : 'Unassigned';
              ?>
              <option value="<?= htmlspecialchars($did) ?>" <?= $did === $initialDevice ? 'selected' : '' ?>>
                <?= htmlspecialchars($did . ' (' . $drm . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <strong><?= htmlspecialchars($sessionDevice) ?></strong>
        <?php endif; ?>
        <span class="device-state is-connecting" id="sidebarDeviceState"><i></i> <span>Connecting...</span></span>
      </div>

      <!-- Current User Profile & Room Card -->
      <div class="user-card">
        <div class="user-info">
          <div class="user-avatar">
            <i class="fa-solid <?= $isAdmin ? 'fa-user-shield' : 'fa-hotel' ?>"></i>
          </div>
          <div class="user-details">
            <span class="user-name" title="<?= htmlspecialchars($userName) ?>"><?= htmlspecialchars($userName) ?></span>
            <span class="user-role-badge <?= $isAdmin ? 'role-admin' : 'role-client' ?>">
              <i class="fa-solid <?= $isAdmin ? 'fa-shield-halved' : 'fa-door-closed' ?>"></i>
              <?= $isAdmin ? 'Administrator' : ('Room ' . htmlspecialchars((string)$userRoom)) ?>
            </span>
          </div>
        </div>
        <a href="logout.php" class="logout-link">
          <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
        </a>
      </div>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div>
          <p class="eyebrow">Computer Engineering Thesis Prototype</p>
          <h2>Smart Fragrance Dispenser Dashboard</h2>
          <p class="top-desc">Monitor VOC response, safety interlocks, tank status, and misting control.</p>
        </div>
        <div class="top-actions">
          <?php if ($isAdmin): ?>
            <button type="button" class="ghost-btn" id="baselineBtn"><i class="fa-solid fa-crosshairs btn-icon"></i>Set baseline</button>
            <button type="button" class="primary-btn" id="exportBtn"><i class="fa-solid fa-file-arrow-down btn-icon"></i>Export CSV</button>
          <?php endif; ?>
        </div>
      </header>

      <?php if ($pageError !== ''): ?>
        <div class="banner-alert" role="alert">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span><?= htmlspecialchars($pageError) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!$isAdmin): ?>
        <!-- Compact Mobile Quick Controls Card (shown <= 620px for Guests) -->
        <section class="panel mobile-quick-card" id="mobileQuickControls" aria-label="Quick Guest Controls">
          <div class="panel-title-row compact-row">
            <div>
              <h3>Room <?= htmlspecialchars((string)$userRoom) ?> Quick Controls</h3>
              <p>Fast misting access for your room</p>
            </div>
            <label class="switch-label">
              <span>Power</span>
              <input type="checkbox" id="quickPowerToggle" />
              <i></i>
            </label>
          </div>
          <div class="quick-profile-row" data-quick-profile="true">
            <button type="button" class="profile-chip-btn" data-profile="MILD">Mild</button>
            <button type="button" class="profile-chip-btn" data-profile="STRONG">Strong</button>
          </div>
          <button type="button" class="spray-button" id="quickSprayBtn">Spray Now</button>
          <p class="helper-text" id="quickSprayHint">Connecting to device...</p>
        </section>
      <?php endif; ?>

      <!-- Prominent Hero Live Status Banner -->
      <section id="overview" class="hero-status state-offline" aria-live="polite">
        <div class="hero-main">
          <div class="hero-icon-wrap" id="heroIconWrap">
            <i class="fa-solid fa-plug" id="heroIcon"></i>
          </div>
          <div>
            <span class="hero-eyebrow" id="heroDeviceLabel"><?= htmlspecialchars($initialDevice) ?> LIVE STATE</span>
            <div class="hero-title-row">
              <strong class="hero-state-label" id="heroStateLabel">CONNECTING</strong>
              <span class="hero-phase-pill" id="heroPhasePill">Standby</span>
            </div>
            <p class="hero-detail" id="heroStateDetail">Waiting for telemetry from gateway...</p>
          </div>
        </div>
      </section>

      <section class="summary-bar" aria-label="Device summary indicators">
        <div class="summary-chip neutral" id="chipConnection"><span></span> Checking...</div>
        <div class="summary-chip neutral" id="chipTank"><span></span> Tank: --</div>
        <div class="summary-chip neutral" id="chipSafety"><span></span> Misting: --</div>
        <?php if ($isAdmin): ?>
          <div class="summary-chip admin-chip">
            <i class="fa-solid fa-shield-halved"></i> Admin Privileges Active
          </div>
        <?php else: ?>
          <div class="summary-chip client-chip">
            <i class="fa-solid fa-hotel"></i> Room <?= htmlspecialchars((string)$userRoom) ?> (<?= htmlspecialchars($sessionDevice) ?>)
          </div>
        <?php endif; ?>
        <div class="last-updated">Last updated: <strong id="lastUpdated">--:--:--</strong></div>
      </section>

      <!-- 4-Card Metric Grid -->
      <section class="metric-grid">
        <article class="metric-card accent-card">
          <div class="metric-head">
            <span>VOC Response</span>
            <small id="vocSourceBadge">Connecting</small>
          </div>
          <div class="metric-value" id="vocValue">--</div>
          <p class="metric-sub" id="vocBaselineText">Baseline --</p>
        </article>

        <article class="metric-card">
          <div class="metric-head">
            <span>VOC Status</span>
            <small id="vocBadge">--</small>
          </div>
          <div class="metric-value text-value" id="vocStatus">--</div>
          <p class="metric-sub" id="vocThresholdsText">Low &lt; 100 · Normal 100–179 · High &ge; 180</p>
        </article>

        <article class="metric-card">
          <div class="metric-head">
            <span>Tank</span>
            <small>XKC-Y23A</small>
          </div>
          <div class="metric-value text-value" id="tankMetricValue">--</div>
          <p class="metric-sub" id="tankMetricSub">Awaiting water sensor</p>
        </article>

        <article class="metric-card">
          <div class="metric-head">
            <span>Device Status</span>
            <small id="deviceModeBadge">--</small>
          </div>
          <div class="metric-value text-value" id="deviceStateMetric">--</div>
          <p class="metric-sub" id="deviceStateSub">Fan: -- · Mist: --</p>
        </article>
      </section>

      <section class="dashboard-grid">
        <article id="controls" class="panel controls-panel <?= !$isAdmin ? 'client-mode' : '' ?>">
          <div class="panel-title-row">
            <div>
              <h3>Control Panel</h3>
              <p>Select operating mode, dose profile, and misting command.</p>
            </div>
            <label class="switch-label">
              <span>Power</span>
              <input type="checkbox" id="powerToggle" />
              <i></i>
            </label>
          </div>

          <?php if (!$isAdmin): ?>
            <div class="client-notice">
              <i class="fa-solid fa-circle-info"></i>
              <span>Guests control their own room's misting; other settings are managed by the administrator.</span>
            </div>
          <?php endif; ?>

          <!-- Operating mode (Admin only) -->
          <div class="control-section" id="modeControlGroup">
            <span class="field-label">Operating mode <?= !$isAdmin ? '<small>(Admin Only)</small>' : '' ?></span>
            <div class="segmented" data-group="mode">
              <button type="button" data-mode="MANUAL">Manual</button>
              <button type="button" data-mode="TIMED">Time-Based</button>
              <button type="button" data-mode="SENSOR">Sensor-Based</button>
              <button type="button" data-mode="AUTO">Auto</button>
            </div>
          </div>

          <!-- Dose profile (Admin + Client) -->
          <div class="control-section" id="profileControlGroup">
            <span class="field-label">Dose profile</span>
            <div class="profile-grid" data-group="profile">
              <button type="button" class="profile-card" data-profile="MILD">
                <strong>Mild</strong>
                <span id="profileSubMILD">5 s mist, 25 s rest</span>
              </button>
              <button type="button" class="profile-card" data-profile="STRONG">
                <strong>Strong</strong>
                <span id="profileSubSTRONG">15 s mist, 15 s rest</span>
              </button>
            </div>
          </div>

          <!-- Time-based interval (Admin only) -->
          <div class="control-section" id="intervalControlGroup">
            <span class="field-label">Time-based interval <?= !$isAdmin ? '<small>(Admin Only)</small>' : '' ?></span>
            <div class="segmented small" data-group="interval">
              <button type="button" data-minutes="30">30 min</button>
              <button type="button" data-minutes="60">1 hour</button>
              <button type="button" data-minutes="120">2 hours</button>
            </div>
          </div>

          <div class="control-actions-row">
            <button type="button" class="spray-button" id="sprayBtn">Spray Now</button>
            <?php if ($isAdmin): ?>
              <button type="button" class="ghost-btn fan-test-btn" id="fanTestBtn">
                <i class="fa-solid fa-wind btn-icon"></i>Fan Test (5 s)
              </button>
            <?php endif; ?>
          </div>
          <p class="helper-text" id="sprayHint" aria-live="polite">Connecting to device...</p>
        </article>

        <!-- Binary Tank Monitoring Panel -->
        <article class="panel tank-panel">
          <div class="panel-title-row">
            <div>
              <h3>Tank Monitoring</h3>
              <p>Binary liquid detection and dry-burn protection.</p>
            </div>
            <span class="status-pill neutral" id="tankPill">--</span>
          </div>

          <div class="tank-layout">
            <div class="tank-binary-visual is-unknown" id="tankVisualBox">
              <i class="fa-solid fa-droplet tank-droplet-icon"></i>
              <span class="tank-binary-badge" id="tankVisualLabel">--</span>
            </div>
            <div class="tank-details">
              <h4 id="tankStatus">Checking sensor...</h4>
              <p id="tankDesc">Awaiting XKC-Y23A-NPN reservoir state from ESP32-S3.</p>
              <div class="mini-stats">
                <div><span>Sensor Type</span><strong>Binary Contactless</strong></div>
                <div><span>Empty Tank Lock</span><strong id="tankLock">--</strong></div>
              </div>
            </div>
          </div>
        </article>
      </section>

      <section id="safety" class="dashboard-grid second-row">
        <article class="panel logic-panel">
          <h3>Sensor-Based Misting Logic</h3>
          <p class="panel-desc">The device enforces VOC thresholds and tank interlocks locally on every cycle.</p>
          <div class="logic-steps">
            <div class="logic-step allow">
              <span>Low VOC (&lt; 100)</span>
              <strong>Misting allowed</strong>
              <small>Fragrance response below target</small>
            </div>
            <div class="logic-step standby">
              <span>Normal VOC (100–179)</span>
              <strong>Standby</strong>
              <small>Within target range</small>
            </div>
            <div class="logic-step lock">
              <span>High VOC (&ge; 180)</span>
              <strong>High VOC lock</strong>
              <small>Pause misting until &lt; 170</small>
            </div>
          </div>
        </article>

        <article class="panel notifications-panel">
          <div class="panel-title-row">
            <div>
              <h3>Notifications</h3>
              <p>Latest locks, commands, and connectivity events.</p>
            </div>
            <span class="status-pill good" id="notifLivePill">Live</span>
          </div>
          <ul class="notification-list" id="notificationList">
            <li><strong>Loading events</strong><span>Fetching recent alerts from SQL Server...</span></li>
          </ul>
        </article>
      </section>

      <section class="panel chart-panel">
        <div class="panel-title-row">
          <div>
            <h3>VOC Trend</h3>
            <p>Latest 7 periodic SAMPLE readings logged every 30 seconds.</p>
          </div>
          <span class="status-pill neutral" id="trendPill">Last 7 samples</span>
        </div>
        <div class="chart" id="chartBars">
          <div class="chart-empty-msg">Waiting for VOC sample logs...</div>
        </div>
        <div class="chart-labels" id="chartLabels"></div>
      </section>

      <section id="logs" class="panel log-panel">
        <div class="panel-title-row">
          <div>
            <h3>Activity Logs</h3>
            <p>Device telemetry and control history (no personal guest data).</p>
          </div>
          <span class="status-pill neutral"><?= $isAdmin ? 'CSV ready' : 'Live view' ?></span>
        </div>

        <div class="log-toolbar">
          <?php if ($isAdmin): ?>
            <label class="log-filter-item">
              <span>Device</span>
              <select id="logDeviceFilter">
                <option value="ACTIVE" selected>Active prototype</option>
                <option value="ALL">All devices</option>
                <?php foreach ($devicesList as $dev): ?>
                  <option value="<?= htmlspecialchars((string)$dev['device_id']) ?>">
                    <?= htmlspecialchars((string)$dev['device_id']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <label class="log-filter-item">
            <span>Date</span>
            <input type="date" id="logDateFilter" />
          </label>
          <button type="button" class="ghost-btn compact-btn" id="logFilterReset">Reset</button>
          <?php if ($isAdmin): ?>
            <button type="button" class="danger-ghost-btn compact-btn" id="clearLogsBtn">
              <i class="fa-solid fa-trash btn-icon"></i>Clear logs
            </button>
          <?php endif; ?>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>VOC</th>
                <th>Tank</th>
                <th>Mode</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="logBody">
              <tr><td colspan="5">Loading activity logs...</td></tr>
            </tbody>
          </table>
        </div>

        <div class="log-pagination">
          <button type="button" class="ghost-btn compact-btn" id="logPrevBtn" disabled>Previous</button>
          <span id="logPageInfo">Page 1 of 1</span>
          <button type="button" class="ghost-btn compact-btn" id="logNextBtn" disabled>Next</button>
        </div>
      </section>
    </main>
  </div>

  <div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

  <script>
    window.APP = <?= json_encode($appBootstrap, JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="script.js"></script>
</body>
</html>