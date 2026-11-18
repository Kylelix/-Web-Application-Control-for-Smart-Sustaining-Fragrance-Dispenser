/*
 * Smart Fragrance Dispenser - V1 Debugging Board Hardware Test
 * MCU: ESP32-S3 DevKitC
 * 
 * Features:
 * - 128x64 I2C OLED Display feedback
 * - 108kHz Atomizer PWM generation (ESP32 Core v3.0+ API)
 * - Fan Toggle
 * - Water Level Sensor read (NPN / Open-Collector safe)
 * - NEW: Non-blocking State Machine
 * - NEW: Wi-Fi Access Point & Telemetry Web Server (CORS Enabled)
 */

#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <WiFi.h>
#include <WebServer.h>

// --- Pin Definitions (Updated to Schematic image_3c9d25.png) ---
const int ATOMIZER_PIN = 2;       // D1/GPIO2 - Traces to Mist Maker 1
const int FAN_PIN = 3;            // D2/GPIO3 - Traces to 5V Fan
const int WATER_SENSOR_PIN = 1;   // D0/GPIO1 - Traces to Water Level Sensor
const int I2C_SDA = 47;           // GPIO47 - OLED SDA 
const int I2C_SCL = 48;           // GPIO48 - OLED SCL 

// --- OLED Configuration ---
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET    -1 
#define OLED_ADDRESS  0x3C 
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// --- PWM Settings (ESP32 Core v3.0+) ---
const int ATOMIZER_FREQ = 108000; // 108 kHz Resonant Frequency
const int ATOMIZER_RES = 8;       // 8-bit resolution (0-255)
const int DUTY_50 = 128;          // 50% Duty Cycle for Max Flyback
const int DUTY_OFF = 0;

// --- Wi-Fi & Web Server ---
const char* ssid = "Fragrance-V1-Debug";
const char* password = ""; // Open network for easy testing
WebServer server(80);

// --- Telemetry State Variables ---
bool hasWater = false;
bool isFanOn = false;
bool isMisting = false;
String currentStatus = "BOOTING";

// --- Web Interface HTML (Stored in Flash Memory) ---
const char INDEX_HTML[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>V1 Telemetry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #0f172a; color: #f8fafc; font-family: sans-serif; }
        .glass-card { background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255,255,255,0.1); }
        .indicator { transition: all 0.3s ease; }
    </style>
</head>
<body class="min-h-screen p-4 flex flex-col items-center">
    <div class="w-full max-w-2xl mb-6 text-center">
        <h1 class="text-3xl font-extrabold">V1 Telemetry Dashboard</h1>
        <p class="text-emerald-400 font-bold" id="connStatus">Connecting...</p>
    </div>

    <div class="w-full max-w-2xl grid grid-cols-2 gap-4">
        <!-- Main Status -->
        <div class="glass-card col-span-2 p-6 rounded-xl text-center border-l-4 border-blue-500" id="statusCard">
            <p class="text-sm text-slate-400 uppercase tracking-widest">System Phase</p>
            <h2 class="text-3xl font-bold mt-2" id="mainStatus">WAITING</h2>
        </div>

        <!-- Water -->
        <div class="glass-card p-4 rounded-xl text-center">
            <h3 class="text-slate-400 mb-2">Water Reservoir</h3>
            <div id="waterText" class="text-xl font-bold">UNKNOWN</div>
        </div>

        <!-- Fan -->
        <div class="glass-card p-4 rounded-xl text-center">
            <h3 class="text-slate-400 mb-2">Aspiration Fan</h3>
            <div id="fanText" class="text-xl font-bold">UNKNOWN</div>
        </div>

        <!-- Atomizer -->
        <div class="glass-card col-span-2 p-4 rounded-xl text-center flex justify-between items-center">
            <h3 class="text-slate-400">108kHz Atomizer</h3>
            <div id="mistText" class="text-2xl font-bold">UNKNOWN</div>
        </div>
    </div>

    <script>
        async function fetchTelemetry() {
            try {
                // Fetch directly from the ESP32 hosting this page
                const res = await fetch('/telemetry');
                if (res.ok) {
                    const data = await res.json();
                    document.getElementById('connStatus').innerText = "Live Connected";
                    document.getElementById('connStatus').className = "text-emerald-400 font-bold";
                    
                    document.getElementById('mainStatus').innerText = data.status;
                    
                    // Water Updates
                    const wText = document.getElementById('waterText');
                    wText.innerText = data.water ? "DETECTED (OK)" : "EMPTY (LOCKED)";
                    wText.className = data.water ? "text-xl font-bold text-blue-400" : "text-xl font-bold text-red-500";
                    
                    // Fan Updates
                    const fText = document.getElementById('fanText');
                    fText.innerText = data.fan ? "RUNNING" : "OFF";
                    fText.className = data.fan ? "text-xl font-bold text-teal-400" : "text-xl font-bold text-slate-500";
                    
                    // Mist Updates
                    const mText = document.getElementById('mistText');
                    const sCard = document.getElementById('statusCard');
                    if (data.mist) {
                        mText.innerText = "ACTIVE"; mText.className = "text-2xl font-bold text-purple-400";
                        sCard.className = "glass-card col-span-2 p-6 rounded-xl text-center border-l-4 border-purple-500";
                    } else if (!data.water && data.status.includes('BLOCKED')) {
                        mText.innerText = "LOCKED OUT"; mText.className = "text-2xl font-bold text-red-500";
                        sCard.className = "glass-card col-span-2 p-6 rounded-xl text-center border-l-4 border-red-500";
                    } else {
                        mText.innerText = "IDLE"; mText.className = "text-2xl font-bold text-slate-500";
                        sCard.className = "glass-card col-span-2 p-6 rounded-xl text-center border-l-4 border-blue-500";
                    }
                }
            } catch (e) {
                document.getElementById('connStatus').innerText = "Connection Lost!";
                document.getElementById('connStatus').className = "text-red-500 font-bold";
            }
        }
        setInterval(fetchTelemetry, 500);
    </script>
