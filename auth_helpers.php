<?php
/**
 * auth_helpers.php
 * Authentication, CSRF, idle timeout, and room-device authorization helpers for SmartScent.
 */

function isAdmin(?string $role): bool {
    return strtolower((string)$role) === 'admin';
}

function isClient(?string $role): bool {
    return strtolower((string)$role) === 'client';
}

/**
 * Send a JSON response with HTTP status and terminate execution.
 */
function jsonResponse(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Clear session state and cookie safely.
 */
function clearAuthSession(): void {
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Generate or return the per-session CSRF token.
 */
function csrfToken(): string {
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from X-CSRF-Token header or provided parameter.
 */
function csrfCheck(?string $provided = null): bool {
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '') {
        return false;
    }
    $candidate = $provided;
    if ($candidate === null || $candidate === '') {
        $candidate = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    }
    if (!is_string($candidate) || $candidate === '') {
        return false;
    }
    return hash_equals($expected, $candidate);
}

/**
 * Enforce role-based idle timeout (30 min Client, 2 h Admin).
 * Passive polling passes $touchActivity = false so background tabs still time out.
 */
function enforceIdleTimeout(bool $isApi = false, bool $touchActivity = true): void {
    if (!isset($_SESSION['smartscent_user_id'])) {
        return;
    }
    $now = time();
    $role = $_SESSION['smartscent_role'] ?? 'client';
    $limit = isAdmin($role)
        ? (defined('IDLE_TIMEOUT_ADMIN_S') ? IDLE_TIMEOUT_ADMIN_S : 7200)
        : (defined('IDLE_TIMEOUT_CLIENT_S') ? IDLE_TIMEOUT_CLIENT_S : 1800);

    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
    if (($now - $lastActivity) > $limit) {
        clearAuthSession();
        if ($isApi) {
            jsonResponse([
                'ok'      => false,
                'error'   => 'SESSION_EXPIRED',
                'message' => 'Your session expired due to inactivity. Please sign in again.'
            ], 401);
        }
        header('Location: login.php?error=session');
        exit();
    }

    if ($touchActivity) {
        $_SESSION['last_activity'] = $now;
    } elseif (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = $now;
    }
}

/**
 * Re-checks userlogin.is_active in SQL Server at most every 20 seconds per session
 * so a checked-out guest loses access promptly (within 30 s).
 */
function verifyAccountStillActive(bool $isApi = false): void {
    global $conn;
    $userId = (int)($_SESSION['smartscent_user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $now = time();
    $lastCheck = (int)($_SESSION['last_active_check'] ?? 0);
    $interval = defined('ACTIVE_RECHECK_INTERVAL_S') ? ACTIVE_RECHECK_INTERVAL_S : 20;

    if ($lastCheck > 0 && ($now - $lastCheck) < $interval) {
        return;
    }

    if (!isset($conn) || $conn === false) {
        return;
    }

    $stmt = @sqlsrv_query(
        $conn,
        'SELECT is_active, role, room_number FROM userlogin WHERE id = ?',
        [$userId]
    );
    if ($stmt === false) {
        return;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    $_SESSION['last_active_check'] = $now;

    if (!$row || (int)$row['is_active'] !== 1) {
        clearAuthSession();
        if ($isApi) {
            jsonResponse([
                'ok'      => false,
                'error'   => 'ACCOUNT_DEACTIVATED',
                'message' => 'Your session ended because this account is inactive or checked out.'
            ], 401);
        }
        header('Location: login.php?error=session');
        exit();
    }

    $_SESSION['smartscent_role'] = strtolower((string)$row['role']);
    $_SESSION['smartscent_room'] = $row['room_number'] ?? null;
}

/**
 * Ensures the user is logged in (HTML pages). Redirects to login.php if not authenticated.
 */
function requireLogin(): void {
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
        session_start();
    }
    if (!isset($_SESSION['smartscent_user_id'])) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Maps a room number to its prototype hardware device ID via the devices table (with fallback).
 */
function getDeviceForRoom(?string $room): ?string {
    if ($room === null) {
        return null;
    }
    $cleanRoom = trim((string)$room);
    if ($cleanRoom === '') {
        return null;
    }

    global $conn;
    if (isset($conn) && $conn !== false) {
        $stmt = @sqlsrv_query(
            $conn,
            'SELECT TOP 1 device_id FROM devices WHERE room_number = ?',
            [$cleanRoom]
        );
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            return $row ? (string)$row['device_id'] : null;
        }
    }

    $map = [
        '101' => 'SFD-01',
        '102' => 'SFD-02',
    ];
    return $map[$cleanRoom] ?? null;
}

/**
 * Ensures clients have a valid assigned hotel room and active account (HTML pages).
 * Administrators are exempt from room binding.
 */
function requireRoomAccess(): void {
    requireLogin();
    enforceIdleTimeout(false, true);
    verifyAccountStillActive(false);

    $role = $_SESSION['smartscent_role'] ?? '';
    if (isAdmin($role)) {
        return;
    }

    $room = $_SESSION['smartscent_room'] ?? null;
    $device = getDeviceForRoom($room);

    if (empty($room) || $device === null) {
        clearAuthSession();
        header('Location: login.php?error=no_room');
        exit();
    }
}

/**
 * Ensures the user is an admin (HTML pages).
 */
function requireAdmin(): void {
    requireLogin();
    enforceIdleTimeout(false, true);
    verifyAccountStillActive(false);
    if (!isAdmin($_SESSION['smartscent_role'] ?? '')) {
        header('Location: index.php?error=unauthorized');
        exit();
    }
}

/**
 * API equivalent of requireRoomAccess(), always returning JSON errors on failure.
 */
function requireLoginApi(bool $touchActivity = true): void {
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
        session_start();
    }
    if (!isset($_SESSION['smartscent_user_id'])) {
        jsonResponse([
            'ok'      => false,
            'error'   => 'UNAUTHORIZED',
            'message' => 'Authentication required.'
        ], 401);
    }

    enforceIdleTimeout(true, $touchActivity);
    verifyAccountStillActive(true);

    $role = $_SESSION['smartscent_role'] ?? '';
    if (!isAdmin($role)) {
        $room = $_SESSION['smartscent_room'] ?? null;
        $device = getDeviceForRoom($room);
        if (empty($room) || $device === null) {
            clearAuthSession();
            jsonResponse([
                'ok'      => false,
                'error'   => 'NO_ROOM',
                'message' => 'Room assignment invalid.'
            ], 401);
        }
    }
}

/**
 * API guard requiring Administrator role.
 */
function requireAdminApi(): void {
    requireLoginApi(true);
    if (!isAdmin($_SESSION['smartscent_role'] ?? '')) {
        jsonResponse([
            'ok'      => false,
            'error'   => 'FORBIDDEN',
            'message' => 'Administrator privileges are required.'
        ], 403);
    }
}

function roleLabel(string $role, ?string $room = null): string {
    if (strtolower($role) === 'client' && !empty($room)) {
        return 'Room ' . htmlspecialchars($room) . ' Guest';
    }
    return match (strtolower($role)) {
        'admin'  => 'Administrator',
        'client' => 'Client Guest',
        default  => ucfirst($role),
    };
}

function roleBadgeClass(string $role): string {
    return match (strtolower($role)) {
        'admin'  => 'badge-admin',
        'client' => 'badge-client',
        default  => 'badge-neutral',
    };
}

function verifyPassword(string $submitted, string $stored, int $userId, $conn): bool {
    if (password_verify($submitted, $stored)) {
        return true;
    }
    if ($submitted === $stored) {
        if ($conn !== false) {
            $hashed = password_hash($submitted, PASSWORD_BCRYPT);
            @sqlsrv_query($conn, 'UPDATE userlogin SET password = ? WHERE id = ?', [$hashed, $userId]);
        }
        return true;
    }
    return false;
}

function hashPassword(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT);
}