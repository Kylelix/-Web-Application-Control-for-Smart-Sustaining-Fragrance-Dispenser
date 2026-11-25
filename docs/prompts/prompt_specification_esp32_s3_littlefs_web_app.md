# Prompt for Web App Developer / AI Assistant

**Copy and paste the following text to your web developer or AI coding assistant:**

***

**Role:** Act as an expert frontend web developer and UI/UX designer. 

**Task:** Build a modern, responsive, dark-mode web application dashboard for an IoT-Based Smart Fragrance Dispenser. 

**CRITICAL HARDWARE CONSTRAINTS (ESP32-S3 & LittleFS):**
This web application will not be hosted on a standard cloud server (like AWS or Vercel). It will be embedded directly inside an ESP32-S3 microcontroller and served from its internal flash memory using the **LittleFS** file system. Therefore, you must strictly adhere to the following rules:

1. **Static Files Only:** The final output must be pure, static client-side files (`index.html`, `.css`, `.js`). There is no Node.js, PHP, or Python backend to render pages. 
2. **No External CDN Reliance:** While you can use CDNs for initial development, the final production build should ideally bundle critical assets (or be easily compressible) so the dashboard works even if the ESP32 is acting as a localized Access Point without internet access.
3. **REST API Data Fetching:** The UI must be a Single Page Application (SPA) or vanilla JS page that updates dynamically without reloading. It must fetch data from the ESP32 via standard HTTP requests:
   * **Telemetry:** Poll `GET /telemetry` every 500ms-1000ms. It will return a JSON payload with sensor data (Temperature, Humidity, VOC, NOx, Water Level, Fan State, Mist State).
   * **Commands:** Send user inputs (e.g., turning on the fan, setting mist intensity to Mild/Strong) via `POST /api/control` with a JSON payload.
4. **Build for GZIP Compression:** The final export should be as lightweight as possible. If you use a build tool (like Vite, React, or Tailwind CLI), ensure the final `dist` folder can be easily GZIP compressed (`.gz`), as the ESP32 LittleFS will serve compressed files to save flash memory.

**Required UI Features:**
* A hero section showing the current status of the device (Idle, Misting, Cooldown, Locked Out).
* Live data cards for the SHT40 Sensor (Temperature °C, Humidity %RH).
* Live data cards for the SGP41 Sensor (VOC Index, NOx Index).
* A warning indicator if the water reservoir is empty (which locks out the atomizer).
* Control buttons to manually trigger the Fan and select Atomizer Intensity (Mild / Strong / OFF).

Please provide the frontend code prioritizing clean architecture, modern aesthetics, and microcontroller-friendly static file structure.