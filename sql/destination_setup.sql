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
    sync_status  VARCHAR(50),
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_donor_email (email)
) ENGINE=InnoDB;

-- Persistent cursor: tracks the last successfully synced ID per engine.
-- Safer than MAX(remote_id) which can skip rows if source has gaps or reorders.
CREATE TABLE IF NOT EXISTS sync_cursor (
    engine          VARCHAR(50) PRIMARY KEY,
    last_synced_id  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO sync_cursor (engine, last_synced_id) VALUES ('SyncEngine', 0);

-- Audit log: immutable record of every sync run for financial traceability.
CREATE TABLE IF NOT EXISTS sync_audit_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    engine           VARCHAR(50) NOT NULL,
    rows_synced      INT UNSIGNED NOT NULL DEFAULT 0,
    rows_failed      INT UNSIGNED NOT NULL DEFAULT 0,
    rows_dlq         INT UNSIGNED NOT NULL DEFAULT 0,
    rows_emergency   INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds DECIMAL(8, 2),
    cursor_end       INT UNSIGNED,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
