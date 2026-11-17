# SmartScent — Hotel Room QR Guest Login Guide

This guide walks you through setting up and testing the QR-based guest authentication for hotel prototypes (Room 101 and Room 102).

---

### Step 1: Database Setup in SSMS
1. If not already executed, run [`smartscent_db_setup.sql`](smartscent_db_setup.sql) in SQL Server Management Studio (SSMS).
2. Open and execute [`qr_room_upgrade.sql`](qr_room_upgrade.sql) in SSMS:
   - Adds the `room_number` column and unique filtered index.
   - Configures `client101@smartscent.com` (Room 101, temp password: `room101pass`).
   - Configures `client102@smartscent.com` (Room 102, temp password: `room102pass`).
   - Deactivates unassigned observer accounts.

---

### Step 2: Network & Firewall Setup
1. Open PowerShell or Command Prompt and run:
   ```cmd
   ipconfig
   ```
   Note your laptop's **IPv4 Address** on your Wi-Fi network (e.g., `192.168.1.15`).
2. **Set a Static IP** on your laptop (or DHCP reservation in your router/mobile hotspot) so the IP address does not change between tests or after printing.
3. **Allow Apache through Windows Firewall**:
   - Ensure Apache HTTP Server (port 80) is allowed for Private networks in Windows Defender Firewall.
4. Ensure your test mobile phone and the host laptop are connected to the **same Wi-Fi network** (or phone hotspot).

---

### Step 3: Generate QR Code Cards
1. Open [`generate_qr.py`](generate_qr.py).
2. Update `BASE_URL` with your laptop's static IPv4:
   ```python
   BASE_URL = "http://<YOUR_LAPTOP_IP>/smartscent/login.php?room="
   ```
3. Run the generator script:
   ```cmd
   python generate_qr.py
   ```
4. Output images [`qr_room_101.png`](qr_room_101.png) and [`qr_room_102.png`](qr_room_102.png) will be created with room labels and print-ready dimensions (4 to 5 cm).

---

### Step 4: Test Scanning & First-Time Login
1. Test by scanning `qr_room_101.png` and `qr_room_102.png` using a mobile phone camera before printing.
2. The phone browser will open `login.php?room=101` with the room number prefilled.
3. Log in once per account using the temporary passwords:
   - **Room 101**: Username `101` or `client101@smartscent.com` | Password: `room101pass`
   - **Room 102**: Username `102` or `client102@smartscent.com` | Password: `room102pass`
   - **Admin**: Username `admin@smartscent.com` | Password: `admin123`
4. The system transparently hashes the temporary plain-text passwords into secure **bcrypt** hashes upon first successful login.

---

### Front Desk Workflow (Check-in & Check-out)
When a guest checks out, reception deactivates the login:
```sql
UPDATE userlogin SET is_active = 0 WHERE room_number = '101';
```

When a new guest checks in, reception issues a fresh password and reactivates the account:
```sql
UPDATE userlogin SET is_active = 1, password = '<new temp password>' WHERE room_number = '101';
```
*(The plain-text password will auto-rehash to bcrypt on the guest's first login.)*
