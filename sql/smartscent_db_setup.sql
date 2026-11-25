-- ====================================================================
-- SmartScent Dashboard — Database & Authentication Setup Script
-- Database: smartscent_db
-- Target: Microsoft SQL Server / SSMS
-- ====================================================================

-- 1. Create the database if it doesn't exist
IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = N'smartscent_db')
BEGIN
    CREATE DATABASE smartscent_db;
    PRINT 'Database [smartscent_db] created successfully.';
END
ELSE
BEGIN
    PRINT 'Database [smartscent_db] already exists.';
END
GO

USE smartscent_db;
GO

-- 2. Create the userlogin table
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='userlogin' AND xtype='U')
BEGIN
    CREATE TABLE userlogin (
        id           INT IDENTITY(1,1) PRIMARY KEY,
        email        VARCHAR(255) NOT NULL UNIQUE,
        password     VARCHAR(255) NOT NULL,
        role         VARCHAR(20)  NOT NULL DEFAULT 'client',  -- 'admin' or 'client'
        full_name    NVARCHAR(255) NULL,
        is_active    BIT NOT NULL DEFAULT 1,
        created_at   DATETIME DEFAULT GETDATE()
    );
    PRINT 'Table [userlogin] created successfully.';
END
ELSE
BEGIN
    PRINT 'Table [userlogin] already exists.';
END
GO

-- Ensure columns exist (for safe re-runs or schema upgrades)
IF COL_LENGTH('userlogin', 'full_name') IS NULL
    ALTER TABLE userlogin ADD full_name NVARCHAR(255) NULL;
GO
IF COL_LENGTH('userlogin', 'is_active') IS NULL
    ALTER TABLE userlogin ADD is_active BIT NOT NULL DEFAULT 1;
GO
IF COL_LENGTH('userlogin', 'created_at') IS NULL
    ALTER TABLE userlogin ADD created_at DATETIME DEFAULT GETDATE();
GO

-- 3. Seed Default Accounts
-- Passwords start in plain-text and automatically rehash to bcrypt upon first login in PHP.

-- ADMIN ACCOUNT
IF NOT EXISTS (SELECT 1 FROM userlogin WHERE email = 'admin@smartscent.com')
BEGIN
    INSERT INTO userlogin (email, password, role, full_name, is_active)
    VALUES ('admin@smartscent.com', 'admin123', 'admin', 'System Administrator', 1);
    PRINT 'Admin account [admin@smartscent.com] inserted.';
END
ELSE
BEGIN
    PRINT 'Admin account already exists.';
END
GO

-- CLIENT ACCOUNT
IF NOT EXISTS (SELECT 1 FROM userlogin WHERE email = 'client@smartscent.com')
BEGIN
    INSERT INTO userlogin (email, password, role, full_name, is_active)
    VALUES ('client@smartscent.com', 'client123', 'client', 'SmartScent Client User', 1);
    PRINT 'Client account [client@smartscent.com] inserted.';
END
ELSE
BEGIN
    PRINT 'Client account already exists.';
END
GO

-- OPTIONAL SECONDARY CLIENT ACCOUNT (Observer / Tester)
IF NOT EXISTS (SELECT 1 FROM userlogin WHERE email = 'observer@smartscent.com')
BEGIN
    INSERT INTO userlogin (email, password, role, full_name, is_active)
    VALUES ('observer@smartscent.com', 'client123', 'client', 'Research Observer', 1);
    PRINT 'Client observer account [observer@smartscent.com] inserted.';
END
GO

-- 4. Display the seeded accounts for verification
SELECT id, email, role, full_name, is_active, created_at FROM userlogin;
GO
