# System Architecture: Sentinel Sync Engine

## Overview
The Sentinel Sync Engine is designed to bridge the gap between unstructured CMS data and mission-critical relational databases. It prioritizes **Data Integrity** and **Scalable Performance** to handle massive data loads without failure.

## Core Design Patterns

### 1. The Dead Letter Queue (DLQ)
In standard sync scripts, a network timeout results in a "Silent Failure" where data is lost. Sentinel implements a DLQ pattern. If the `destination_db` is unreachable, the engine serializes the data into a JSON payload and stores it locally. 

**Why JSON?**
By storing the failed payload as JSON, we decouple the recovery process from the source table schema. If the source table changes, the recovery worker still has the original data captured at the moment of failure.

### 2. Idempotency (The 'remote_id' Constraint)
To prevent duplicate records during edge cases or manual data entry errors, the system uses a `UNIQUE` constraint on the `remote_id`. 
- **Action:** `INSERT ... ON DUPLICATE KEY UPDATE`
- **Benefit:** If a sync job is interrupted and restarted on the same data, the system gracefully updates existing records rather than creating duplicates.

### 3. Financial Precision
Floating-point math in programming (e.g., `0.1 + 0.2`) can lead to rounding errors. Sentinel enforces:
- **Source:** Integers (Kobos)
- **Transit:** Handled as integers.
- **Destination:** `DECIMAL(10,2)` for fixed-point precision.

### 4. State Tracking (The Watermark)
To prevent "infinite loop" re-syncing of historical data, Sentinel queries the destination database for the `MAX(remote_id)`. It uses this watermark to only pull *new* records (`WHERE id > :last_id`) from the source database, drastically improving efficiency.

### 5. Memory Management & Batching
Sentinel is built to process millions of rows without hitting PHP memory limits. 
- **Streaming:** Uses `PDO::fetch()` and file streaming (`fgets`) instead of memory-heavy `fetchAll()` or `file()`.
- **Transaction Batching:** Groups destination inserts into Database Transactions (chunks of 500) to minimize network overhead and maximize write speed.

### 6. Concurrency Locks
To safely run via a Cron scheduler, the engine implements a file-based locking mechanism (`logs/sync.lock`). This ensures that if a long-running sync overlaps with the next scheduled run, the newer job gracefully exits to prevent race conditions.

### 7. Secure Configuration
All sensitive data (database credentials) is abstracted out of version control using `vlucas/phpdotenv`. Credentials are injected securely via a `.env` file.

## Data Flow
1. **Concurrency Check:** Engine checks for existing lock files.
2. **Watermark:** Engine determines the last synced ID.
3. **Extraction:** PDO safely streams new records from `wp_source_db`.
4. **Transformation:** Logic converts Kobos to Naira and sanitizes inputs.
5. **Execution:** Data is committed to `accounting_dest_db` in transaction batches.
6. **Fallback:** On individual row failure, the `catch` block routes data to `sync_dead_letter_queue`.
7. **Recovery:** `RecoveryWorker.php` polls the DLQ and re-attempts the sync.