#ifndef SMARTSCENT_CONFIG_H
#define SMARTSCENT_CONFIG_H

// ============================================================================
// SmartScent ESP32-S3 Firmware v2.0.0 Configuration
// Copy this file to config.h before compiling in Arduino IDE.
// For Room 101: DEVICE_ID = "SFD-01", STATIC_IP_BYTES = {192, 168, 1, 15}
// For Room 102: DEVICE_ID = "SFD-02", STATIC_IP_BYTES = {192, 168, 1, 16}
// ============================================================================

#define DEVICE_ID            "SFD-01"

// Station (STA) Wi-Fi credentials (your router or phone hotspot)
#define WIFI_STA_SSID        "YOUR_WIFI_SSID"
#define WIFI_STA_PASS        "YOUR_WIFI_PASSWORD"

// Static IPv4 configuration for STA mode (adjust to match your Wi-Fi subnet)
// SFD-01 default: 192.168.1.15 | SFD-02 default: 192.168.1.16
#define STATIC_IP_BYTES      192, 168, 1, 15
#define STATIC_GW_BYTES      192, 168, 1, 1
#define STATIC_SN_BYTES      255, 255, 255, 0
#define STATIC_DNS_BYTES     8, 8, 8, 8

// Device API Key (must EXACTLY match DEVICE_API_KEY in config.php on the host laptop)
#define API_KEY              "CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026"

// Fallback to SoftAP (SSID: Fragrance-<DEVICE_ID>, IP: 192.168.4.1) if STA fails for 15 s
#define USE_SOFTAP_FALLBACK  1
#define SOFTAP_PASS          ""

// Sensor source switch:
//   1 = Simulate realistic VOC index on board (until V3 SGP41+SHT40 board is soldered)
//   0 = Poll real Sensirion SGP41 (0x59) with SHT40 (0x44) compensation at 1 Hz
#define SIMULATE_VOC         1

// XKC-Y23A-NPN water sensor polarity (matches ESP32S3TestCode.ino)
// HIGH = liquid detected when pin reads HIGH; flip to LOW if wired active-low.
#define WATER_ACTIVE_LEVEL   HIGH

#endif // SMARTSCENT_CONFIG_H