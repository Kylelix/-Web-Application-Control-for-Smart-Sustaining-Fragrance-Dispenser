<?php
/**
 * login.php
 * SmartScent Dashboard — Authentication Page
 * Supports admin credentials and QR-based hotel room guest logins.
 */
require_once 'db_connection.php';
require_once 'auth_helpers.php';

// If already logged in, redirect straight to dashboard
if (isset($_SESSION['smartscent_user_id'])) {
    header("Location: index.php");
    exit();
}

// Sanitize room from QR URL (?room=101)
$room = preg_replace('/[^A-Za-z0-9]/', '', $_GET['room'] ?? '');

$error = '';
$infoMsg = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $infoMsg = "You have been logged out successfully.";
}

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'unauthorized') {
        $error = "Administrative privileges are required to access that feature.";
    } elseif ($_GET['error'] === 'no_room') {
        $error = "Your account is not assigned to a valid room. Please contact reception.";
    } elseif ($_GET['error'] === 'session') {
        $error = "Your session has expired or was ended. Please sign in again.";
    }
}

// Brute-force lockout duration in seconds (5 minutes)
$lockoutDuration = 300;

// Check if current session is locked out
if (isset($_SESSION['lockout_time']) && time() < $_SESSION['lockout_time']) {
    $remainingSeconds = $_SESSION['lockout_time'] - time();
    $remainingMinutes = max(1, ceil($remainingSeconds / 60));
    $error = "Too many failed login attempts. Please wait {$remainingMinutes} minute(s) before trying again.";
}

$submittedIdentifier = $_POST['identifier'] ?? '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // If lockout has expired, reset counter
    if (isset($_SESSION['lockout_time']) && time() >= $_SESSION['lockout_time']) {
        unset($_SESSION['lockout_time'], $_SESSION['failed_login_attempts']);
    }

    if (isset($_SESSION['lockout_time']) && time() < $_SESSION['lockout_time']) {
        // Still locked out
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $error = "Please enter both your room number/email and password.";
        } elseif ($conn === false) {
            $error = "Database service is currently unavailable. Please contact an administrator.";
        } else {
            // Parameterized query matching email OR room_number
            $sql = "SELECT id, email, password, role, full_name, is_active, room_number
                    FROM userlogin
                    WHERE email = ? OR room_number = ?";
            $params = [$identifier, $identifier];
            $stmt = @sqlsrv_query($conn, $sql, $params);

            if ($stmt !== false) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);

                if ($row) {
                    if ((int)$row['is_active'] !== 1) {
                        $error = "This account is currently deactivated. Please contact an administrator.";
                    } elseif (verifyPassword($password, $row['password'], (int)$row['id'], $conn)) {
                        unset($_SESSION['failed_login_attempts'], $_SESSION['lockout_time']);

                        // Regenerate session ID for security
                        session_regenerate_id(true);

                        $_SESSION['smartscent_user_id']  = $row['id'];
                        $_SESSION['smartscent_email']    = $row['email'];
                        $_SESSION['smartscent_role']     = strtolower($row['role']);
                        $_SESSION['smartscent_fullname'] = $row['full_name'] ?? 'SmartScent User';
                        $_SESSION['smartscent_room']     = $row['room_number'] ?? null;
                        $_SESSION['last_activity']       = time();
                        $_SESSION['last_active_check']   = time();
                        csrfToken();

                        header("Location: index.php");
                        exit();
                    } else {
                        $_SESSION['failed_login_attempts'] = ($_SESSION['failed_login_attempts'] ?? 0) + 1;
                        if ($_SESSION['failed_login_attempts'] >= 5) {
                            $_SESSION['lockout_time'] = time() + $lockoutDuration;
                            $error = "Too many failed login attempts. Please wait 5 minutes before trying again.";
                        } else {
                            $error = "Incorrect room number/email or password.";
                        }
                    }
                } else {
                    $_SESSION['failed_login_attempts'] = ($_SESSION['failed_login_attempts'] ?? 0) + 1;
                    if ($_SESSION['failed_login_attempts'] >= 5) {
                        $_SESSION['lockout_time'] = time() + $lockoutDuration;
                        $error = "Too many failed login attempts. Please wait 5 minutes before trying again.";
                    } else {
                        $error = "Incorrect room number/email or password.";
                    }
                }
            } else {
                $error = "Authentication service error. Please contact an administrator.";
            }
        }
    }
}

$prefilledIdentifier = $submittedIdentifier !== '' ? $submittedIdentifier : $room;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartScent — Sign In</title>
  <link rel="stylesheet" href="assets/inter.css">
  <link rel="stylesheet" href="assets/fontawesome.css">
  <link rel="stylesheet" href="login.css">
</head>
<body class="login-page">

<div class="login-container">
  <div class="login-card">
    <div class="login-brand">
      <div class="login-logo">
        <i class="fa-solid fa-leaf"></i>
      </div>
      <h2>SmartScent</h2>
      <p>VOC-response guided fragrance dispenser</p>

      <?php if (!empty($room)): ?>
        <div class="room-indicator-badge">
          <i class="fa-solid fa-hotel"></i>
          <span>Room <?= htmlspecialchars($room) ?> Guest Access</span>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($error)): ?>
      <div class="login-alert alert-error" role="alert">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($infoMsg)): ?>
      <div class="login-alert alert-info" role="status">
        <i class="fa-solid fa-circle-check"></i>
        <span><?= htmlspecialchars($infoMsg) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php<?= !empty($room) ? '?room=' . urlencode($room) : '' ?>" id="loginForm">
      <div class="form-group">
        <label class="form-label" for="identifier">Email or Room Number</label>
        <div class="input-wrapper">
          <i class="fa-solid fa-user input-icon"></i>
          <input
            type="text"
            id="identifier"
            name="identifier"
            class="form-input"
            required
            placeholder="e.g. 101 or admin@smartscent.com"
            value="<?= htmlspecialchars($prefilledIdentifier) ?>"
            autocomplete="username"
          >
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="input-wrapper">
          <i class="fa-solid fa-lock input-icon"></i>
          <input
            type="password"
            id="password"
            name="password"
            class="form-input"
            required
            placeholder="••••••••"
            autocomplete="current-password"
          >
          <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
            <i class="fa-regular fa-eye" id="toggleIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-submit">
        <i class="fa-solid fa-arrow-right-to-bracket"></i>
        <span>Sign In</span>
      </button>
    </form>

    <div class="db-status-pill">
      <span class="db-dot <?= $conn !== false ? 'connected' : 'offline' ?>"></span>
      <span><?= $conn !== false ? 'SQL Server Connected (smartscent_db)' : 'Database Offline / Unreachable' ?></span>
    </div>
  </div>
</div>

<script>
  const toggleBtn = document.getElementById('togglePassword');
  const passwordInput = document.getElementById('password');
  const toggleIcon = document.getElementById('toggleIcon');

  toggleBtn.addEventListener('click', () => {
    const isPassword = passwordInput.getAttribute('type') === 'password';
    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
    toggleIcon.className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
  });
</script>
</body>
</html>