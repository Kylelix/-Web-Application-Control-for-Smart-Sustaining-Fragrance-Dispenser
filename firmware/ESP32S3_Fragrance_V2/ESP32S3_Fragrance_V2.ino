/*
 * SmartScent Dispenser Firmware v2.0.0
 * Target MCU: ESP32-S3 DevKitC (Arduino-ESP32 Core 3.x)
 *
 * Hardware Mapping (Phase 1 Plan B Carrier PCB + ESP32S3TestCode.ino):
 *   - GPIO2        : Atomizer Control (USE_CUSTOM_PWM=0 -> Digital HIGH/LOW; 1 -> 108 kHz PWM)
 *   - GPIO3        : 5V Aspiration Fan (HIGH = ON, LOW = OFF)
 *   - GPIO1        : XKC-Y23A-NPN Water Level Sensor (INPUT_PULLUP, WATER_ACTIVE_LEVEL = liquid)
 *   - GPIO5 / 6    : I2C Bus 0 (Wire)  -> Sensors SDA=GPIO5, SCL=GPIO6 (SHT40 0x44, SGP41 0x59)
 *   - GPIO47 / 48  : I2C Bus 1 (Wire1) -> Display SDA=GPIO47, SCL=GPIO48 (SSD1306 OLED 0x3C)
 *
 * Scope Notes:
 *   - Serves JSON API only (/telemetry, /api/control) and plain-text info at /.
 *   - SHT40 is read internally at 1 Hz only to compensate SGP41 (never exposed via API/OLED).
 *   - Hardware safety interlocks (empty tank, high VOC, MAX_MIST_MS, THERMAL_LIMIT) are
 *     enforced locally on the microcontroller regardless of network commands.
 */

#include <Arduino.h>
#include <Wire.h>
#include <WiFi.h>
#include <WebServer.h>
#include <Preferences.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <ArduinoJson.h>
#include <math.h>
#include "config.h"

#ifndef USE_CUSTOM_PWM
#define USE_CUSTOM_PWM 0
#endif

#if !SIMULATE_VOC
#include <SensirionI2CSgp41.h>
#include <SensirionI2cSht4x.h>
#ifndef SHT40_I2C_ADDR_44
#define SHT40_I2C_ADDR_44 0x44
#endif
#include <GasIndexAlgorithm.h>
#endif

// ============================================================================
// 1. Hardware Pins & PWM Settings
// ============================================================================
const int ATOMIZER_PIN      = 2;
const int FAN_PIN           = 3;
const int WATER_SENSOR_PIN  = 1;

// Dual Hardware I2C Buses
const int SENSOR_I2C_SDA    = 5;   // Bus 0 (Wire): SHT40 (0x44), SGP41 (0x59)
const int SENSOR_I2C_SCL    = 6;
const int OLED_I2C_SDA      = 47;  // Bus 1 (Wire1): SSD1306 OLED (0x3C)
const int OLED_I2C_SCL      = 48;

#if USE_CUSTOM_PWM == 1
const uint32_t ATOMIZER_FREQ = 108000;
const uint8_t  ATOMIZER_RES  = 8;
const uint8_t  DUTY_50       = 128;
const uint8_t  DUTY_OFF      = 0;
#endif

#define SCREEN_WIDTH  128
#define SCREEN_HEIGHT 64
#define OLED_RESET    -1
#define OLED_ADDRESS  0x3C

// ============================================================================
// 2. Confirmed Timing, Safety & Threshold Constants (Amendment 1)
// ============================================================================
const char* FW_VERSION = "2.0.0";

const unsigned long FAN_PRE_MS             = 0UL;      // TBD (0 s pre-run)
const unsigned long FAN_POST_MS            = 5000UL;   // 5 s post-run overlaps start of cooldown
const unsigned long MILD_MIST_MS           = 5000UL;   // 5 s mist
const unsigned long MILD_COOLDOWN_S        = 25UL;     // 25 s cooldown
const unsigned long STRONG_MIST_MS         = 15000UL;  // 15 s mist
const unsigned long STRONG_COOLDOWN_S      = 15UL;     // 15 s cooldown
const unsigned long MAX_MIST_MS            = 20000UL;  // Hard burst safety cap (20 s)
const unsigned long SETTLE_S               = 30UL;     // Min wait in SENSOR mode after spray (TBD)
const uint32_t      MAX_ON_PER_10MIN_S     = 300UL;    // Piezo thermal protection (TBD)
const unsigned long BOOT_LOCKOUT_MS        = 3000UL;   // No auto-spray during first 3 s

const int VOC_LOW        = 100;
const int VOC_HIGH       = 180;
const int VOC_HIGH_CLEAR = 170;

const unsigned long WATER_EMPTY_DEBOUNCE_MS = 300UL;
const unsigned long WATER_FULL_DEBOUNCE_MS  = 2000UL;

