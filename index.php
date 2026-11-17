<?php
/**
 * index.php
 * SmartScent Dashboard — Main Application Page
 * Protected by SQL Server authentication session (Admin & Hotel Room Client roles).
 */
require_once 'db_connection.php';
require_once 'auth_helpers.php';

// Hard authentication & room assignment gate
requireRoomAccess();

$userEmail = $_SESSION['smartscent_email'] ?? 'user@smartscent.com';
$userRole  = $_SESSION['smartscent_role']  ?? 'client';
$userName  = $_SESSION['smartscent_fullname'] ?? 'SmartScent User';
$userRoom  = $_SESSION['smartscent_room'] ?? null;
$isAdmin   = isAdmin($userRole);

// Clients only ever see the device of their own session room. Ignore any ?room= parameter after login.
$sessionDevice = getDeviceForRoom($userRoom) ?? 'SFD-01';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SmartScent Dashboard — <?= $isAdmin ? 'Admin Console' : ('Room ' . htmlspecialchars($userRoom) . ' Portal') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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

      <nav class="side-nav">
        <a class="active" href="#overview"><i class="fa-solid fa-gauge-high nav-icon"></i>Overview</a>
        <a href="#controls"><i class="fa-solid fa-sliders nav-icon"></i>Controls</a>
        <a href="#safety"><i class="fa-solid fa-shield-virus nav-icon"></i>Safety</a>
        <a href="#logs"><i class="fa-solid fa-clock-rotate-left nav-icon"></i>Logs</a>
      </nav>

      <!-- Prototype Device Identification / Admin Switcher -->
      <div class="device-card compact-card">
        <span class="tiny-label"><?= $isAdmin ? 'Select Prototype' : 'Assigned Device' ?></span>
        <?php if ($isAdmin): ?>
          <select id="adminDeviceSelect" class="device-selector" aria-label="Select prototype device">
            <option value="SFD-01" selected>SFD-01 (Room 101)</option>
            <option value="SFD-02">SFD-02 (Room 102)</option>
          </select>
        <?php else: ?>
          <strong><?= htmlspecialchars($sessionDevice) ?></strong>
        <?php endif; ?>
        <span class="device-state"><i></i> Online</span>
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
              <?= $isAdmin ? 'Administrator' : ('Room ' . htmlspecialchars($userRoom)) ?>
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
          <p class="top-desc">Monitor VOC response, room condition, tank status, and misting control.</p>
        </div>
        <div class="top-actions">
          <?php if ($isAdmin): ?>
            <button class="ghost-btn" id="baselineBtn"><i class="fa-solid fa-crosshairs btn-icon"></i>Set baseline</button>
            <button class="primary-btn" id="exportBtn"><i class="fa-solid fa-file-arrow-down btn-icon"></i>Export CSV</button>
          <?php endif; ?>
        </div>
      </header>

      <section id="overview" class="summary-bar">
        <div class="summary-chip good"><span></span> Connected</div>
        <div class="summary-chip warn"><span></span> Refill recommended</div>
        <div class="summary-chip safe"><span></span> Misting allowed</div>
        <?php if ($isAdmin): ?>
          <div class="summary-chip admin-chip">
            <i class="fa-solid fa-shield-halved"></i> Admin Privileges Active
          </div>
        <?php else: ?>
          <div class="summary-chip client-chip">
            <i class="fa-solid fa-hotel"></i> Room <?= htmlspecialchars($userRoom) ?> (<?= htmlspecialchars($sessionDevice) ?>)
          </div>
        <?php endif; ?>
        <div class="last-updated">Last updated: <strong id="lastUpdated">--:--:--</strong></div>
      </section>

      <!-- 3-Metric Environmental Grid -->
      <section class="metric-grid">
        <article class="metric-card accent-card">
          <div class="metric-head">
            <span>VOC Response</span>
            <small>SGP41</small>
          </div>
          <div class="metric-value" id="vocValue">132</div>
          <p class="metric-sub">VOC index / response reading</p>
        </article>

        <article class="metric-card">
          <div class="metric-head">
            <span>VOC Status</span>
            <small id="vocBadge">Normal</small>
          </div>
          <div class="metric-value text-value" id="vocStatus">Normal</div>
          <p class="metric-sub">Within target response range</p>
        </article>

        <article class="metric-card">
          <div class="metric-head">
            <span>Humidity</span>
            <small>SHT40</small>
          </div>
          <div class="metric-value"><span id="humidityValue">62</span>%</div>
          <p class="metric-sub">Used for environmental monitoring</p>
        </article>
      </section>

      <section class="dashboard-grid">
        <article id="controls" class="panel controls-panel <?= !$isAdmin ? 'client-mode' : '' ?>">
          <div class="panel-title-row">
            <div>
              <h3>Control Panel</h3>
              <p>Select operating mode and misting profile.</p>
            </div>
            <!-- Power toggle: allowed for both Admin and Client -->
            <label class="switch-label">
              <span>Power</span>
              <input type="checkbox" id="powerToggle" checked />
              <i></i>
            </label>
          </div>

          <?php if (!$isAdmin): ?>
            <div class="client-notice">
              <i class="fa-solid fa-circle-info"></i>
              <span>Guests control their own room's misting; other settings are managed by the administrator.</span>
            </div>
          <?php endif; ?>

          <!-- Operating mode: Admin only -->
          <div class="control-section">
            <span class="field-label">Operating mode <?= !$isAdmin ? '<small>(Admin Only)</small>' : '' ?></span>
            <div class="segmented" data-group="mode">
              <button <?= !$isAdmin ? 'disabled' : '' ?>>Manual</button>
              <button <?= !$isAdmin ? 'disabled' : '' ?>>Time-Based</button>
              <button class="active" <?= !$isAdmin ? 'disabled' : '' ?>>Sensor-Based</button>
              <button <?= !$isAdmin ? 'disabled' : '' ?>>Auto</button>
            </div>
          </div>

          <!-- Dose profile: Allowed for both Admin and Client -->
          <div class="control-section">
            <span class="field-label">Dose profile</span>
            <div class="profile-grid" data-group="profile">
              <button class="profile-card active">
                <strong>Mild</strong>
                <span>3 sec mist · longer cooldown</span>
              </button>
              <button class="profile-card">
                <strong>Strong</strong>
                <span>6 sec mist · shorter cooldown</span>
              </button>
            </div>
          </div>

          <!-- Time-based interval: Admin only -->
          <div class="control-section">
            <span class="field-label">Time-based interval <?= !$isAdmin ? '<small>(Admin Only)</small>' : '' ?></span>
            <div class="segmented small" data-group="interval">
              <button <?= !$isAdmin ? 'disabled' : '' ?>>30 min</button>
              <button class="active" <?= !$isAdmin ? 'disabled' : '' ?>>1 hour</button>
              <button <?= !$isAdmin ? 'disabled' : '' ?>>2 hours</button>
            </div>
          </div>

          <!-- Spray Now: Allowed for both Admin and Client, subject to safety locks -->
          <button class="spray-button" id="sprayBtn">Spray Now</button>
          <p class="helper-text" id="sprayHint">
            <?= $isAdmin ? 'Manual command is allowed because tank level and VOC response are safe.' : ('Guest misting is active for Room ' . htmlspecialchars($userRoom) . '.') ?>
          </p>
        </article>

        <article class="panel tank-panel">
          <div class="panel-title-row">
            <div>
              <h3>Tank Monitoring</h3>
              <p>Liquid level status and misting lock.</p>
            </div>
            <span class="status-pill warn" id="tankPill">Near Empty</span>
          </div>

          <div class="tank-layout">
            <div class="tank-visual">
              <div class="tank-fill" id="tankFill"></div>
              <span id="tankPercent">30%</span>
            </div>
            <div class="tank-details">
              <h4 id="tankStatus">Refill Required</h4>
              <p>Tank level is below the refill threshold. Misting is still allowed, but refill is recommended.</p>
              <div class="mini-stats">
                <div><span>Threshold</span><strong>40%</strong></div>
                <div><span>Lock</span><strong id="tankLock">Inactive</strong></div>
              </div>
            </div>
          </div>
        </article>
      </section>

      <section id="safety" class="dashboard-grid second-row">
        <article class="panel logic-panel">
          <h3>Sensor-Based Misting Logic</h3>
          <p class="panel-desc">The device uses VOC response and tank status before allowing misting.</p>
          <div class="logic-steps">
            <div class="logic-step allow">
              <span>Low VOC</span>
              <strong>Misting allowed</strong>
              <small>Fragrance response below target</small>
            </div>
            <div class="logic-step standby">
              <span>Normal VOC</span>
              <strong>Standby</strong>
              <small>Within target range</small>
            </div>
            <div class="logic-step lock">
              <span>High VOC</span>
              <strong>High VOC lock</strong>
              <small>Pause misting and alert user</small>
            </div>
          </div>
        </article>

        <article class="panel notifications-panel">
          <div class="panel-title-row">
            <div>
              <h3>Notifications</h3>
              <p>System alerts and latest activity.</p>
            </div>
            <span class="status-pill good">Live</span>
          </div>
          <ul class="notification-list" id="notificationList">
            <li><strong>Device connected</strong><span>ESP32-S3 main hub is online.</span></li>
            <li><strong>Refill recommended</strong><span>Tank level is below 40%.</span></li>
            <li><strong>Sensor data received</strong><span>VOC and humidity updated.</span></li>
          </ul>
        </article>
      </section>

      <section class="panel chart-panel">
        <div class="panel-title-row">
          <div>
            <h3>VOC Trend</h3>
            <p>Sample trend for prototype monitoring and testing demonstration.</p>
          </div>
          <span class="status-pill neutral">Last 7 readings</span>
        </div>
        <div class="chart" id="chartBars">
          <div style="height:44%"><span>95</span></div>
          <div style="height:56%"><span>120</span></div>
          <div style="height:63%"><span>132</span></div>
          <div style="height:50%"><span>108</span></div>
          <div style="height:72%"><span>156</span></div>
          <div style="height:58%"><span>126</span></div>
          <div style="height:61%"><span>132</span></div>
        </div>
        <div class="chart-labels"><span>-6</span><span>-5</span><span>-4</span><span>-3</span><span>-2</span><span>-1</span><span>Now</span></div>
      </section>

      <section id="logs" class="panel log-panel">
        <div class="panel-title-row">
          <div>
            <h3>Activity Logs</h3>
            <p>Records are for prototype testing and evaluation only.</p>
          </div>
          <span class="status-pill neutral"><?= $isAdmin ? 'CSV ready' : 'Live view' ?></span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>VOC</th>
                <th>Humidity</th>
                <th>Tank</th>
                <th>Mode</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="logBody">
              <tr>
                <td>15:50:21</td>
                <td>132</td>
                <td>62%</td>
                <td>30%</td>
                <td>Manual</td>
                <td><span class="table-tag good">Misted</span></td>
              </tr>
              <tr>
                <td>15:45:10</td>
                <td>188</td>
                <td>63%</td>
                <td>30%</td>
                <td>Sensor-Based</td>
                <td><span class="table-tag alert">Paused</span></td>
              </tr>
              <tr>
                <td>15:40:02</td>
                <td>118</td>
                <td>62%</td>
                <td>32%</td>
                <td>Time-Based</td>
                <td><span class="table-tag idle">Standby</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <script>
    window.currentUserRole = <?= json_encode($userRole) ?>;
    window.currentUserRoom = <?= json_encode($userRoom) ?>;
    window.currentDevice   = <?= json_encode($isAdmin ? 'SFD-01' : $sessionDevice) ?>;
  </script>
  <script src="script.js"></script>
</body>
</html>
