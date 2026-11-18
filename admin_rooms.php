<?php
/**
 * admin_rooms.php
 * Admin-only Front Desk Room Access Console (Guest Check-In / Check-Out).
 */
require_once 'db_connection.php';
require_once 'auth_helpers.php';
require_once 'device_gateway.php';

requireAdmin();

$userName = $_SESSION['smartscent_fullname'] ?? 'System Administrator';
$csrf     = csrfToken();
$errorMsg = '';
$infoMsg  = '';

function generateTempPassword(int $length = 8): string {
    // Unambiguous alphanumeric set (excludes 0/O/1/l/I)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $maxIdx = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $maxIdx)];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck($_POST['csrf_token'] ?? null)) {
        $errorMsg = 'Security token validation failed. Please refresh and try again.';
    } elseif ($conn === false) {
        $errorMsg = 'Database connection unavailable.';
    } else {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $roomNo = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['room_number'] ?? ''));

        if ($roomNo === '' || !in_array($action, ['checkin', 'checkout'], true)) {
            $errorMsg = 'Invalid room action requested.';
        } elseif ($action === 'checkin') {
            $tempPw = generateTempPassword(8);
            $hashed = hashPassword($tempPw);

            $stmt = @sqlsrv_query(
                $conn,
                'UPDATE userlogin SET is_active = 1, password = ? WHERE room_number = ?',
                [$hashed, $roomNo]
            );
            if ($stmt !== false && sqlsrv_rows_affected($stmt) > 0) {
                sqlsrv_free_stmt($stmt);
                // Store in one-time flash so password is shown ONCE on screen via PRG
                $_SESSION['flash_room_checkin'] = [
                    'room'     => $roomNo,
                    'password' => $tempPw,
                ];
                header('Location: admin_rooms.php');
                exit();
            } else {
                if ($stmt !== false) sqlsrv_free_stmt($stmt);
                $errorMsg = "Room {$roomNo} account not found in database.";
            }
        } elseif ($action === 'checkout') {
            $stmt = @sqlsrv_query(
                $conn,
                'UPDATE userlogin SET is_active = 0 WHERE room_number = ?',
                [$roomNo]
            );
            if ($stmt !== false && sqlsrv_rows_affected($stmt) > 0) {
                sqlsrv_free_stmt($stmt);
                $_SESSION['flash_room_msg'] = "Room {$roomNo} checked out. Any active guest session will terminate within 30 seconds.";
                header('Location: admin_rooms.php');
                exit();
            } else {
                if ($stmt !== false) sqlsrv_free_stmt($stmt);
                $errorMsg = "Could not check out Room {$roomNo}.";
            }
        }
    }
}

$revealedCheckin = $_SESSION['flash_room_checkin'] ?? null;
unset($_SESSION['flash_room_checkin']);

if (isset($_SESSION['flash_room_msg'])) {
    $infoMsg = (string)$_SESSION['flash_room_msg'];
    unset($_SESSION['flash_room_msg']);
}

$rooms = [];
if ($conn !== false) {
    $sql = 'SELECT u.id, u.room_number, u.is_active, u.role, d.device_id
            FROM userlogin u
            LEFT JOIN devices d ON d.room_number = u.room_number
            WHERE u.room_number IS NOT NULL AND LOWER(u.role) = \'client\'
            ORDER BY u.room_number ASC';
    $stmt = @sqlsrv_query($conn, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rooms[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    } else {
        $errorMsg = 'Could not load room records. Ensure database upgrade scripts were executed.';
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
  <title>SmartScent — Room Check-In &amp; Check-Out</title>
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
        <a class="active" href="admin_rooms.php"><i class="fa-solid fa-key nav-icon"></i>Room Access</a>
        <a href="admin_devices.php"><i class="fa-solid fa-microchip nav-icon"></i>Devices</a>
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
          <p class="eyebrow">Reception &amp; Access Control</p>
          <h2>Guest Room Check-In / Check-Out</h2>
          <p class="top-desc">Issue one-time temporary room passwords on check-in and revoke QR portal access on check-out.</p>
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

      <?php if (is_array($revealedCheckin)): ?>
        <div class="password-reveal-box" role="status">
          <strong><i class="fa-solid fa-key"></i> Room <?= htmlspecialchars((string)$revealedCheckin['room']) ?> Checked In — Temporary Password (Shown Once):</strong>
          <div>
            <code class="password-reveal-code"><?= htmlspecialchars((string)$revealedCheckin['password']) ?></code>
          </div>
          <p style="margin:4px 0 0; font-size:0.84rem; color:#065f46;">
            Give this 8-character password to the guest and have them scan the Room <?= htmlspecialchars((string)$revealedCheckin['room']) ?> QR code. It is already stored as a secure bcrypt hash and will not be displayed again after leaving or refreshing this page.
          </p>
        </div>
      <?php endif; ?>

      <section class="admin-grid">
        <?php foreach ($rooms as $r): ?>
          <?php
            $roomNo = (string)$r['room_number'];
            $active = ((int)$r['is_active'] === 1);
            $devId  = $r['device_id'] ? (string)$r['device_id'] : 'Unassigned';
          ?>
          <article class="panel">
            <div class="panel-title-row">
              <div>
                <h3><i class="fa-solid fa-door-closed"></i> Room <?= htmlspecialchars($roomNo) ?></h3>
                <p>Assigned Prototype: <strong><?= htmlspecialchars($devId) ?></strong></p>
              </div>
              <span class="status-pill <?= $active ? 'good' : 'neutral' ?>">
                <?= $active ? 'Active Guest' : 'Checked Out' ?>
              </span>
            </div>

            <p style="font-size:0.84rem; color:var(--muted); margin-bottom:16px;">
              <?= $active
                ? 'Guest access is enabled. Checking out revokes login and terminates any active guest dashboard session within 30 seconds.'
                : 'Access is currently disabled. Check-in generates a fresh 8-character temporary password hashed in bcrypt.' ?>
            </p>

            <div class="control-actions-row">
              <form method="POST" action="admin_rooms.php" style="flex:1;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                <input type="hidden" name="action" value="checkin" />
                <input type="hidden" name="room_number" value="<?= htmlspecialchars($roomNo) ?>" />
                <button type="submit" class="primary-btn" style="width:100%;">
                  <i class="fa-solid fa-key btn-icon"></i>Check In / Reset Password
                </button>
              </form>

              <form method="POST" action="admin_rooms.php" style="flex:1;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                <input type="hidden" name="action" value="checkout" />
                <input type="hidden" name="room_number" value="<?= htmlspecialchars($roomNo) ?>" />
                <button type="submit" class="danger-ghost-btn" style="width:100%;" <?= !$active ? 'disabled' : '' ?>>
                  <i class="fa-solid fa-arrow-right-from-bracket btn-icon"></i>Check Out
                </button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    </main>
  </div>
</body>
</html>