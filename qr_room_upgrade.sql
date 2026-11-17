-- ====================================================================
-- SmartScent Dashboard — QR Room Upgrade Script
-- Database: smartscent_db
-- Target: Microsoft SQL Server / SSMS
-- Description: Adds room_number column, room index, and configures
--              accounts for Room 101 and Room 102 prototype testing.
-- ====================================================================

USE smartscent_db;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- 1. Add room_number column to userlogin if it does not already exist
IF COL_LENGTH('userlogin', 'room_number') IS NULL
BEGIN
    ALTER TABLE userlogin ADD room_number VARCHAR(10) NULL;
    PRINT 'Column [room_number] added to table [userlogin].';
END
ELSE
BEGIN
    PRINT 'Column [room_number] already exists in table [userlogin].';
END
GO

-- 2. Create UNIQUE filtered index on room_number (only non-null rooms must be unique)
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'UQ_userlogin_room_number' AND object_id = OBJECT_ID('userlogin'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_userlogin_room_number
    ON userlogin (room_number)
    WHERE room_number IS NOT NULL;
    PRINT 'Filtered unique index [UQ_userlogin_room_number] created successfully.';
END
ELSE
BEGIN
    PRINT 'Filtered index [UQ_userlogin_room_number] already exists.';
END
GO

-- 3. Convert existing client account to Room 101 guest
-- Password starts in plain-text and automatically rehashes to bcrypt upon first login.
IF EXISTS (SELECT 1 FROM userlogin WHERE email = 'client@smartscent.com' OR room_number = '101')
BEGIN
    UPDATE userlogin
    SET email = 'client101@smartscent.com',
        room_number = '101',
        full_name = 'Room 101 Guest',
        password = 'room101pass',
        is_active = 1
    WHERE email = 'client@smartscent.com' OR room_number = '101';
    PRINT 'Room 101 account updated (client101@smartscent.com).';
END
GO

-- 4. Insert Room 102 guest account if missing
IF NOT EXISTS (SELECT 1 FROM userlogin WHERE email = 'client102@smartscent.com' OR room_number = '102')
BEGIN
    INSERT INTO userlogin (email, password, role, full_name, room_number, is_active)
    VALUES ('client102@smartscent.com', 'room102pass', 'client', 'Room 102 Guest', '102', 1);
    PRINT 'Room 102 account inserted (client102@smartscent.com).';
END
ELSE
BEGIN
    PRINT 'Room 102 account already exists.';
END
GO

-- 5. Deactivate observer account (every client must belong to a specific hotel room)
IF EXISTS (SELECT 1 FROM userlogin WHERE email = 'observer@smartscent.com')
BEGIN
    UPDATE userlogin
    SET is_active = 0
    WHERE email = 'observer@smartscent.com';
    PRINT 'Observer account deactivated (every client must belong to a room).';
END
GO

-- Note: admin@smartscent.com is unchanged and retains room_number NULL.

-- ====================================================================
-- FRONT DESK WORKFLOW HELPER QUERIES (Copy and run as needed)
-- ====================================================================
-- Guest check-out (deactivates room login so QR link cannot be reused):
-- UPDATE userlogin SET is_active = 0 WHERE room_number = '101';
--
-- New guest check-in (re-activates room login with fresh temporary password):
-- UPDATE userlogin SET is_active = 1, password = '<new temp password>' WHERE room_number = '101';
-- ====================================================================

-- 6. Verification query
SELECT id, email, role, full_name, room_number, is_active FROM userlogin;
GO