const unsigned long STA_CONNECT_TIMEOUT_MS  = 15000UL;
const unsigned long STA_RECONNECT_RETRY_MS  = 15000UL;

// ============================================================================
// 3. Discrete State Constants (uint8_t prevents Arduino prototype issues)
// ============================================================================
const uint8_t MODE_MANUAL = 0;
const uint8_t MODE_TIMED  = 1;
const uint8_t MODE_SENSOR = 2;
const uint8_t MODE_AUTO   = 3;

const uint8_t PROFILE_MILD   = 0;
const uint8_t PROFILE_STRONG = 1;

const uint8_t CYCLE_IDLE     = 0;
const uint8_t CYCLE_FAN_PRE  = 1;
const uint8_t CYCLE_MIST     = 2;
const uint8_t CYCLE_FAN_POST = 3;

// ============================================================================
// 4. Global Objects & State
// ============================================================================
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire1, OLED_RESET);
WebServer server(80);
Preferences prefs;

#if !SIMULATE_VOC
SensirionI2CSgp41 sgp41;
SensirionI2cSht4x sht4x;
GasIndexAlgorithmParams vocAlgoParams;
uint16_t sgpConditioningRemainS = 10;
uint32_t sgpSampleSeconds       = 0;
bool     sgpDetected            = false;
bool     sgpLastReadFailed      = false;
#endif

// Persisted configuration
bool     powerOn     = true;
uint8_t  opMode      = MODE_SENSOR;
uint8_t  doseProfile = PROFILE_MILD;
uint16_t intervalMin = 60;
int32_t  vocBaseline = 100;

// Hardware & Safety State
bool hasWater      = false;
bool highVocLock   = false;
bool oledReady     = false;
bool sht40Detected = false;

bool isFanOn   = false;
bool isMisting = false;

// Spray Cycle State
uint8_t       cycleStage       = CYCLE_IDLE;
unsigned long stageStartMs     = 0;
unsigned long activeMistTargetMs = MILD_MIST_MS;

// Cooldown State (counted from mist stop; overlaps FAN_POST)
bool          cooldownActive   = false;
unsigned long cooldownStartMs  = 0;
unsigned long cooldownTotalMs  = 0;
unsigned long lastSprayEndMs   = 0;
bool          hasSprayedOnce   = false;

// Timed Mode Scheduler
unsigned long timedStartMs     = 0;

// Manual Fan Test State
bool          manualFanActive     = false;
unsigned long manualFanStartMs    = 0;
unsigned long manualFanDurationMs = 5000;

// Water Sensor Debounce
bool          waterDebouncing     = false;
bool          waterPendingLevel   = false;
unsigned long waterDebounceStartMs = 0;

// Rolling 10-minute Piezo Thermal Guard (60 buckets x 10 s = 600 s)
const int     THERMAL_BUCKETS        = 60;
const unsigned long THERMAL_STEP_MS  = 10000UL;
uint16_t      mistMsBuckets[THERMAL_BUCKETS];
int           currentThermalBucket   = 0;
unsigned long lastThermalStepMs      = 0;
unsigned long lastLoopMs             = 0;

// Sensor 1 Hz State
unsigned long lastSensorTickMs = 0;
bool          vocReady         = false;
int32_t       vocIndex         = 105;
float         simVocSurge      = 0.0f;
float         simVocNoiseBase  = 104.0f;

// Wi-Fi Management
bool          softApActive       = false;
bool          staConnecting      = false;
unsigned long staAttemptStartMs  = 0;
unsigned long lastStaRetryMs     = 0;
unsigned long lastOledMs         = 0;

// ============================================================================
// 5. Helper Conversion Functions
// ============================================================================
const char* modeToStr(uint8_t m) {
  switch (m) {
    case MODE_MANUAL: return "MANUAL";
    case MODE_TIMED:  return "TIMED";
    case MODE_SENSOR: return "SENSOR";
    case MODE_AUTO:   return "AUTO";
    default:          return "SENSOR";
  }
}

const char* profileToStr(uint8_t p) {
  return (p == PROFILE_STRONG) ? "STRONG" : "MILD";
}

unsigned long getMistDurationMs() {
  unsigned long d = (doseProfile == PROFILE_STRONG) ? STRONG_MIST_MS : MILD_MIST_MS;
  return (d > MAX_MIST_MS) ? MAX_MIST_MS : d;
}

unsigned long getCooldownDurationMs() {
  return (doseProfile == PROFILE_STRONG) ? (STRONG_COOLDOWN_S * 1000UL) : (MILD_COOLDOWN_S * 1000UL);
}

uint32_t getCooldownRemainingS(unsigned long now) {
  if (!cooldownActive) return 0;
  unsigned long elapsed = now - cooldownStartMs;
  if (elapsed >= cooldownTotalMs) return 0;
  return (uint32_t)((cooldownTotalMs - elapsed + 999UL) / 1000UL);
}

