<?php
/**
 * auth_helpers.php
 * Authentication and role permission helper functions for SmartScent.
 */

/**
 * Returns true if the user has an admin role.
 */
function isAdmin(?string $role): bool {
    return strtolower((string)$role) === 'admin';
}

/**
 * Returns true if the user has a client role.
 */
function isClient(?string $role): bool {
    return strtolower((string)$role) === 'client';
}

/**
 * Ensures the user is logged in. Redirects to login.php if not authenticated.
 */
function requireLogin(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['smartscent_user_id'])) {
        header("Location: login.php");
        exit();
    }
}

/**
 * Maps a room number to its prototype hardware device ID.
 */
function getDeviceForRoom(?string $room): ?string {
    if ($room === null) {
        return null;
    }
    $map = [
        '101' => 'SFD-01',
        '102' => 'SFD-02'
    ];
    $cleanRoom = trim((string)$room);
    return $map[$cleanRoom] ?? null;
}

/**
 * Ensures clients have a valid assigned hotel room.
 * Clients without a valid room are signed out and redirected with an error.
 * Administrators are exempt.
 */
function requireRoomAccess(): void {
    requireLogin();
    $role = $_SESSION['smartscent_role'] ?? '';
    if (isAdmin($role)) {
        return; // Admins are exempt
    }

    $room = $_SESSION['smartscent_room'] ?? null;
    $device = getDeviceForRoom($room);

    if (empty($room) || $device === null) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header("Location: login.php?error=no_room");
        exit();
    }
}

/**
 * Ensures the user is an admin. Redirects with unauthorized warning if not.
 */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin($_SESSION['smartscent_role'] ?? '')) {
        header("Location: index.php?error=unauthorized");
        exit();
    }
}

/**
 * Formatted role label for UI display.
 */
function roleLabel(string $role, ?string $room = null): string {
    if (strtolower($role) === 'client' && !empty($room)) {
        return "Room " . htmlspecialchars($room) . " Guest";
    }
    return match (strtolower($role)) {
        'admin'  => 'Administrator',
        'client' => 'Client Guest',
        default  => ucfirst($role)
    };
}

/**
 * CSS badge class based on role.
 */
function roleBadgeClass(string $role): string {
    return match (strtolower($role)) {
        'admin'  => 'badge-admin',
        'client' => 'badge-client',
        default  => 'badge-neutral'
    };
}

/**
 * Verify submitted password against stored hash/string.
 * Transparently upgrades plain-text passwords into bcrypt hashes upon successful authentication.
 */
function verifyPassword(string $submitted, string $stored, int $userId, $conn): bool {
    // 1. Try standard bcrypt check
    if (password_verify($submitted, $stored)) {
        return true;
    }

    // 2. Plain-text check with auto-migration to bcrypt
    if ($submitted === $stored) {
        if ($conn !== false) {
            $hashed = password_hash($submitted, PASSWORD_BCRYPT);
            @sqlsrv_query($conn, "UPDATE userlogin SET password = ? WHERE id = ?", [$hashed, $userId]);
        }
        return true;
    }

    return false;
}

/**
 * Helper to generate a standard bcrypt password hash.
 */
function hashPassword(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT);
}
?>
