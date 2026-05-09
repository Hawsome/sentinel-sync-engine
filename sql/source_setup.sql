-- ==========================================================
-- SOURCE DATABASE SETUP (Simulating WordPress/CMS)
-- Purpose: Holds raw transaction data and the Dead Letter Queue.
-- ==========================================================

CREATE DATABASE IF NOT EXISTS wp_source_db;
USE wp_source_db;

-- Table: Raw donations as they arrive from the frontend
CREATE TABLE IF NOT EXISTS wp_donations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donor_email VARCHAR(255) NOT NULL,
    amount_kobo INT NOT NULL, -- Stored as integers to prevent rounding errors
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table: The Dead Letter Queue (DLQ)
-- This captures failed sync attempts for later recovery.
CREATE TABLE IF NOT EXISTS sync_dead_letter_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payload JSON NOT NULL, -- Captures the entire data object at the time of failure
    error_message TEXT,
    attempts INT DEFAULT 0,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed Data for testing
INSERT INTO wp_donations (donor_email, amount_kobo) VALUES 
('awesome@example.com', 5000), 
('hawesome@example.com', 12500),
('failure_test@example.com', 7500);