# SmartScent REST API Contract v2

This document defines the HTTP/JSON contracts between:
1. **ESP32-S3 Firmware (v2.0.0)** and the **PHP Gateway** (`device_gateway.php`)
2. **Browser Frontend (`script.js`)** and the **PHP Gateway (`api/*.php`)**

> **Architectural Rule:** Browsers NEVER communicate directly with the ESP32-S3. All telemetry reads are cached and normalized by PHP, and all control commands pass through session authentication, role/room enforcement, CSRF verification, rate limiting, and value validation before reaching the hardware.

> **Privacy & Scope Rule:** Temperature and humidity (SHT40 values) are used internally on the ESP32 solely for SGP41 compensation and are NEVER exposed, logged, exported, or returned by any API.

---

## 1. ESP32-S3 Hardware Endpoints (Port 80)

### 1.1 `GET /` (Plain Text Info)
- **Auth:** Open (local network / SoftAP debug)
- **Response:** `200 OK`, `text/plain`
```text
SFD-01 API v2: /telemetry, /api/control
```
*(No HTML, no CDN assets, no LittleFS.)*

---

### 1.2 `GET /telemetry`
- **Auth:** Open, read-only
- **Headers:** `Access-Control-Allow-Origin: *`, `Content-Type: application/json`
- **Response (`200 OK`):**
```json
{
  "device_id": "SFD-01",
  "fw": "2.0.0",
  "uptime_s": 3621,
  "power": true,
  "mode": "SENSOR",
  "profile": "MILD",
  "interval_min": 60,
  "state": "IDLE",
  "phase": "NONE",
  "status": "IDLE",
  "water": true,
  "fan": false,
  "mist": false,
  "voc_index": 132,
  "voc_ready": true,
  "voc_source": "SIMULATED",
  "baseline": 128,
  "thresholds": {
    "low": 100,
    "high": 180
  },
  "lock": {
    "empty_tank": false,
    "high_voc": false
  },
  "cooldown_remaining_s": 0,
  "next_timed_s": 1800,
  "profiles": {
    "MILD": { "mist_s": 5, "cooldown_s": 25 },
    "STRONG": { "mist_s": 15, "cooldown_s": 15 }
  },
  "sensors": {
    "sgp41": "SIMULATED",
    "sht40": "MISSING"
  },
  "rssi": -58
}
```

#### Field Specifications
- **Backward compatibility:** Preserves `water`, `fan`, `mist`, `status`.
- **`mode`:** `MANUAL` | `TIMED` | `SENSOR` | `AUTO`
- **`profile`:** `MILD` | `STRONG`
- **`interval_min`:** `30` | `60` | `120`
- **`state`:** `OFF` | `IDLE` | `MISTING` | `COOLDOWN` | `LOCKED_OUT`
  - `OFF` whenever `power === false`.
  - `LOCKED_OUT` whenever `lock.empty_tank === true` or `lock.high_voc === true` (and `power === true`).
  - `MISTING` during active mist spray (`phase = FAN_PRE` or `MIST`).
  - `COOLDOWN` during post-spray rest period (`cooldown_remaining_s > 0`; `phase` is `FAN_POST` during the initial 5 s chamber-clearing fan run, then `NONE`).
- **`phase`:** `NONE` | `FAN_PRE` | `MIST` | `FAN_POST`
- **`voc_index`:** Integer when `voc_ready === true`; `null` while `voc_ready === false`.
- **`voc_source`:** `SGP41` | `SIMULATED` | `NONE`
- **`profiles` (Hardware-confirmed values):**
  - `MILD`: 5 s mist ON, 25 s cooldown OFF (5 s fan post-run overlaps start of cooldown).
  - `STRONG`: 15 s mist ON, 15 s cooldown OFF (5 s fan post-run overlaps start of cooldown).
- **`sensors`:** Status-only hardware diagnostics (`sgp41`: `OK` | `SIMULATED` | `FAIL`, `sht40`: `OK` | `MISSING`). Never contains temperature or humidity numbers.

---

### 1.3 `POST /api/control` (and `OPTIONS /api/control`)
- **Auth Header:** `X-Api-Key: <DEVICE_API_KEY>` (required on `POST`; `OPTIONS` returns `204 No Content` with CORS headers for debug use)
- **Content-Type:** `application/json`

#### Accepted JSON Commands
| Command | Payload Example | Notes |
|---|---|---|
| `POWER` | `{"command":"POWER","value":true}` | `value` boolean (`true`/`false`) |
| `SET_MODE` | `{"command":"SET_MODE","mode":"SENSOR"}` | `MANUAL`, `TIMED`, `SENSOR`, `AUTO` |
| `SET_PROFILE` | `{"command":"SET_PROFILE","profile":"STRONG"}` | `MILD`, `STRONG` |
| `SET_INTERVAL` | `{"command":"SET_INTERVAL","minutes":60}` | `30`, `60`, `120` |
| `SPRAY_NOW` | `{"command":"SPRAY_NOW"}` | Single cycle using active `profile` |
| `FAN` | `{"command":"FAN","value":true,"duration_s":5}` | Manual fan test (`duration_s`: `1` to `10` when `value:true`) |
| `SET_BASELINE` | `{"command":"SET_BASELINE"}` | Records current `voc_index` as `baseline` (requires `voc_ready:true`) |

