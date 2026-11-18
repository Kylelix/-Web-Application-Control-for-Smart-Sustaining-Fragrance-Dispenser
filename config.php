<?php
/**
 * config.php
 * Central backend configuration for SmartScent Gateway & Dashboard.
 * Keep DEVICE_API_KEY synchronized with API_KEY in firmware config.h.
 */

define('DEVICE_API_KEY', getenv('SMARTSCENT_API_KEY') ?: 'CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026');

// ESP32 cURL timeouts & caching
define('DEVICE_CONNECT_TIMEOUT_MS', 800);
define('DEVICE_TIMEOUT_MS',         1500);
define('TELEMETRY_CACHE_MS',        700);
define('OFFLINE_AFTER_MS',          5000);

// Logging & safety rate limits
define('SAMPLE_LOG_INTERVAL_S',     30);
define('SPRAY_MIN_GAP_S',           5);
define('CONTROL_RATE_LIMIT_MAX',    5);
define('CONTROL_RATE_LIMIT_WIN_S',  10);

// Session idle timeouts
define('IDLE_TIMEOUT_CLIENT_S',     1800); // 30 minutes
define('IDLE_TIMEOUT_ADMIN_S',      7200); // 2 hours
define('ACTIVE_RECHECK_INTERVAL_S', 20);   // Re-checks userlogin.is_active at most every 20s (< 30s logout rule)

// VOC thresholds (SGP41 VOC Index)
define('VOC_THRESHOLD_LOW',         100);
define('VOC_THRESHOLD_HIGH',        180);
define('VOC_THRESHOLD_HIGH_CLEAR',  170);

// Confirmed hardware spray profiles (Amendment 1)
define('DEFAULT_PROFILES', [
    'MILD'   => ['mist_s' => 5,  'cooldown_s' => 25],
    'STRONG' => ['mist_s' => 15, 'cooldown_s' => 15],
]);

define('CACHE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'cache');