uint32_t getNextTimedS(unsigned long now) {
  bool usesTimer = (opMode == MODE_TIMED) || (opMode == MODE_AUTO && !vocReady);
  if (!powerOn || !usesTimer) return 0;
  unsigned long intervalMs = (unsigned long)intervalMin * 60000UL;
  unsigned long elapsed = now - timedStartMs;
  if (elapsed >= intervalMs) return 0;
  return (uint32_t)((intervalMs - elapsed + 999UL) / 1000UL);
}

uint32_t getRollingMistMs() {
  uint32_t total = 0;
  for (int i = 0; i < THERMAL_BUCKETS; i++) {
    total += mistMsBuckets[i];
  }
  return total;
}

const char* getVocSourceStr() {
#if SIMULATE_VOC
  return "SIMULATED";
#else
  return sgpDetected ? "SGP41" : "NONE";
#endif
}

const char* getSgpStatusStr() {
#if SIMULATE_VOC
  return "SIMULATED";
#else
  if (!sgpDetected || sgpLastReadFailed) return "FAIL";
  return "OK";
#endif
}

const char* getDerivedStateStr() {
  if (!powerOn) return "OFF";
  if (!hasWater || highVocLock) return "LOCKED_OUT";
  if (cycleStage == CYCLE_FAN_PRE || cycleStage == CYCLE_MIST) return "MISTING";
  if (cooldownActive) return "COOLDOWN";
  return "IDLE";
}

const char* getPhaseStr() {
  switch (cycleStage) {
    case CYCLE_FAN_PRE:  return "FAN_PRE";
    case CYCLE_MIST:     return "MIST";
    case CYCLE_FAN_POST: return "FAN_POST";
    default:             return "NONE";
  }
}

void writeAtomizer(bool on) {
  if (on && powerOn && hasWater && !highVocLock) {
#if USE_CUSTOM_PWM == 1
    ledcWrite(ATOMIZER_PIN, DUTY_50);
#else
    digitalWrite(ATOMIZER_PIN, HIGH);
#endif
    isMisting = true;
  } else {
#if USE_CUSTOM_PWM == 1
    ledcWrite(ATOMIZER_PIN, DUTY_OFF);
#else
    digitalWrite(ATOMIZER_PIN, LOW);
#endif
    isMisting = false;
  }
}

void writeFan(bool on) {
  if (on && powerOn) {
    digitalWrite(FAN_PIN, HIGH);
    isFanOn = true;
  } else {
    digitalWrite(FAN_PIN, LOW);
    isFanOn = false;
  }
}

void savePreferences() {
  prefs.begin("smartscent", false);
  prefs.putBool("power", powerOn);
  prefs.putUChar("mode", opMode);
  prefs.putUChar("profile", doseProfile);
  prefs.putUShort("interval", intervalMin);
  prefs.putInt("baseline", vocBaseline);
  prefs.end();
}

void loadPreferences() {
  prefs.begin("smartscent", true);
  powerOn     = prefs.getBool("power", true);
  opMode      = prefs.getUChar("mode", MODE_SENSOR);
  doseProfile = prefs.getUChar("profile", PROFILE_MILD);
  intervalMin = prefs.getUShort("interval", 60);
  vocBaseline = prefs.getInt("baseline", 100);
  prefs.end();

  if (opMode > MODE_AUTO) opMode = MODE_SENSOR;
  if (doseProfile > PROFILE_STRONG) doseProfile = PROFILE_MILD;
  if (intervalMin != 30 && intervalMin != 60 && intervalMin != 120) intervalMin = 60;
}

// ============================================================================
// 6. Safety Interlocks, Water Debounce & Thermal Accrual
// ============================================================================
void updateWaterDebounce(unsigned long now) {
  bool raw = (digitalRead(WATER_SENSOR_PIN) == WATER_ACTIVE_LEVEL);
  if (raw == hasWater) {
    waterDebouncing = false;
    return;
  }
  if (!waterDebouncing || waterPendingLevel != raw) {
    waterDebouncing = true;
    waterPendingLevel = raw;
    waterDebounceStartMs = now;
    return;
  }
  unsigned long requiredMs = raw ? WATER_FULL_DEBOUNCE_MS : WATER_EMPTY_DEBOUNCE_MS;
  if (now - waterDebounceStartMs >= requiredMs) {
    hasWater = raw;
    waterDebouncing = false;
    Serial.printf("[WATER] Reservoir is now %s\n", hasWater ? "OK" : "EMPTY");
  }
}

void updateThermalBuckets(unsigned long now) {
  unsigned long dt = now - lastLoopMs;
  lastLoopMs = now;
  if (isMisting && dt > 0) {
    uint32_t updated = (uint32_t)mistMsBuckets[currentThermalBucket] + (uint32_t)dt;
    mistMsBuckets[currentThermalBucket] = (updated > 60000UL) ? 60000 : (uint16_t)updated;
  }
  while (now - lastThermalStepMs >= THERMAL_STEP_MS) {
    lastThermalStepMs += THERMAL_STEP_MS;
    currentThermalBucket = (currentThermalBucket + 1) % THERMAL_BUCKETS;
    mistMsBuckets[currentThermalBucket] = 0;
  }
}