#### Responses
- **Accepted (`200 OK`):**
```json
{
  "ok": true,
  "result": "ACCEPTED",
  "state": { "...full telemetry object..." }
}
```
- **Refused (`409 Conflict`):**
```json
{
  "ok": false,
  "error": "COOLDOWN_ACTIVE",
  "remaining_s": 18
}
```
  Error codes returned as HTTP `409`:
  - `TANK_EMPTY` — Water reservoir empty (`water === false`)
  - `HIGH_VOC_LOCK` — VOC index at or above high threshold (`>= 180` until `< 170`)
  - `POWER_OFF` — Master power is off
  - `COOLDOWN_ACTIVE` — Spray cooldown still running (includes `remaining_s`)
  - `THERMAL_LIMIT` — Rolling 10-minute atomizer ON limit (`MAX_ON_PER_10MIN_S = 300`) would be exceeded; UI displays *"Atomizer cooling down to protect the device."*
  - `BUSY` — A spray/fan cycle is already running (or VOC sensor not yet ready for `SET_BASELINE`)
- **Bad Request (`400 Bad Request`):**
  - `INVALID_COMMAND`
  - `INVALID_VALUE`
- **Unauthorized (`401 Unauthorized`):**
  - `UNAUTHORIZED` — Missing or mismatched `X-Api-Key` header

---

## 2. Browser <-> PHP Gateway Endpoints

All browser endpoints require an active authenticated PHP session (returning `401` JSON if unauthenticated or expired). Device `host` and `DEVICE_API_KEY` are never exposed.

### 2.1 `GET api/telemetry.php`
- **Query Params:** `?device=SFD-01` or `SFD-02` (**Admin only**; Clients have `device` ignored and locked to their session room's device).
- **Response (`200 OK`):**
```json
{
  "ok": true,
  "device_id": "SFD-01",
  "online": true,
  "stale_ms": 420,
  "server_time": "2026-11-17T19:45:00+08:00",
  "last_seen": "2026-11-17 19:44:59",
  "data": {
    "power": true,
    "mode": "SENSOR",
    "profile": "MILD",
    "interval_min": 60,
    "state": "IDLE",
    "phase": "NONE",
    "water": true,
    "tank_status": "OK",
    "fan": false,
    "mist": false,
    "voc_index": 132,
    "voc_status": "NORMAL",
    "voc_ready": true,
    "voc_source": "SIMULATED",
    "baseline": 128,
    "thresholds": { "low": 100, "high": 180 },
    "lock": { "empty_tank": false, "high_voc": false },
    "cooldown_remaining_s": 0,
    "next_timed_s": 1800,
    "profiles": {
      "MILD": { "mist_s": 5, "cooldown_s": 25 },
      "STRONG": { "mist_s": 15, "cooldown_s": 15 }
    }
  },
  "permissions": {
    "power": true,
    "profile": true,
    "spray": true,
    "mode": false,
    "interval": false,
    "fan": false,
    "baseline": false,
    "export": false,
    "switch_device": false
  }
}
```
- **Offline Rule:** If ESP32 is unreachable (cURL error, non-200, or cache age > `OFFLINE_AFTER_MS`), returns `online: false` with the last cached `data` and current `stale_ms`.
- **Whitelist Rule:** Gateway drops any non-whitelisted keys (never forwards `temperature` or `humidity`). `sensors` is omitted for Clients and included only when authenticated as `admin` (primarily consumed by `admin_devices.php`).

### 2.2 `POST api/control.php`
- **Headers:** `Content-Type: application/json`, `X-CSRF-Token: <session_csrf_token>`
- **Role Matrix:**
  - `client`: Only `POWER`, `SET_PROFILE`, `SPRAY_NOW` on their own assigned room device (`device` body property is ignored / rejected `403` if tampered to another device).
  - `admin`: All commands (`POWER`, `SET_MODE`, `SET_PROFILE`, `SET_INTERVAL`, `SPRAY_NOW`, `FAN`, `SET_BASELINE`) and may specify `"device": "SFD-01" | "SFD-02"`.
- **Status Codes:**
  - `200`: Command accepted (`{"ok":true,"result":"ACCEPTED","message":"...","telemetry":{...}}`)
  - `400`: Invalid JSON / `INVALID_COMMAND` / `INVALID_VALUE`
  - `401`: Session expired or guest checked out
  - `403`: CSRF failure or role unauthorized (`FORBIDDEN`)
  - `409`: Hardware refusal (`TANK_EMPTY`, `HIGH_VOC_LOCK`, `POWER_OFF`, `COOLDOWN_ACTIVE`, `THERMAL_LIMIT`, `BUSY`) or `SPRAY_MIN_GAP_S` guard
  - `429`: Rate limit exceeded (max 5 commands per 10 s per session)
  - `503`: Target device offline/unreachable

### 2.3 `GET api/logs.php` (and Admin `POST api/logs.php` for clearing)
- `GET api/logs.php?device=SFD-01&limit=8&page=1&date=YYYY-MM-DD` — Activity rows (`time`, `voc_index`, `voc_status`, `water_ok`, `tank_status`, `mode`, `event`, `tag`, `tag_class`, `detail`).
- `GET api/logs.php?type=notifications&device=SFD-01` — Latest 5 high-priority events (`LOCK_ON`, `LOCK_OFF`, `OFFLINE`, `ONLINE`, `COMMAND`, `BASELINE`, empty tank).
- `GET api/logs.php?type=trend&limit=7&device=SFD-01` — Latest 7 `SAMPLE` VOC readings in chronological order.
- `POST api/logs.php` (Admin only + CSRF): `{"action":"clear","device":"ALL|SFD-01|SFD-02"}` clears logs.

### 2.4 `GET api/export_csv.php` (Admin only)
- Streams UTF-8 BOM CSV with formula-injection protection.
- Query params: `?device=SFD-01&from=YYYY-MM-DD&to=YYYY-MM-DD` (all optional).
- Columns: `timestamp,device_id,event,voc_index,voc_status,tank,mode,profile,state,actor_role,detail`.