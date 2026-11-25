# Software Integration Guide: V1 Hardware Test Firmware

**To the Software / Web Application Developer:**
This document explains how the current ESP32-S3 hardware test firmware operates and how your frontend web application can interface with it. 

Currently, this firmware is designed as a **"Read-Only Telemetry Test."** It automatically cycles through hardware states so we can verify the physical components (Fan, Atomizer, Sensors) are working, while broadcasting its status to you via a REST API.

---

## 1. Network Architecture (SoftAP Mode)
For this test phase, the ESP32 does not connect to a local router or the internet. Instead, it acts as its own Wi-Fi router (Access Point).

*   **SSID:** `Fragrance-V1-Debug`
*   **Password:** *(None / Open)*
*   **ESP32 IP Address:** `192.168.4.1`

*To test your web app against the live hardware, your laptop or development machine must be connected to this Wi-Fi network.*

---

## 2. The REST API & CORS
The ESP32 is running a lightweight, non-blocking HTTP web server on Port 80. It currently exposes two endpoints:

### Endpoint A: The Web UI (Root)
*   **Route:** `GET /`
*   **Response:** `text/html`
*   **Purpose:** Serves the embedded HTML/Tailwind dashboard directly from the ESP32's flash memory.

### Endpoint B: The Telemetry API (Your Data Source)
*   **Route:** `GET /telemetry`
*   **Response:** `application/json`
*   **CORS:** Explicitly enabled (`Access-Control-Allow-Origin: *`)
*   **Purpose:** This is the endpoint your web app will fetch. Because CORS is set to wildcard `*`, you can run your frontend web app locally on your machine (e.g., `http://localhost:3000` via React/Next.js) and it will successfully fetch data from the ESP32 without the browser throwing Cross-Origin security errors.

---

## 3. The JSON Data Schema
When you poll `http://192.168.4.1/telemetry`, the ESP32 returns a flat JSON object representing the real-time physical state of the hardware.

**Example Response:**
```json
{
  "water": true,
  "fan": false,
  "mist": true,
  "status": "MISTING (108k)"
}
```

**Data Dictionary:**
*   `water` *(boolean)*: `true` if liquid is detected by the XKC-Y23A-NPN sensor. `false` if the tank is empty.
*   `fan` *(boolean)*: `true` if the 4010 DC aspiration fan is currently receiving 5V power.
*   `mist` *(boolean)*: `true` if the 108kHz Atomizer is actively firing.
*   `status` *(string)*: A human-readable string of the current phase (e.g., "FAN ON", "MISTING", "MIST BLOCKED", "WAITING").

---

## 4. Hardware Behavior (The Non-Blocking State Machine)
To ensure the web server doesn't freeze, the ESP32 avoids using `delay()`. Instead, it uses a `millis()` timer to constantly loop through a 3-phase automated test cycle:

1.  **Phase 0 (Fan Test):** Fan turns ON, Mist stays OFF. *(Lasts 2 seconds)*
2.  **Phase 1 (Atomizer Test):** Fan turns OFF. Mist turns ON. *(Lasts 1.5 seconds)*
    *   *Hardware Interlock:* If `water` is `false` during this phase, the firmware will refuse to turn `mist` to `true`. This protects the piezo disc from melting down when dry.
3.  **Phase 2 (Cooldown):** Everything turns OFF. *(Lasts 4 seconds, then restarts)*

*Note: You should poll the API roughly every 500ms (`setInterval`) to catch these state changes smoothly in your UI.*

---

## 5. Future Roadmap for Final Integration
This firmware is just the stepping stone. For the final thesis integration, we will modify the firmware to support two-way communication:

1.  **Network Change:** We will switch the ESP32 from Access Point mode to **Station Mode (STA)** so it connects to your local home router (or a mobile hotspot), giving it a standard local IP address alongside your web server.
2.  **Command Endpoints:** We will add `POST` routes (e.g., `POST /api/control`) that accept JSON payloads from your web app.
    *   *Example payload you will send to the hardware:* `{"command": "MIST_STRONG", "duration_sec": 10}`
3.  **Full Sensor Integration:** The `/telemetry` endpoint will be expanded to include the environmental data (`temperature`, `humidity`, `voc_index`) from the I2C sensors once the final PCB is assembled.