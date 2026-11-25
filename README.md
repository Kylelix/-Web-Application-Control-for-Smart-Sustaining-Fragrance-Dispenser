# SmartScent Dashboard v3

IoT-Based Smart Sustaining Fragrance Dispenser with VOC Sensor and Web Application Control for Indoor Environments (`Room 101 / SFD-01` & `Room 102 / SFD-02`).

---

## Project Structure

### Web Runtime (Document Root)
- `index.php`, `script.js`, `styles.css` — Authenticated live monitoring & control dashboard
- `login.php`, `login.css`, `logout.php` — QR guest room & Admin login
- `admin_rooms.php`, `admin_devices.php` — Front-desk Check-In/Out & Device Host / Sensor Diagnostics consoles
- `config.php`, `db_connection.php`, `auth_helpers.php`, `device_gateway.php` — SQL Server session, RBAC/CSRF, cURL gateway & `flock` cache
- `api/` — JSON endpoints (`telemetry.php`, `control.php`, `logs.php`, `export_csv.php`)
- `assets/` — Self-hosted offline CSS fonts & icons (`inter.css`, `fontawesome.css`)
- `index.html`, `login.html` — Redirect stubs to `.php`

### Organized Subfolders
- [`docs/`](docs/) — Technical & setup documentation:
  - [`docs/API_CONTRACT.md`](docs/API_CONTRACT.md)
  - [`docs/FIRMWARE_NOTES.md`](docs/FIRMWARE_NOTES.md)
  - [`docs/README_INTEGRATION.md`](docs/README_INTEGRATION.md)
  - [`docs/README_QR.md`](docs/README_QR.md)
- [`docs/prompts/`](docs/prompts/) — Web UI prompt specifications & hardware handoff specs:
  - [`prompt_specification_esp32_s3_littlefs_web_app.md`](docs/prompts/prompt_specification_esp32_s3_littlefs_web_app.md)
  - [`software_integration_guide.md`](docs/prompts/software_integration_guide.md)
  - [`hardware_to_software_handoff_phase_1_plan_b.md`](docs/prompts/hardware_to_software_handoff_phase_1_plan_b.md)
- [`firmware/`](firmware/) — ESP32-S3 Arduino sketches:
  - `firmware/ESP32S3_Fragrance_V2/` — Active v2.0.0 firmware (`ESP32S3_Fragrance_V2.ino`, `config.h`, `config.example.h`)
  - `firmware/ESP32S3TestCode/` — Original V1 hardware test reference
- [`sql/`](sql/) — Idempotent SQL Server setup & upgrade scripts (run in order):
  1. `sql/smartscent_db_setup.sql`
  2. `sql/qr_room_upgrade.sql`
  3. `sql/hardware_integration_upgrade.sql`
- [`qr/`](qr/) — Printable Room 101 & 102 QR cards & generator (`python qr/generate_qr.py`)
- [`tools/`](tools/) — Mock ESP32 simulator (`tools/mock_esp32.php`) & background CLI collector (`tools/collector.php`)

---

## Quick Start (After Cloning / Pulling)

1. **Link your cloned folder to XAMPP `htdocs` (one-time per PC):**  
   Windows folder junctions are **not** synced by Git. Unless you cloned directly into `C:\xampp\htdocs\smartscent`, run this in **Command Prompt (`cmd`)** to avoid `404 Not Found` errors:
   ```cmd
   mklink /J "C:\xampp\htdocs\smartscent" "<FULL_PATH_TO_YOUR_CLONED_SmartScent_Dashboard_v3_FOLDER>"
   ```
2. **Start Apache & allow Port 80:**  
   Open **XAMPP Control Panel** and click **Start** on **Apache**. If your phone cannot connect over Wi-Fi, allow Port `80` in **PowerShell (Admin)**:
   ```powershell
   New-NetFirewallRule -DisplayName "SmartScent HTTP 80" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
   ```
3. **Regenerate QR codes & test on PC/phone:**  
   Check your laptop's Wi-Fi IPv4 with `ipconfig`, run `python qr/generate_qr.py` to update `qr/qr_room_101.png` and `qr/qr_room_102.png`, and connect your phone to the **same Wi-Fi**.
   - **Phone:** Scan `qr/qr_room_101.png` or open `http://<YOUR_LAPTOP_IP>/smartscent/login.php?room=101`
   - **PC:** Open `http://localhost/smartscent/login.php?room=101`
4. **Full setup guides:**  
   See [`docs/README_QR.md`](docs/README_QR.md) for QR room login & SQL Server setup, and [`docs/README_INTEGRATION.md`](docs/README_INTEGRATION.md) for ESP32-S3 firmware (`config.h`) & Wi-Fi details.