-- ==========================================================
-- DESTINATION DATABASE SETUP (Accounting/ERP System)
-- Purpose: Optimized for reporting and financial integrity.
-- ==========================================================

CREATE DATABASE IF NOT EXISTS accounting_dest_db;
USE accounting_dest_db;

CREATE TABLE IF NOT EXISTS synced_donations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    remote_id INT UNIQUE NOT NULL, 
    email VARCHAR(255) NOT NULL,
    amount_naira DECIMAL(10, 2) NOT NULL,
    sync_status VARCHAR(50),
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Create an index on email for faster lookup/reporting
CREATE INDEX idx_donor_email ON synced_donations(email);