void finishMistStartCooldown(unsigned long now) {
  writeAtomizer(false);
  hasSprayedOnce = true;
  lastSprayEndMs = now;
  cooldownActive = true;
  cooldownStartMs = now;
  cooldownTotalMs = getCooldownDurationMs();

  if (powerOn && FAN_POST_MS > 0) {
    cycleStage = CYCLE_FAN_POST;
    stageStartMs = now;
    writeFan(true);
  } else {
    cycleStage = CYCLE_IDLE;
    writeFan(false);
  }
}

void enforceSafetyInterlocks(unsigned long now) {
  // Always keep atomizer off if power off, tank empty, or high VOC lock active
  if (!powerOn || !hasWater || highVocLock) {
    if (isMisting || cycleStage == CYCLE_MIST) {
      finishMistStartCooldown(now);
    }
    if (!powerOn) {
      writeAtomizer(false);
      writeFan(false);
      manualFanActive = false;
      cycleStage = CYCLE_IDLE;
      cooldownActive = false;
    } else if (cycleStage == CYCLE_FAN_PRE) {
      writeFan(false);
      cycleStage = CYCLE_IDLE;
    }
  }

  // Hard cap: atomizer must never run longer than MAX_MIST_MS in a single burst
  if (cycleStage == CYCLE_MIST && (now - stageStartMs >= MAX_MIST_MS)) {
    Serial.println("[SAFETY] MAX_MIST_MS cap reached; forcing atomizer OFF.");
    finishMistStartCooldown(now);
  }
}

// Evaluates safety & cooldown before initiating a spray. Returns "" if accepted or error code.
const char* tryStartSpray(unsigned long now, const char* trigger) {
  if (now < BOOT_LOCKOUT_MS) return "BUSY";
  if (!powerOn)              return "POWER_OFF";
  if (!hasWater)             return "TANK_EMPTY";
  if (highVocLock)           return "HIGH_VOC_LOCK";
  if (cycleStage == CYCLE_FAN_PRE || cycleStage == CYCLE_MIST) return "BUSY";
  if (cooldownActive && (now - cooldownStartMs < cooldownTotalMs)) {
    return "COOLDOWN_ACTIVE";
  }

  unsigned long plannedMist = getMistDurationMs();
  if ((getRollingMistMs() + plannedMist) > (MAX_ON_PER_10MIN_S * 1000UL)) {
    return "THERMAL_LIMIT";
  }

  // Stop manual fan test cleanly if one was running
  manualFanActive = false;
  activeMistTargetMs = plannedMist;
  timedStartMs = now; // Reset interval timer on spray

  if (FAN_PRE_MS > 0) {
    cycleStage = CYCLE_FAN_PRE;
    stageStartMs = now;
    writeFan(true);
    writeAtomizer(false);
  } else {
    cycleStage = CYCLE_MIST;
    stageStartMs = now;
    writeFan(true);
    writeAtomizer(true);
  }

  Serial.printf("[SPRAY] Started (%s, profile=%s, mist=%lums)\n",
                trigger, profileToStr(doseProfile), activeMistTargetMs);
  return "";
}

// ============================================================================
// 7. Spray Cycle, Modes & 1 Hz Sensors
// ============================================================================
void updateSprayMachine(unsigned long now) {
  // Update cooldown completion
  if (cooldownActive && (now - cooldownStartMs >= cooldownTotalMs)) {
    cooldownActive = false;
  }

  // Update manual fan timer
  if (manualFanActive) {
    if (!powerOn || (now - manualFanStartMs >= manualFanDurationMs)) {
      manualFanActive = false;
      if (cycleStage == CYCLE_IDLE) {
        writeFan(false);
      }
    }
  }

  switch (cycleStage) {
    case CYCLE_FAN_PRE:
      if (now - stageStartMs >= FAN_PRE_MS) {
        cycleStage = CYCLE_MIST;
        stageStartMs = now;
        writeFan(true);
        writeAtomizer(true);
      }
      break;

    case CYCLE_MIST:
      if (now - stageStartMs >= activeMistTargetMs) {
        finishMistStartCooldown(now);
      }
      break;

    case CYCLE_FAN_POST:
      if (now - stageStartMs >= FAN_POST_MS) {
        cycleStage = CYCLE_IDLE;
        if (!manualFanActive) {
          writeFan(false);
        }
      }
      break;

    case CYCLE_IDLE:
    default:
      if (!manualFanActive && isFanOn) {
        writeFan(false);
      }
      break;
  }
}

