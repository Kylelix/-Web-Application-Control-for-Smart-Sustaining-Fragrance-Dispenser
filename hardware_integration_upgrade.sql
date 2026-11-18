-- ====================================================================
-- SmartScent Dashboard — Hardware Integration Database Upgrade
-- Database: smartscent_db
-- Target: Microsoft SQL Server / SSMS
-- Description: Creates [devices] and [activity_log] tables, filtered
--              indexes, and seeds SFD-01 (Room 101) & SFD-02 (Room 102).
-- ====================================================================

USE smartscent_db;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- 1. Create [devices] registry table
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name = 'devices' AND xtype = 'U')
BEGIN
    CREATE TABLE devices (
        device_id    VARCHAR(20)  NOT NULL PRIMARY KEY,
        room_number  VARCHAR(10)  NULL,
        host         VARCHAR(100) NOT NULL,
        is_enabled   BIT          NOT NULL DEFAULT 1,
        firmware     VARCHAR(20)  NULL,
        last_seen    DATETIME2    NULL,
        created_at   DATETIME2    NOT NULL DEFAULT SYSDATETIME()
    );
    PRINT 'Table [devices] created successfully.';
END
ELSE
BEGIN
    PRINT 'Table [devices] already exists.';
END
GO

-- Filtered unique index so multiple devices could have NULL room_number if needed,
-- while each assigned room maps uniquely to one device.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'UQ_devices_room_number' AND object_id = OBJECT_ID('devices'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_devices_room_number
    ON devices (room_number)
    WHERE room_number IS NOT NULL;
    PRINT 'Filtered unique index [UQ_devices_room_number] created successfully.';
END
ELSE
BEGIN
    PRINT 'Filtered index [UQ_devices_room_number] already exists.';
END
GO

-- 2. Seed SFD-01 (Room 101) and SFD-02 (Room 102)
-- EDIT THESE HOSTS IN SSMS OR VIA admin_devices.php TO MATCH YOUR NETWORK:
--   * STA static IPs (final two-prototype test): 192.168.1.15 / 192.168.1.16
--   * Bench SoftAP single-device test: 192.168.4.1
--   * Mock ESP32 simulator (tools/mock_esp32.php): 127.0.0.1:8081 / 127.0.0.1:8082
IF NOT EXISTS (SELECT 1 FROM devices WHERE device_id = 'SFD-01')
BEGIN
    INSERT INTO devices (device_id, room_number, host, is_enabled)
    VALUES ('SFD-01', '101', '192.168.1.15', 1);
    PRINT 'Device [SFD-01] seeded (Room 101 -> 192.168.1.15).';
END
ELSE
BEGIN
    PRINT 'Device [SFD-01] already exists.';
END
GO

IF NOT EXISTS (SELECT 1 FROM devices WHERE device_id = 'SFD-02')
BEGIN
    INSERT INTO devices (device_id, room_number, host, is_enabled)
    VALUES ('SFD-02', '102', '192.168.1.16', 1);
    PRINT 'Device [SFD-02] seeded (Room 102 -> 192.168.1.16).';
END
ELSE
BEGIN
    PRINT 'Device [SFD-02] already exists.';
END
GO

-- 3. Create [activity_log] table (Device data ONLY: no guest personal data)
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name = 'activity_log' AND xtype = 'U')
BEGIN
    CREATE TABLE activity_log (
        id          BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        logged_at   DATETIME2    NOT NULL DEFAULT SYSDATETIME(),
        device_id   VARCHAR(20)  NOT NULL,
        event       VARCHAR(30)  NOT NULL,  -- SAMPLE, COMMAND, MIST_START, MIST_END, LOCK_ON, LOCK_OFF, OFFLINE, ONLINE, BASELINE
        actor_role  VARCHAR(10)  NULL,      -- admin, client, system
        voc_index   INT          NULL,
        voc_status  VARCHAR(10)  NULL,
        water_ok    BIT          NULL,
        mode        VARCHAR(10)  NULL,
        profile     VARCHAR(10)  NULL,
        state       VARCHAR(12)  NULL,
        detail      VARCHAR(120) NULL,
        CONSTRAINT FK_activity_log_device FOREIGN KEY (device_id) REFERENCES devices(device_id)
    );
    PRINT 'Table [activity_log] created successfully.';
END
ELSE
BEGIN
    PRINT 'Table [activity_log] already exists.';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_activity_log_device_time' AND object_id = OBJECT_ID('activity_log'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_activity_log_device_time
    ON activity_log (device_id, logged_at DESC);
    PRINT 'Index [IX_activity_log_device_time] created successfully.';
END
ELSE
BEGIN
    PRINT 'Index [IX_activity_log_device_time] already exists.';
END
GO

-- ====================================================================
-- QUICK HOST SWITCH EXAMPLES (Uncomment and run as needed)
-- ====================================================================
-- Switch SFD-01 to SoftAP bench test:
-- UPDATE devices SET host = '192.168.4.1' WHERE device_id = 'SFD-01';
--
-- Switch both to local mock ESP32 simulator:
-- UPDATE devices SET host = '127.0.0.1:8081' WHERE device_id = 'SFD-01';
-- UPDATE devices SET host = '127.0.0.1:8082' WHERE device_id = 'SFD-02';
--
-- Restore STA production static IPs:
-- UPDATE devices SET host = '192.168.1.15' WHERE device_id = 'SFD-01';
-- UPDATE devices SET host = '192.168.1.16' WHERE device_id = 'SFD-02';
-- ====================================================================

SELECT device_id, room_number, host, is_enabled, firmware, last_seen FROM devices;
GO