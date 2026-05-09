# System Architecture: Sentinel Sync Engine

## Overview
The Sentinel Sync Engine is designed to bridge the gap between unstructured CMS data and mission-critical relational databases. It prioritizes **Data Integrity** over speed.

## Core Design Patterns

### 1. The Dead Letter Queue (DLQ)
In standard sync scripts, a network timeout results in a "Silent Failure" where data is lost. Sentinel implements a DLQ pattern. If the `destination_db` is unreachable, the engine serializes the data into a JSON payload and stores it locally. 

**Why JSON?**
By storing the failed payload as JSON, we decouple the recovery process from the source table schema. If the source table changes, the recovery worker still has the original data captured at the moment of failure.

### 2. Idempotency (The 'remote_id' Constraint)
To prevent duplicate records during retries, the system uses a `UNIQUE` constraint on the `remote_id`. 
- **Action:** `INSERT ... ON DUPLICATE KEY UPDATE`
- **Benefit:** If a sync job is interrupted and restarted, the system gracefully updates existing records rather than creating duplicates.

### 3. Financial Precision
Floating-point math in programming (e.g., `0.1 + 0.2`) can lead to rounding errors. Sentinel enforces:
- **Source:** Integers (Kobos)
- **Transit:** Handled as integers.
- **Destination:** `DECIMAL(10,2)` for fixed-point precision.

## Data Flow
1. **Extraction:** PHP PDO fetches records from `wp_source_db`.
2. **Transformation:** Logic converts Kobos to Naira and sanitizes inputs.
3. **Execution:** Attempted write to `accounting_dest_db`.
4. **Fallback:** On failure, `catch` block routes data to `sync_dead_letter_queue`.
5. **Recovery:** `RecoveryWorker.php` polls the DLQ and re-attempts the sync.