void updateTimedMode(unsigned long now) {
  if (!powerOn || now < BOOT_LOCKOUT_MS) return;
  bool shouldRunTimed = (opMode == MODE_TIMED) || (opMode == MODE_AUTO && !vocReady);
  if (!shouldRunTimed) return;

  unsigned long intervalMs = (unsigned long)intervalMin * 60000UL;
  if (now - timedStartMs >= intervalMs) {
    const char* err = tryStartSpray(now, "TIMED");
    if (err[0] == '\0') {
      timedStartMs = now;
    }
  }
}

bool probeI2CAddress(uint8_t addr) {
  Wire.beginTransmission(addr);
  return (Wire.endTransmission() == 0);
}

void tickSensors1Hz(unsigned long now) {
  sht40Detected = probeI2CAddress(0x44);

#if SIMULATE_VOC
  // Simulated 1 Hz VOC engine: idle ~104, surges while misting, decays over ~90 s
  if (isMisting) {
    simVocSurge = min(65.0f, simVocSurge + 3.2f);
  } else {
    simVocSurge *= 0.972f; // ~90s decay back to baseline
    if (simVocSurge < 0.2f) simVocSurge = 0.0f;
  }
  int jitter = random(-2, 3);
  simVocNoiseBase += (random(-10, 11) * 0.05f);
  if (simVocNoiseBase < 96.0f)  simVocNoiseBase = 96.0f;
  if (simVocNoiseBase > 114.0f) simVocNoiseBase = 114.0f;

  vocIndex = (int32_t)round(simVocNoiseBase + simVocSurge + jitter);
  if (vocIndex < 1)   vocIndex = 1;
  if (vocIndex > 500) vocIndex = 500;

  if (now >= 3000UL) {
    vocReady = true;
  }
#else
  // Real SHT40 + SGP41 1 Hz acquisition
  uint16_t compRh = 0x8000; // Default 50% RH
  uint16_t compT  = 0x6666; // Default 25 C
  if (sht40Detected) {
    float tempC = 25.0f;
    float rhPct = 50.0f;
    uint16_t shtErr = sht4x.measureHighPrecision(tempC, rhPct);
    if (shtErr == 0) {
      float tTick = ((tempC + 45.0f) * 65535.0f) / 175.0f;
      float rTick = (rhPct * 65535.0f) / 100.0f;
      compT  = (uint16_t)constrain((long)round(tTick), 0L, 65535L);
      compRh = (uint16_t)constrain((long)round(rTick), 0L, 65535L);
    }
  }

  sgpDetected = probeI2CAddress(0x59);
  if (sgpDetected) {
    uint16_t srawVoc = 0;
    uint16_t srawNox = 0;
    uint16_t err = 0;
    if (sgpConditioningRemainS > 0) {
      err = sgp41.executeConditioning(compRh, compT, srawVoc);
      sgpConditioningRemainS--;
      sgpLastReadFailed = (err != 0);
      vocReady = false;
    } else {
      err = sgp41.measureRawSignals(compRh, compT, srawVoc, srawNox);
      sgpLastReadFailed = (err != 0);
      if (err == 0) {
        int32_t outIndex = 0;
        GasIndexAlgorithm_process(&vocAlgoParams, (int32_t)srawVoc, &outIndex);
        sgpSampleSeconds++;
        if (sgpSampleSeconds >= 45 && outIndex > 0) {
          vocIndex = outIndex;
          vocReady = true;
        }
      }
    }
  } else {
    vocReady = false;
  }
#endif

  // Evaluate High VOC safety lock with hysteresis across all modes
  if (vocReady) {
    if (vocIndex >= VOC_HIGH) {
      highVocLock = true;
    } else if (vocIndex < VOC_HIGH_CLEAR) {
      highVocLock = false;
    }
  }

  // Evaluate SENSOR / AUTO automatic spraying once per second
  if (powerOn && now >= BOOT_LOCKOUT_MS && vocReady) {
    bool isSensorActive = (opMode == MODE_SENSOR) || (opMode == MODE_AUTO);
    if (isSensorActive && vocIndex < VOC_LOW && hasWater && !highVocLock) {
      bool settled = !hasSprayedOnce || ((now - lastSprayEndMs) >= (SETTLE_S * 1000UL));
      if (settled && !cooldownActive && cycleStage == CYCLE_IDLE) {
        tryStartSpray(now, "SENSOR");
      }
    }
  }
}



