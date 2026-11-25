# SmartScent — Hotel Room QR Guest Login Guide

This guide walks you through setting up and testing the QR-based guest authentication for hotel prototypes (Room 101 and Room 102).

---

### Step 1: Database Setup in SSMS
1. If not already executed, run [`sql/smartscent_db_setup.sql`](../sql/smartscent_db_setup.sql) in SQL Server Management Studio (SSMS).
2. Open and execute [`sql/qr_room_upgrade.sql`](../sql/qr_room_upgrade.sql) and [`sql/hardware_integration_upgrade.sql`](../sql/hardware_integration_upgrade.sql) in SSMS:
   - Adds the `room_number` column, `devices` registry, and `activity_log` table.
   - Configures `client101@smartscent.com` (Room 101 -> `SFD-01`) and `client102@smartscent.com` (Room 102 -> `SFD-02`).
   - Deactivates unassigned observer accounts.

---

### Step 2: Network & Firewall Setup
1. Open PowerShell or Command Prompt and run:
   ```cmd
   ipconfig
   ```
   Note your laptop's **IPv4 Address** on your Wi-Fi network (e.g., `192.168.1.10`).
2. **Set a Static IP** on your laptop (or DHCP reservation in your router/mobile hotspot) so the IP address does not change between tests or after printing.
3. **Allow Apache through Windows Firewall**:
   - Serve the website with XAMPP Apache (port 80) and ensure Apache HTTP Server is allowed for Private networks in Windows Defender Firewall.
4. Ensure your test mobile phone and the host laptop are connected to the **same Wi-Fi network** (or phone hotspot).

---

### Step 3: Generate QR Code Cards
1. Open [`qr/generate_qr.py`](../qr/generate_qr.py) and confirm `PORT` and `BASE_PATH` match how you host the site:
   - **XAMPP Apache (recommended)**: `PORT = 80`, `BASE_PATH = "/smartscent"` -> `http://<YOUR_LAPTOP_IP>/smartscent/login.php?room=`
   - **PHP Built-in Server (single-device debug only)**: `PORT = 8000`, `BASE_PATH = ""`
   *(The script auto-detects your active `SERVER_IP` via `get_local_ip()`.)*
2. Run the generator script:
   ```cmd
   python qr/generate_qr.py
   ```
3. Output images [`qr/qr_room_101.png`](../qr/qr_room_101.png) and [`qr/qr_room_102.png`](../qr/qr_room_102.png) will be created with room labels and print-ready dimensions (4 to 5 cm).

---

### Step 4: Test Scanning & First-Time Login
1. Test by scanning `qr_room_101.png` and `qr_room_102.png` using a mobile phone camera before printing.
2. The phone browser will open `login.php?room=101` with the room number prefilled.
3. Issue a fresh guest password via **Room Access** (`admin_rooms.php`) after signing in as Admin (`admin@smartscent.com`), or use the initial temporary passwords once so they convert to **bcrypt** hashes:
   - **Room 101**: Username `101` or `client101@smartscent.com` | Initial Password: `room101pass`
   - **Room 102**: Username `102` or `client102@smartscent.com` | Initial Password: `room102pass`
   - **Admin**: Username `admin@smartscent.com` | Initial Password: `admin123`

---

### Front Desk Workflow (Check-in & Check-out)
Use **`admin_rooms.php`** (recommended — hashes passwords immediately with bcrypt and revokes active sessions within 30 seconds), or use SQL directly in SSMS:
```sql
-- Check-out:
UPDATE userlogin SET is_active = 0 WHERE room_number = '101';

-- Check-in:
UPDATE userlogin SET is_active = 1, password = '<new temp password>' WHERE room_number = '101';
```