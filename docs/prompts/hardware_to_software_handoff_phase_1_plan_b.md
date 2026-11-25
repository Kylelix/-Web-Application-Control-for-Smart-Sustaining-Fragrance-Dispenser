# Hardware-to-Software Integration Handoff (Phase 1 — Plan B)

**Target Hardware:** ESP32-S3 DevKitC Carrier PCB + COTS Blue 3–4.5V Atomizer Module (powered via dedicated `LD1117-3.3` rail)  
**Target Firmware:** `firmware/ESP32S3_Fragrance_V2/ESP32S3_Fragrance_V2.ino` & `config.h` (Arduino-ESP32 Core 3.x)

To align the v2 firmware with the finalized Phase 1 carrier PCB, please apply the following two updates:

---

## 1. Split the I2C Bus (Dual Hardware I2C)

On the carrier PCB, the OLED and gas/environmental sensors are on **separate I2C buses** (matching the proven wiring from `ESP32S3TestCode`) so OLED charge-pump noise does not interfere with VOC reads.

| Bus | Instance | SDA | SCL | Devices & Addresses |
|---|---|---|---|---|
| **Bus 0 (Sensors)** | `Wire` / `TwoWire(0)` | `GPIO5` | `GPIO6` | `SHT40` (`0x44`), `SGP41` (`0x59`) — external 5.1 kΩ pull-ups on board |
| **Bus 1 (Display)** | `Wire1` / `TwoWire(1)` | `GPIO47` | `GPIO48` | `SSD1306 OLED` (`0x3C`) |

**Required Firmware Update:**
* Initialize `Wire.begin(5, 6)` for `SHT40` and `SGP41`, and `Wire1.begin(47, 48)` for the `SSD1306` OLED (passing `&Wire` / `&Wire1` into their respective `begin()` calls).

---

## 2. Add a Phase 1 / Phase 2 Atomizer Mode Toggle on `GPIO2`

For Phase 1 (integration deadline), we are using the **COTS Blue 3–4.5V Atomizer Module** on header `J5` (`GPIO2`). Unlike our custom V4 driver (deferred to Phase 2), this module has its own onboard 108 kHz oscillator and expects a **steady DC digital `HIGH`/`LOW`** signal on its `S` pin—not a 108 kHz PWM wave.

**Required Firmware Update:**
* Add a compile-time flag in `config.h`:
  ```cpp
  // 0 = Phase 1 Plan B (COTS Blue Module, steady Digital HIGH/LOW)
  // 1 = Phase 2 Custom V4 Driver (108 kHz LEDC PWM)
  #define USE_CUSTOM_PWM 0
  ```
* **When `USE_CUSTOM_PWM == 0`:**
  * In `setup()`: `pinMode(ATOMIZER_PIN, OUTPUT); digitalWrite(ATOMIZER_PIN, LOW);` (do **not** call `ledcAttach` on `GPIO2`).
  * In mist ON/OFF helpers: use `digitalWrite(ATOMIZER_PIN, HIGH)` to mist and `digitalWrite(ATOMIZER_PIN, LOW)` to stop.
* **When `USE_CUSTOM_PWM == 1`:**
  * Retain the existing `ledcAttach(ATOMIZER_PIN, 108000, 8)` and `ledcWrite(ATOMIZER_PIN, 128 / 0)` logic for Phase 2.

---

## 3. Confirmed Unchanged Pinout & Bench TBD Items

| Subsystem | Pin | Hardware Status | Software Action |
|---|---|---|---|
| **Water Sensor (`XKC-Y23A-NPN`)** | `GPIO1` | Powered from `5VOUT` via `JP31`; open-collector NPN | Keep `INPUT_PULLUP` and `WATER_ACTIVE_LEVEL` tunable in `config.h` pending physical bench polarity check. |
| **Atomizer Control** | `GPIO2` | Connected to Plan B header `J5` (3.3V-safe) | Switch to Digital `HIGH`/`LOW` via `USE_CUSTOM_PWM 0`. |
| **Aspiration Fan (5V)** | `GPIO3` | Low-side switched (`Digital HIGH = ON`) | No change needed. |
| **SGP41 / SHT40** | `GPIO5/6` | `SHT40` used strictly for internal `SGP41` compensation | Keep `SIMULATE_VOC 1` until physical PCB is soldered, then flip to `0`. |

*Note: All REST API contracts (`/telemetry`, `/api/control`), safety timers (`MILD` 5s/25s, `STRONG` 15s/15s, 5s `FAN_POST`, `THERMAL_LIMIT`), and PHP gateway logic remain completely unchanged.*