// ============================================================================
// 8. JSON Telemetry Builder & HTTP Endpoints
// ============================================================================
String buildTelemetryJson() {
  unsigned long now = millis();
  JsonDocument doc;

  doc["device_id"]    = DEVICE_ID;
  doc["fw"]           = FW_VERSION;
  doc["uptime_s"]     = (uint32_t)(now / 1000UL);
  doc["power"]        = powerOn;
  doc["mode"]         = modeToStr(opMode);
  doc["profile"]      = profileToStr(doseProfile);
  doc["interval_min"] = intervalMin;

  const char* stateStr = getDerivedStateStr();
  doc["state"]  = stateStr;
  doc["phase"]  = getPhaseStr();
  doc["status"] = stateStr; // Backward compatibility key

  doc["water"] = hasWater;
  doc["fan"]   = isFanOn;
  doc["mist"]  = isMisting;

  if (vocReady) {
    doc["voc_index"] = vocIndex;
  } else {
    doc["voc_index"] = nullptr;
  }
  doc["voc_ready"]  = vocReady;
  doc["voc_source"] = getVocSourceStr();
  doc["baseline"]   = vocBaseline;

  JsonObject thresholds = doc["thresholds"].to<JsonObject>();
  thresholds["low"]  = VOC_LOW;
  thresholds["high"] = VOC_HIGH;

  JsonObject lockObj = doc["lock"].to<JsonObject>();
  lockObj["empty_tank"] = !hasWater;
  lockObj["high_voc"]   = highVocLock;

  doc["cooldown_remaining_s"] = getCooldownRemainingS(now);
  doc["next_timed_s"]         = getNextTimedS(now);

  JsonObject profiles = doc["profiles"].to<JsonObject>();
  JsonObject mildObj = profiles["MILD"].to<JsonObject>();
  mildObj["mist_s"]     = MILD_MIST_MS / 1000UL;
  mildObj["cooldown_s"] = MILD_COOLDOWN_S;
  JsonObject strongObj = profiles["STRONG"].to<JsonObject>();
  strongObj["mist_s"]     = STRONG_MIST_MS / 1000UL;
  strongObj["cooldown_s"] = STRONG_COOLDOWN_S;

  JsonObject sensorsObj = doc["sensors"].to<JsonObject>();
  sensorsObj["sgp41"] = getSgpStatusStr();
  sensorsObj["sht40"] = sht40Detected ? "OK" : "MISSING";

  doc["rssi"] = (WiFi.status() == WL_CONNECTED) ? WiFi.RSSI() : 0;

  String out;
  serializeJson(doc, out);
  return out;
}

void sendErrorJson(int httpCode, const char* code, uint32_t remainingS) {
  JsonDocument doc;
  doc["ok"] = false;
  doc["error"] = code;
  if (strcmp(code, "COOLDOWN_ACTIVE") == 0) {
    doc["remaining_s"] = remainingS;
  }
  String out;
  serializeJson(doc, out);
  server.send(httpCode, "application/json", out);
}

void handleRoot() {
  String msg = String(DEVICE_ID) + " API v2: /telemetry, /api/control";
  server.send(200, "text/plain", msg);
}

void handleTelemetry() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", buildTelemetryJson());
}

void handleControlOptions() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.sendHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
  server.sendHeader("Access-Control-Allow-Headers", "Content-Type, X-Api-Key");
  server.send(204);
}

