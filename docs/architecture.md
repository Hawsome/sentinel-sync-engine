# System Architecture: Sentinel Sync Engine

## Overview
The Sentinel Sync Engine bridges the gap between unstructured CMS data and mission-critical relational databases. It prioritises **Data Integrity** and **Scalable Performance** to handle large data loads without failure.

## Core Design Patterns

### 1. The Dead Letter Queue (DLQ)
In standard sync scripts, a row-level failure results in silent data loss. Sentinel implements a DLQ pattern. If a destination insert fails, the engine serialises the full error envelope as JSON into `sync_dead_letter_queue` on the source database.

**Why JSON?**
Storing the failed payload as JSON decouples the recovery process from the source table schema. If the source table changes, the recovery worker still has the original data captured at the moment of failure, including the error message and timestamp.

**Payload format:**
```json
{
  "data": { "id": 42, "donor_email": "...", "amount_kobo": 5000, "created_at": "..." },
  "error": "SQLSTATE[...] ...",
  "timestamp": "2026-06-29 12:00:00"
}
```

### 2. Idempotency (The `remote_id` Constraint)
The destination table enforces a `UNIQUE` constraint on `remote_id`. Every insert uses:
```sql
INSERT ... ON DUPLICATE KEY UPDATE email = VALUES(email), amount_naira = VALUES(amount_naira), ...
```
This means re-running after an interruption is always safe: no duplicates, and corrected source records are reflected in the destination.

### 3. Financial Precision
Floating-point arithmetic can introduce rounding errors. Sentinel enforces a strict currency handling chain:
- **Source:** Integers (Kobos)
- **Transit:** Cast to integer in PHP, then divided by 100 using float division and rounded to 2 decimal places before the destination write
- **Destination:** `DECIMAL(10,2) UNSIGNED` for fixed-point precision

### 4. State Tracking (Persistent Cursor)
To prevent re-syncing historical data on every run, Sentinel uses a dedicated `sync_cursor` table in the destination database. After each successful run, the highest processed `remote_id` is persisted there.

This is safer than `MAX(remote_id)` because it is explicit: gaps in the source IDs, non-sequential inserts, or multi-writer scenarios cannot cause rows to be skipped.

```sql
SELECT last_synced_id FROM sync_cursor WHERE engine = 'SyncEngine' FOR UPDATE
```

The `FOR UPDATE` runs inside an open transaction to ensure the lock is actually held, preventing two concurrent instances from reading the same cursor value.

### 5. Row Validation
Every row is validated before any database write:
- `id` must be a positive integer
- `amount_kobo` must be a positive integer
- `donor_email` must pass `FILTER_VALIDATE_EMAIL` and be within RFC 5321 length limits

Invalid rows are logged and skipped rather than silently written or crashed over.

### 6. Memory Management and Batching
Sentinel is built to process millions of rows without hitting PHP memory limits.
- **Streaming:** Uses `PDO::fetch()` and `fgets()` instead of `fetchAll()` or `file()`.
- **Transaction Batching:** Groups destination inserts into transactions of 500 rows to minimise network overhead and maximise write speed.

### 7. Concurrency Locks
The engine uses `flock(LOCK_EX | LOCK_NB)` for process-level locking. This is atomic -- unlike a `file_exists` check, two processes cannot both acquire the lock simultaneously. The OS automatically releases the lock if the process dies, so stale locks are never an issue.

### 8. Three-Tier Resilience

| Tier | Trigger | Mechanism |
|------|---------|-----------|
| 1 - Primary Sync | Normal operation | Stream rows, batch-insert to destination |
| 2 - Dead Letter Queue | Row-level insert failure | Serialise payload to `sync_dead_letter_queue` |
| 3 - Emergency Log | Source DB also unreachable | Append payload to local filesystem JSON log |

The `EmergencyIngestor` processes all unarchived emergency logs (not just today's) and renames each file to `.inprogress` before reading it, preventing double-ingestion if the process is interrupted mid-file. It uses `flock` for process-level concurrency control, consistent with `SyncEngine` and `RecoveryWorker`.

### 9. Secure Configuration
All credentials are loaded via `vlucas/phpdotenv`. There are no hardcoded fallback values. `DB_PORT` defaults to `3306` if not set; all other five database variables are required at startup. Missing required credentials produce a clear error rather than silently connecting as root.

Explicit `SELECT` column lists are used throughout to prevent future source-schema additions from leaking into destination inserts or DLQ payloads.

### 10. Observability
- All output uses timestamped, levelled log lines (`INFO`, `WARN`, `ERROR`, `CRITICAL`), safe to capture in cron.
- Every sync run writes a row to `sync_audit_log` recording row counts, failure counts, run duration, and final cursor position.
- The DLQ tracks `attempts` and `quarantined` per record. Records that fail `MAX_ATTEMPTS` (5) times are quarantined rather than retried indefinitely. Quarantine events are logged at `ERROR` level.
- `RecoveryWorker` processes a maximum of 100 DLQ records per run (`RECOVERY_BATCH_LIMIT`). This bounds the recovery window and prevents a large backlog from blocking normal operations.

## Data Flow

1. **Concurrency Check:** Engine acquires an exclusive `flock` or exits immediately. All three scripts (`SyncEngine`, `RecoveryWorker`, `EmergencyIngestor`) use separate lock files.
2. **Cursor Read:** Engine reads `last_synced_id` from `sync_cursor` inside an open transaction (`FOR UPDATE`). The lock is held until the first 500-row batch commits, at which point the transaction cycles. The cursor is persisted at the end of the run.
3. **Extraction:** PDO streams new records from `wp_source_db` (`WHERE id > :last_id`).
4. **Validation:** Each row is validated before processing.
5. **Transformation:** `amount_kobo` cast to integer, divided by 100 using float division, and rounded to 2 decimal places to produce `amount_naira`.
6. **Execution:** Data committed to `accounting_dest_db` in 500-row transaction batches.
7. **Fallback (Tier 2):** Row failure routes payload to `sync_dead_letter_queue`.
8. **Fallback (Tier 3):** DLQ insert failure routes payload to local emergency log file.
9. **Cursor Update:** `sync_cursor` updated to the highest successfully processed ID.
10. **Audit:** Run summary written to `sync_audit_log`.
11. **Recovery:** `RecoveryWorker.php` polls the DLQ and re-attempts up to `MAX_ATTEMPTS` times, then quarantines.
12. **Emergency Recovery:** `EmergencyIngestor.php` processes filesystem logs when the DB returns.
