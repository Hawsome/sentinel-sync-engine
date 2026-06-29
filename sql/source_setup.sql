-- ==========================================================
-- SOURCE DATABASE SETUP (Simulating WordPress/CMS)
-- Purpose: Holds raw transaction data and the Dead Letter Queue.
-- ==========================================================

CREATE DATABASE IF NOT EXISTS wp_source_db;
USE wp_source_db;

-- Table: Raw donations as they arrive from the frontend
CREATE TABLE IF NOT EXISTS wp_donations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    donor_email VARCHAR(254) NOT NULL,
    amount_kobo INT UNSIGNED NOT NULL,  -- Unsigned: negatives are invalid
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table: The Dead Letter Queue (DLQ)
-- Payload stores the full error envelope: {data, error, timestamp}
-- quarantined = 1 means the record exceeded MAX_ATTEMPTS and needs human review
CREATE TABLE IF NOT EXISTS sync_dead_letter_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    payload      JSON NOT NULL,
    error_message TEXT,
    attempts     INT UNSIGNED DEFAULT 0,
    quarantined  TINYINT(1) NOT NULL DEFAULT 0,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- auto-updated by MySQL on every attempt; useful for ops monitoring and manual audits
    INDEX idx_recoverable (quarantined, attempts)
) ENGINE=InnoDB;

-- Seed Data for testing
INSERT INTO wp_donations (donor_email, amount_kobo) VALUES
('awesome@example.com', 5000),
('hawesome@example.com', 12500),
('failure_test@example.com', 7500);