void handleControlPost() {
  server.sendHeader("Access-Control-Allow-Origin", "*");

  if (!server.hasHeader("X-Api-Key") || server.header("X-Api-Key") != String(API_KEY)) {
    sendErrorJson(401, "UNAUTHORIZED", 0);
    return;
  }

  String body = server.arg("plain");
  if (body.length() == 0 || body.length() > 512) {
    sendErrorJson(400, "INVALID_VALUE", 0);
    return;
  }

  JsonDocument req;
  DeserializationError err = deserializeJson(req, body);
  if (err || !req["command"].is<const char*>()) {
    sendErrorJson(400, "INVALID_COMMAND", 0);
    return;
  }

  const char* cmd = req["command"];
  unsigned long now = millis();

  if (strcmp(cmd, "POWER") == 0) {
    if (!req["value"].is<bool>()) {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    powerOn = req["value"].as<bool>();
    if (!powerOn) {
      writeAtomizer(false);
      writeFan(false);
      manualFanActive = false;
      cycleStage = CYCLE_IDLE;
      cooldownActive = false;
    } else {
      timedStartMs = now;
    }
    savePreferences();
  } else if (strcmp(cmd, "SET_MODE") == 0) {
    if (!req["mode"].is<const char*>()) {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    const char* m = req["mode"];
    if      (strcmp(m, "MANUAL") == 0) opMode = MODE_MANUAL;
    else if (strcmp(m, "TIMED")  == 0) opMode = MODE_TIMED;
    else if (strcmp(m, "SENSOR") == 0) opMode = MODE_SENSOR;
    else if (strcmp(m, "AUTO")   == 0) opMode = MODE_AUTO;
    else {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    timedStartMs = now;
    savePreferences();
  } else if (strcmp(cmd, "SET_PROFILE") == 0) {
    if (!req["profile"].is<const char*>()) {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    const char* p = req["profile"];
    if      (strcmp(p, "MILD")   == 0) doseProfile = PROFILE_MILD;
    else if (strcmp(p, "STRONG") == 0) doseProfile = PROFILE_STRONG;
    else {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    savePreferences();
  } else if (strcmp(cmd, "SET_INTERVAL") == 0) {
    if (!req["minutes"].is<int>()) {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    int mins = req["minutes"].as<int>();
    if (mins != 30 && mins != 60 && mins != 120) {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    intervalMin = (uint16_t)mins;
    timedStartMs = now;
    savePreferences();
  } else if (strcmp(cmd, "SPRAY_NOW") == 0) {
    const char* sprayErr = tryStartSpray(now, "MANUAL");
    if (sprayErr[0] != '\0') {
      uint32_t rem = (strcmp(sprayErr, "COOLDOWN_ACTIVE") == 0) ? getCooldownRemainingS(now) : 0;
      sendErrorJson(409, sprayErr, rem);
      return;
    }
  } else if (strcmp(cmd, "FAN") == 0) {
    if (!req["value"].is<bool>()) {
      sendErrorJson(400, "INVALID_VALUE", 0);
      return;
    }
    bool turnOn = req["value"].as<bool>();
    if (!powerOn) {
      sendErrorJson(409, "POWER_OFF", 0);
      return;
    }
    if (cycleStage == CYCLE_FAN_PRE || cycleStage == CYCLE_MIST || cycleStage == CYCLE_FAN_POST) {
      sendErrorJson(409, "BUSY", 0);
      return;
    }
    if (turnOn) {
      int durS = req["duration_s"].is<int>() ? req["duration_s"].as<int>() : 5;
      if (durS < 1 || durS > 10) {
        sendErrorJson(400, "INVALID_VALUE", 0);
        return;
      }
      manualFanActive = true;
      manualFanStartMs = now;
      manualFanDurationMs = (unsigned long)durS * 1000UL;
      writeFan(true);
    } else {
      manualFanActive = false;
      writeFan(false);
    }
  } else if (strcmp(cmd, "SET_BASELINE") == 0) {
    if (!vocReady) {
      sendErrorJson(409, "BUSY", 0);
      return;
    }
    vocBaseline = vocIndex;
    savePreferences();
  } else {
    sendErrorJson(400, "INVALID_COMMAND", 0);
    return;
  }

  // Accepted response embeds full telemetry under "state"
  String res = "{\"ok\":true,\"result\":\"ACCEPTED\",\"state\":" + buildTelemetryJson() + "}";
  server.send(200, "application/json", res);
}

// ============================================================================
// 9. Wi-Fi (STA + 15s SoftAP Fallback) & OLED Display
// ============================================================================
void startSoftAP() {
  if (softApActive) return;
  WiFi.mode(WIFI_AP_STA);
  String apSsid = String("Fragrance-") + DEVICE_ID;
  if (strlen(SOFTAP_PASS) > 0) {
    WiFi.softAP(apSsid.c_str(), SOFTAP_PASS);
  } else {
    WiFi.softAP(apSsid.c_str());
  }
  softApActive = true;
  Serial.print("[WIFI] SoftAP fallback active: ");
  Serial.print(apSsid);
  Serial.print(" IP=");
  Serial.println(WiFi.softAPIP());
}

void initWifi() {
  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);

  IPAddress ip(STATIC_IP_BYTES);
  IPAddress gw(STATIC_GW_BYTES);
  IPAddress sn(STATIC_SN_BYTES);
  IPAddress dns(STATIC_DNS_BYTES);

  if (!WiFi.config(ip, gw, sn, dns)) {
    Serial.println("[WIFI] Static IP config failed");
  }

  WiFi.begin(WIFI_STA_SSID, WIFI_STA_PASS);
  staConnecting = true;
  staAttemptStartMs = millis();

  Serial.print("[WIFI] Connecting STA to ");
  Serial.println(WIFI_STA_SSID);

  while (WiFi.status() != WL_CONNECTED && (millis() - staAttemptStartMs < STA_CONNECT_TIMEOUT_MS)) {
    delay(250);
  }

  if (WiFi.status() == WL_CONNECTED) {
    staConnecting = false;
    Serial.print("[WIFI] STA Connected! IP=");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("[WIFI] STA timeout after 15 s.");
#if USE_SOFTAP_FALLBACK
    startSoftAP();
#endif
  }
}

void maintainWifi(unsigned long now) {
  if (WiFi.status() == WL_CONNECTED) {
    if (softApActive) {
      // Keep running or disable AP once STA recovers
      WiFi.softAPdisconnect(true);
      softApActive = false;
      Serial.print("[WIFI] STA recovered! IP=");
      Serial.println(WiFi.localIP());
    }
    return;
  }

  if (now - lastStaRetryMs >= STA_RECONNECT_RETRY_MS) {
    lastStaRetryMs = now;
    Serial.println("[WIFI] Retrying STA connection...");
    WiFi.disconnect(false);
    WiFi.begin(WIFI_STA_SSID, WIFI_STA_PASS);
  }

#if USE_SOFTAP_FALLBACK
  if (!softApActive && (now - staAttemptStartMs >= STA_CONNECT_TIMEOUT_MS)) {
    startSoftAP();
  }
#endif
}

void updateOLED(unsigned long now) {
  if (!oledReady) return;
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);

  display.setCursor(0, 0);
  display.print(DEVICE_ID);
  display.print(" | ");
  display.println(modeToStr(opMode));

  display.setCursor(0, 11);
  if (WiFi.status() == WL_CONNECTED) {
    display.print("STA: ");
    display.println(WiFi.localIP());
  } else if (softApActive) {
    display.print("AP: ");
    display.println(WiFi.softAPIP());
  } else {
    display.println("WiFi: Connecting...");
  }

  display.setCursor(0, 23);
  display.print("Tank: ");
  display.println(hasWater ? "OK" : "EMPTY (LOCK)");

  display.setCursor(0, 34);
  display.print("VOC: ");
  if (vocReady) {
    display.print(vocIndex);
  } else {
    display.print("WARMUP");
  }
#if SIMULATE_VOC
  display.println(" (SIM)");
#else
  display.println(highVocLock ? " LOCK" : "");
#endif

  display.setCursor(0, 48);
  display.setTextSize(2);
  display.println(getDerivedStateStr());

  display.display();
  (void)now;
}

// ============================================================================
// 10. Arduino Setup & Main Loop
// ============================================================================
void setup() {
  // Force hardware outputs OFF before anything else
  pinMode(FAN_PIN, OUTPUT);
  digitalWrite(FAN_PIN, LOW);
#if USE_CUSTOM_PWM == 1
  if (!ledcAttach(ATOMIZER_PIN, ATOMIZER_FREQ, ATOMIZER_RES)) {
    Serial.println("ERROR: Failed to attach PWM to Atomizer Pin!");
  }
  ledcWrite(ATOMIZER_PIN, DUTY_OFF);
#else
  pinMode(ATOMIZER_PIN, OUTPUT);
  digitalWrite(ATOMIZER_PIN, LOW);
#endif

  pinMode(WATER_SENSOR_PIN, INPUT_PULLUP);
  hasWater = (digitalRead(WATER_SENSOR_PIN) == WATER_ACTIVE_LEVEL);

  Serial.begin(115200);
  Serial.printf("\nSmartScent Firmware v%s starting (%s)...\n", FW_VERSION, DEVICE_ID);

  loadPreferences();

  // Initialize Dual Hardware I2C Buses
  Wire.begin(SENSOR_I2C_SDA, SENSOR_I2C_SCL);   // Bus 0: SHT40 (0x44) & SGP41 (0x59)
  Wire1.begin(OLED_I2C_SDA, OLED_I2C_SCL);      // Bus 1: SSD1306 OLED (0x3C)
  if (display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDRESS, true, false)) {
    oledReady = true;
    display.clearDisplay();
    display.setTextSize(1);
    display.setTextColor(SSD1306_WHITE);
    display.setCursor(0, 12);
    display.println(DEVICE_ID);
    display.println("Connecting WiFi...");
    display.display();
  }

#if !SIMULATE_VOC
  sht4x.begin(Wire, SHT40_I2C_ADDR_44);
  sgp41.begin(Wire);
  GasIndexAlgorithm_init(&vocAlgoParams, GasIndexAlgorithm_ALGORITHM_TYPE_VOC);
#endif

  initWifi();

  server.on("/", HTTP_GET, handleRoot);
  server.on("/telemetry", HTTP_GET, handleTelemetry);
  server.on("/api/control", HTTP_OPTIONS, handleControlOptions);
  server.on("/api/control", HTTP_POST, handleControlPost);

  const char* headerKeys[] = { "X-Api-Key" };
  server.collectHeaders(headerKeys, 1);
  server.begin();
  Serial.println("[HTTP] Server started on port 80");

  unsigned long bootMs = millis();
  lastLoopMs       = bootMs;
  lastThermalStepMs = bootMs;
  lastSensorTickMs = bootMs;
  timedStartMs     = bootMs;
}

void loop() {
  unsigned long now = millis();

  // 1. Update rolling thermal accumulator & water sensor debounce
  updateThermalBuckets(now);
  updateWaterDebounce(now);

  // 2. Enforce dry-burn & safety interlocks BEFORE any other logic
  enforceSafetyInterlocks(now);

  // 3. Process HTTP clients promptly
  server.handleClient();

  // 4. Advance non-blocking spray & timed state machines
  updateSprayMachine(now);
  updateTimedMode(now);

  // 5. Exact 1 Hz environmental / VOC acquisition tick
  if (now - lastSensorTickMs >= 1000UL) {
    lastSensorTickMs += 1000UL;
    if (now - lastSensorTickMs > 3000UL) lastSensorTickMs = now;
    tickSensors1Hz(now);
  }

  // 6. Keep Wi-Fi connected
  maintainWifi(now);

  // 7. Refresh OLED at 5 Hz (every 200 ms)
  if (now - lastOledMs >= 200UL) {
    lastOledMs = now;
    updateOLED(now);
  }
}