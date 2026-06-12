# Sentinel Sync Engine
**A Fault-Tolerant, Idempotent Data Pipeline for Mission-Critical Systems**

[![Tech Stack](https://img.shields.io/badge/Stack-PHP%20|%20MySQL%20|%20PDO-blue)](https://github.com/hawesome)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Maintenance](https://img.shields.io/badge/Maintained%3F-yes-green.svg)](https://github.com/hawesome)
[![PHP Lint & Syntax Check](https://github.com/Hawsome/sentinel-sync-engine/actions/workflows/php-check.yml/badge.svg)](https://github.com/Hawsome/sentinel-sync-engine/actions/workflows/php-check.yml)

## The Problem
In high-stakes environments, particularly for nonprofits and mission-driven organisations, data loss is not an option. Standard synchronisation scripts often fail due to:
*   **Network Timeouts:** External API or database connectivity drops.
*   **Server Crashes:** Resource exhaustion during large data migrations.
*   **Data Duplication:** Retrying a failed sync often results in "double-counting" records.

**Sentinel is built for when things break.**

## The Solution
Sentinel is a PHP-based synchronisation engine designed with a **"Failure-First"** mentality. It moves data from a Source (CMS) to a Destination (Relational DB) while ensuring:
1.  **Zero Data Loss:** Implements a **Dead Letter Queue (DLQ)** to serialise and capture failed syncs for later recovery.
2.  **Idempotency & State Tracking:** Utilises a `MAX(remote_id)` watermark to only fetch new records, and unique constraints to ensure that retrying a sync never results in duplicate data.
3.  **Self-Healing:** A dedicated **Recovery Worker** that monitors the DLQ and re-processes items once the system is back online.
4.  **Concurrency Safety:** File-based locking prevents overlapping cron jobs from creating race conditions.

## Architecture Flow
```mermaid
graph TD
    A[WordPress / Source DB] -->|1. Extract New| B(Sentinel Sync Engine)
    B -->|2. Batch Transaction| C[Accounting DB]
    B -->|3. Failure Catch| D[Dead Letter Queue]
    D -->|4. Recovery Trigger| E(Recovery Worker)
    E -->|5. Re-attempt| C
```

## Key Engineering Features
*   **Financial Precision:** Stores currency as integers (kobos) in source/transit to avoid floating-point rounding errors, only converting to `DECIMAL(10,2)` at the final destination.
*   **Resilience Pattern:** Uses a `try-catch-queue` loop. A single record failure does not crash the entire migration process.
*   **Data Normalisation:** Maps unstructured/messy CMS metadata into a strict, indexed Relational SQL schema optimised for BI and Reporting.
*   **Memory Management:** Processes records via streaming (`PDO::fetch()`) and batches inserts in transactions to prevent PHP memory limits.
*   **Security:** Full implementation of **PDO Prepared Statements** to eliminate SQL Injection risks, and `.env` configuration to keep credentials out of version control.

## Technical Decisions: Why I built it this way
*   **Why JSON for the DLQ?** By storing failed payloads as JSON, I decoupled the recovery process from the source schema. If the source table changes, the Recovery Worker still has the original data "snapshot" as it existed at the time of failure.
*   **Why Idempotency?** Using `ON DUPLICATE KEY UPDATE` ensures that the system is "stateless." If the sync job is interrupted and restarted, it gracefully updates existing records rather than creating duplicates.
*   **Why PHP/PDO?** I chose PDO (PHP Data Objects) to ensure the engine is database-agnostic. The logic can be ported from MySQL to PostgreSQL or SQLite with minimal configuration changes.

## Getting Started

### 1. Database Setup
Run the SQL scripts provided in the `/sql` directory:
```bash
mysql -u root -p < sql/source_setup.sql
mysql -u root -p < sql/destination_setup.sql
```

### 2. Install Dependencies
Sentinel relies on Composer to manage packages like `vlucas/phpdotenv`.
```bash
composer install
```

### 3. Configuration
Copy the example environment file and update it with your database credentials:
```bash
cp .env.example .env
```
Open `.env` and fill in your details:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASS=your_password
DB_SOURCE=wp_source_db
DB_DEST=accounting_dest_db
```

### 4. Execution
To run the primary sync engine:
```bash
php src/SyncEngine.php
```
To run the recovery worker (clears the queue):
```bash
php src/RecoveryWorker.php
```

## Future Roadmap
- [ ] Implement a **Circuit Breaker** to stop the engine automatically if the failure rate exceeds 20%.
- [ ] Add **Slack/Email Notifications** for critical DLQ alerts.
- [ ] Develop a Web UI to monitor sync health in real-time.
