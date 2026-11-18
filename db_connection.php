<?php
/**
 * db_connection.php
 * Database connection handler, security headers, and hardened session setup.
 * Connects to Microsoft SQL Server using sqlsrv_connect.
 */

require_once __DIR__ . '/config.php';

// Security headers on all HTTP responses
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

// Secure session cookie parameters before starting session
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$serverName = "KYLELIX\\SQLEXPRESS";
$connectionOptions = [
    "Database"     => "smartscent_db",
    "Uid"          => "",
    "PWD"          => "",
    "CharacterSet" => "UTF-8",
    "LoginTimeout" => 3
];

$conn = @sqlsrv_connect($serverName, $connectionOptions);

// Fallback attempt if local default instance is used without hostname prefix
if ($conn === false) {
    $fallbackServers = [".\\SQLEXPRESS", "localhost\\SQLEXPRESS", "(local)\\SQLEXPRESS", "localhost"];
    foreach ($fallbackServers as $altServer) {
        $conn = @sqlsrv_connect($altServer, $connectionOptions);
        if ($conn !== false) {
            $serverName = $altServer;
            break;
        }
    }
}