<?php
/**
 * db_connection.php
 * Database connection handler for SmartScent Dashboard.
 * Connects to Microsoft SQL Server using sqlsrv_connect.
 */

$serverName = "KYLELIX\\SQLEXPRESS"; 
$connectionOptions = [ 
    "Database" => "smartscent_db", 
    "Uid" => "",   
    "PWD" => "",
    "CharacterSet" => "UTF-8"
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
