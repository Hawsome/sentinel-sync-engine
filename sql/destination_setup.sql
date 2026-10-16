-- ==========================================================
-- DESTINATION DATABASE SETUP (Accounting/ERP System)
-- Purpose: Optimized for reporting and financial integrity.
-- ==========================================================

CREATE DATABASE IF NOT EXISTS accounting_dest_db;
USE accounting_dest_db;

CREATE TABLE IF NOT EXISTS synced_donations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    remote_id    INT UNSIGNED UNIQUE NOT NULL,
    email        VARCHAR(254) NOT NULL,
    amount_naira DECIMAL(10, 2) UNSIGNED NOT NULL,
    donation_status   VARCHAR(20) NOT NULL DEFAULT 'completed',
    -- Version of the source row this reflects. Older snapshots never overwrite newer ones.
    source_updated_at TIMESTAMP(6) NULL DEFAULT NULL,
    sync_status  VARCHAR(50),
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_donor_email (email)
) ENGINE=InnoDB;

-- Persistent cursor: the (updated_at, id) of the last source row each engine has handled.
CREATE TABLE IF NOT EXISTS sync_cursor (
    engine          VARCHAR(50) PRIMARY KEY,
    last_updated_at TIMESTAMP(6) NOT NULL DEFAULT '2000-01-01 00:00:00.000000',
    last_synced_id  INT UNSIGNED NOT NULL DEFAULT 0, -- tie-breaker within last_updated_at
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO sync_cursor (engine, last_synced_id) VALUES ('SyncEngine', 0);

-- Audit log: one row per sync run, including the ones that failed.
CREATE TABLE IF NOT EXISTS sync_audit_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    engine           VARCHAR(50) NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'COMPLETED', -- COMPLETED or ABORTED
    rows_synced      INT UNSIGNED NOT NULL DEFAULT 0,
    rows_failed      INT UNSIGNED NOT NULL DEFAULT 0,
    rows_dlq         INT UNSIGNED NOT NULL DEFAULT 0,
    rows_emergency   INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds DECIMAL(8, 2),
    cursor_end       INT UNSIGNED,
    cursor_end_at    TIMESTAMP(6) NULL DEFAULT NULL,
    error_message    TEXT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