</body>
</html>
)rawliteral";

// --- Non-Blocking Timers ---
unsigned long previousStateMillis = 0;
unsigned long previousOledMillis = 0;
int testPhase = 0; // 0=FAN, 1=MIST, 2=COOLDOWN

// Forward declaration to fix Arduino IDE prototype generation bug
void updateOLED();

void setup() {
  Serial.begin(115200);
  
  // 1. Initialize Hardware Pins
  pinMode(FAN_PIN, OUTPUT);
  digitalWrite(FAN_PIN, LOW); 
  pinMode(WATER_SENSOR_PIN, INPUT_PULLUP);

  // 2. Setup Atomizer PWM
  if (!ledcAttach(ATOMIZER_PIN, ATOMIZER_FREQ, ATOMIZER_RES)) {
    Serial.println("ERROR: Failed to attach PWM to Atomizer Pin!");
  }
  ledcWrite(ATOMIZER_PIN, DUTY_OFF);

  // 3. Initialize I2C & OLED
  Wire.begin(I2C_SDA, I2C_SCL);
  if(!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDRESS)) {
    Serial.println(F("SSD1306 allocation failed!"));
  } else {
    display.clearDisplay();
    display.setTextSize(1);
    display.setTextColor(SSD1306_WHITE);
    display.setCursor(0, 10);
    display.println("V1 Debug Board");
    display.println("Starting AP...");
    display.display();
  }

  // 4. Setup Wi-Fi Access Point
  WiFi.softAP(ssid, password);
  IPAddress IP = WiFi.softAPIP();
  Serial.print("AP IP address: ");
  Serial.println(IP);

  // 5. Setup Web Server Endpoints
  
  // Route for root / web page
  server.on("/", HTTP_GET, []() {
    server.send_P(200, "text/html", INDEX_HTML);
  });

  server.on("/telemetry", HTTP_GET, []() {
    // Add CORS headers so external web apps can read this data
    server.sendHeader("Access-Control-Allow-Origin", "*");
    
    // Construct JSON Response manually to save memory
    String json = "{";
    json += "\"water\":" + String(hasWater ? "true" : "false") + ",";
    json += "\"fan\":" + String(isFanOn ? "true" : "false") + ",";
    json += "\"mist\":" + String(isMisting ? "true" : "false") + ",";
    json += "\"status\":\"" + currentStatus + "\"";
    json += "}";
    
    server.send(200, "application/json", json);
  });

  server.begin();
  Serial.println("HTTP server started");
}

void loop() {
  // 1. Handle incoming web requests (Must be called frequently!)
  server.handleClient();
  
  // 2. Read Sensors
  hasWater = digitalRead(WATER_SENSOR_PIN);
  
  // 3. Non-Blocking State Machine (Replaces delay())
  unsigned long currentMillis = millis();
  
  if (testPhase == 0) { // --- FAN TEST (2 seconds) ---
    currentStatus = "FAN ON";
    isFanOn = true;
    isMisting = false;
    digitalWrite(FAN_PIN, HIGH);
    ledcWrite(ATOMIZER_PIN, DUTY_OFF);
    
    if (currentMillis - previousStateMillis >= 2000) {
      previousStateMillis = currentMillis;
      testPhase = 1; // Move to Mist Test
    }
    
  } else if (testPhase == 1) { // --- MIST TEST (1.5 seconds) ---
    isFanOn = false;
    digitalWrite(FAN_PIN, LOW);
    
    if (hasWater) {
      currentStatus = "MISTING (108k)";
      isMisting = true;
      ledcWrite(ATOMIZER_PIN, DUTY_50);
    } else {
      currentStatus = "MIST BLOCKED";
      isMisting = false;
      ledcWrite(ATOMIZER_PIN, DUTY_OFF);
    }
    
    if (currentMillis - previousStateMillis >= 1500) {
      previousStateMillis = currentMillis;
      testPhase = 2; // Move to Cooldown
    }
    
  } else if (testPhase == 2) { // --- COOLDOWN (4 seconds) ---
    currentStatus = "WAITING (COOLDOWN)";
    isFanOn = false;
    isMisting = false;
    ledcWrite(ATOMIZER_PIN, DUTY_OFF);
    
    if (currentMillis - previousStateMillis >= 4000) {
      previousStateMillis = currentMillis;
      testPhase = 0; // Restart cycle
    }
  }

  // 4. Non-Blocking OLED Update (Refresh only every 200ms to save CPU)
  if (currentMillis - previousOledMillis >= 200) {
    previousOledMillis = currentMillis;
    updateOLED();
  }
}

void updateOLED() {
  display.clearDisplay();
  
  // Header
  display.setCursor(0, 0);
  display.setTextSize(1);
  display.print("IP: ");
  display.println(WiFi.softAPIP());
  
  // Water Status
  display.setCursor(0, 15);
  if (hasWater) {
    display.println("Water: DETECTED (OK)");
  } else {
    display.println("Water: EMPTY (LOCK)");
  }
  
  // Current Action
  display.fillRect(0, 30, 128, 34, SSD1306_BLACK);
  display.setCursor(0, 35);
  display.setTextSize(2);
  
  if (testPhase == 0) display.println("FAN ON");
  else if (testPhase == 1 && hasWater) display.println("MISTING");
  else if (testPhase == 1 && !hasWater) display.println("BLOCKED");
  else display.println("WAITING");

  display.display();
}
