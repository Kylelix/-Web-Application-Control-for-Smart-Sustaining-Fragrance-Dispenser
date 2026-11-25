# SmartScent ESP32-S3 Firmware v2 Notes

Firmware location: `firmware/ESP32S3_Fragrance_V2/ESP32S3_Fragrance_V2.ino`  
Original reference sketch (kept untouched): `firmware/ESP32S3TestCode/ESP32S3TestCode.ino`

---

## 1. Arduino IDE & Board Manager Setup
- **Board Package:** `esp32` by Espressif Systems **Core v3.x** (tested for `ledcAttach(pin, freq, res)` API).
- **Board:** `ESP32S3 Dev Module`
- **Recommended Tools Settings:**
  - USB CDC On Boot: `Enabled` (for Serial Monitor over USB)
  - Flash Size: `8MB (64Mb)` or matching your DevKitC module
  - Partition Scheme: `Default 4MB with spiffs` (or `Huge APP` — LittleFS is NOT needed; firmware serves JSON only)
  - Upload Speed: `921600`

---

## 2. Required Libraries (Arduino Library Manager)
Always required:
1. **Adafruit SSD1306** (by Adafruit)
2. **Adafruit GFX Library** (by Adafruit)
3. **ArduinoJson** (by Benoit Blanchon — **v7.x**)

Required only when `SIMULATE_VOC` is set to `0` (real V3 PCB with Sensirion sensors):
4. **Sensirion I2C SGP41** (by Sensirion)
5. **Sensirion I2C SHT4x** (by Sensirion)
6. **Sensirion Gas Index Algorithm** (by Sensirion)

---

## 3. Per-Device `config.h` Setup
1. Copy `firmware/ESP32S3_Fragrance_V2/config.example.h` to `firmware/ESP32S3_Fragrance_V2/config.h` (`config.h` is `.gitignore`d).
2. Edit `config.h` separately before flashing each unit:
   - **Room 101 (`SFD-01`)**:
     - `#define DEVICE_ID "SFD-01"`
     - `#define STATIC_IP_BYTES 192, 168, 1, 15`
   - **Room 102 (`SFD-02`)**:
     - `#define DEVICE_ID "SFD-02"`
     - `#define STATIC_IP_BYTES 192, 168, 1, 16`
   - Set `WIFI_STA_SSID`, `WIFI_STA_PASS`, `STATIC_GW_BYTES`, and `STATIC_SN_BYTES` to match your test router or phone hotspot.
   - Set `API_KEY` to **the exact same value** as `DEVICE_API_KEY` in `config.php`.
   - Keep `SIMULATE_VOC 1` until the V3 SGP41 + SHT40 board is soldered.

---

## 4. Networking Modes
- **STA Mode (Default / Two-Room Test):** Connects to your Wi-Fi/hotspot using its assigned static IP with `WiFi.setSleep(false)` for fast API response. Automatically retries every 15 s if disconnected.
- **SoftAP Fallback (Bench Debug):** If STA connection fails for 15 s (`USE_SOFTAP_FALLBACK 1`), opens `Fragrance-SFD-01` (or `Fragrance-SFD-02`) at `192.168.4.1`. Only power one SoftAP board at a time to avoid IP collisions.

---

## 5. Quick `curl` Hardware Verification
Check device text info:
```bash
curl http://192.168.1.15/
```
Read live JSON telemetry (open):
```bash
curl http://192.168.1.15/telemetry
```
Trigger a spray cycle (requires `X-Api-Key`):
```bash
curl -X POST http://192.168.1.15/api/control \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" \
  -d "{\"command\":\"SPRAY_NOW\"}"
```
Test fan manually for 5 seconds:
```bash
curl -X POST http://192.168.1.15/api/control \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" \
  -d "{\"command\":\"FAN\",\"value\":true,\"duration_s\":5}"
```
Switch profile to STRONG (15 s mist, 15 s cooldown):
```bash
curl -X POST http://192.168.1.15/api/control \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: CHANGE_ME_SMARTSCENT_DEVICE_KEY_2026" \
  -d "{\"command\":\"SET_PROFILE\",\"profile\":\"STRONG\"}"
```

---

## 6. Items Marked TBD (Hardware Owner Confirmation Needed)
| Item | Current Value | Where Defined | Notes |
|---|---|---|---|
| Water sensor polarity | `WATER_ACTIVE_LEVEL HIGH` | `config.h` | Matches `ESP32S3TestCode.ino`; flip to `LOW` if inverted |
| Fan pre-run duration | `FAN_PRE_MS = 0UL` | `.ino` line 52 | May increase to `1000` if airflow stabilization is needed before misting |
| Sensor mode settle wait | `SETTLE_S = 30UL` | `.ino` line 59 | Min seconds after mist before `SENSOR`/`AUTO` triggers again |
| Piezo 10-min thermal cap | `MAX_ON_PER_10MIN_S = 300UL` | `.ino` line 60 | Tuning limit before returning `THERMAL_LIMIT` (409) |
| Real VOC sensor readiness | `SIMULATE_VOC = 1` | `config.h` | Flip to `0` once SGP41 + SHT40 PCB is soldered and verify 45 s blackout warm-up |
| Water level debounce | `300 ms empty / 2000 ms refill` | `.ino` lines 66-67 | Tune if XKC-Y23A-NPN slosh still triggers false empties |
| Actual test router subnet | `192.168.1.15` / `192.168.1.16` | `config.h` & SQL | Match your hotspot/router subnet and update `admin_devices.php` |