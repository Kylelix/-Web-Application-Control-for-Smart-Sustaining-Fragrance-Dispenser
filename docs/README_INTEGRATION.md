# SmartScent Hardware & Software Integration Guide

Complete deployment and testing manual for the two-room SmartScent prototype (`SFD-01` in Room 101 and `SFD-02` in Room 102).

---

## 1. Architecture Summary
```
Browser (Admin laptop / Guest phones)
   |  HTTP/HTTPS + Session Cookie + CSRF Token
   v
PHP Gateway on Host Laptop (XAMPP Apache + api/*.php) <---> SQL Server (smartscent_db)
   |  Server-side cURL + X-Api-Key (cached under cache/ with flock)
   v
ESP32-S3 SFD-01 (192.168.1.15)     ESP32-S3 SFD-02 (192.168.1.16)
```
Browsers **never** contact the ESP32 directly. This guarantees server-side room/role enforcement, single-threaded ESP32 protection via cached polling, central SQL Server logging, and API key confidentiality.

---

## 2. Web Server Setup (Use XAMPP Apache)
- **Important:** Serve the site with **XAMPP Apache** (multi-threaded) — copy or symlink this folder to `C:\xampp\htdocs\smartscent`.
- Single-threaded `php -S` on Windows blocks parallel browser requests if a device is offline or timing out. If you use `php -S` anyway for quick local debug, keep cURL timeouts short (already configured in `config.php`: 800 ms connect / 1500 ms total).
- Remember to keep `PORT` and `BASE_PATH` in `qr/generate_qr.py` aligned with your real web server URL before generating printable QR cards.

---

## 3. Database Setup (SSMS)
Run these scripts in **SQL Server Management Studio (SSMS)** in exact order:
1. `sql/smartscent_db_setup.sql` (if fresh install)
2. `sql/qr_room_upgrade.sql`
3. `sql/hardware_integration_upgrade.sql` — creates `devices` and `activity_log` and seeds:
   - `SFD-01` -> Room `101` (`192.168.1.15`)
   - `SFD-02` -> Room `102` (`192.168.1.16`)

---

## 4. Flashing the ESP32-S3 Firmware (`ESP32S3_Fragrance_V2`)
1. Open Arduino IDE with **esp32 by Espressif Systems (Core 3.x)** and select **ESP32S3 Dev Module**.
2. Install libraries from Arduino Library Manager:
   - `Adafruit SSD1306`, `Adafruit GFX Library`, `ArduinoJson` (>= 7.x)
   - *(When `SIMULATE_VOC` is set to `0`)*: `Sensirion I2C SGP41`, `Sensirion I2C SHT4x`, `Sensirion Gas Index Algorithm`
3. Copy `firmware/ESP32S3_Fragrance_V2/config.example.h` to `firmware/ESP32S3_Fragrance_V2/config.h`.
4. Edit `config.h` for Board 1 (`SFD-01`, `192.168.1.15`), verify `API_KEY` matches `DEVICE_API_KEY` in `config.php`, and upload.
5. Repeat with `DEVICE_ID "SFD-02"` and `192.168.1.16` for Board 2.

### SoftAP Bench Mode vs STA Two-Prototype Mode
- **STA Mode (Required for simultaneous 2-room demo):** Both boards connect to the router/hotspot with static IPs (`192.168.1.15` & `192.168.1.16`).
- **SoftAP Fallback:** If Wi-Fi STA fails for 15 s, a board starts `Fragrance-SFD-01` at `192.168.4.1`. Power only one SoftAP unit at a time and temporarily set its `host` to `192.168.4.1` on `admin_devices.php`.

---

## 5. Hardware-Free Testing (Mock ESP32 + Background Collector)
To run full dashboard, lock, cooldown, and thermal tests without hardware:
1. Start mock devices in two terminals:
   ```cmd
   C:\xampp\php\php.exe -S 127.0.0.1:8081 tools/mock_esp32.php
   C:\xampp\php\php.exe -S 127.0.0.1:8082 tools/mock_esp32.php
   ```
2. Sign in as Admin (`admin@smartscent.com`) -> open **`admin_devices.php`** -> set `SFD-01` host to `127.0.0.1:8081` and `SFD-02` to `127.0.0.1:8082` -> click **Save Device Settings** and **Test Connection**.
3. Run the background telemetry collector in another terminal so 30 s `SAMPLE` logs and transitions save continuously even with no browser open:
   ```cmd
   C:\xampp\php\php.exe tools/collector.php
   ```
4. When finished testing, set the hosts back to your real ESP32 static IPs (`192.168.1.15` / `192.168.1.16`) via `admin_devices.php`.

---

## 6. Curl Reference (Direct Device API v2)
```bash
# Telemetry (open GET)
curl http://192.168.1.15/telemetry

# Power ON / OFF
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"POWER\",\"value\":true}"

# Set Mode (MANUAL | TIMED | SENSOR | AUTO)
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"SET_MODE\",\"mode\":\"SENSOR\"}"

# Set Dose Profile (MILD=5s/25s | STRONG=15s/15s)
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"SET_PROFILE\",\"profile\":\"STRONG\"}"

# Set Timed Interval (30 | 60 | 120)
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"SET_INTERVAL\",\"minutes\":30}"

# Spray Now
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"SPRAY_NOW\"}"

# Fan Test (1 to 10 s)
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"FAN\",\"value\":true,\"duration_s\":5}"

# Set VOC Baseline
curl -X POST http://192.168.1.15/api/control -H "Content-Type: application/json" -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" -d "{\"command\":\"SET_BASELINE\"}"
```

---

## 7. Troubleshooting Table
| Symptom | Cause | Resolution |
|---|---|---|
| Dashboard shows `OFFLINE` | Wrong IP in `devices`, device powered off, or different Wi-Fi | Check `admin_devices.php` -> **Test Connection**; verify OLED displays STA IP on the same subnet as laptop |
| Control command returns `401 UNAUTHORIZED` from device | `API_KEY` mismatch between `config.h` and `config.php` | Match `DEVICE_API_KEY` in `config.php` with `API_KEY` in `config.h` |
| Spray refused with `TANK_EMPTY` when water is present | Sensor polarity inverted or slosh debounce | Check sensor LED; flip `WATER_ACTIVE_LEVEL` in `config.h` (`HIGH` <-> `LOW`) |
| Spray refused with `THERMAL_LIMIT` | Exceeded 300 s atomizer ON in 10 minutes | Wait a few minutes for piezo rolling thermal window to cool down |
| Checked-out guest still sees dashboard | Check interval runs every 20 s (< 30 s) | Wait up to 20-25 s for automatic polling kickout to `login.php?error=session` |
| Browser console reports SQL Server offline | SQL Server Express service stopped | Start `SQL Server (SQLEXPRESS)` in Windows Services |