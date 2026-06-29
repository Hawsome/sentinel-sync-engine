# Reliability Verification and Chaos Engineering Report

This document outlines the testing protocol used to validate the resilience and data integrity of the Sentinel Sync Engine.

## Testing Objectives
1. **Zero Data Loss:** Ensure records are never dropped, even during total database failure.
2. **Idempotency and State Tracking:** Verify the system never duplicates data and correctly tracks its progress.
3. **Self-Healing:** Confirm the system recovers state once connectivity is restored.
4. **Concurrency Safety:** Ensure multiple overlapping instances do not corrupt data.

---

## Test Suite 1: Direct Synchronisation (Tier 1)
**Scenario:** Source and destination databases are healthy.
- **Action:** Executed `php src/SyncEngine.php` with 100 sample records.
- **Expected Result:** 100% of records appear in `accounting_dest_db` with status `SUCCESS`. Cursor advances in `sync_cursor`.
- **Actual Result:** PASSED. 100/100 records synced in memory-safe batch transactions. `sync_audit_log` entry written with correct counts and duration.

---

## Test Suite 2: Destination Outage (Tier 2 Fallback)
**Scenario:** The destination accounting database becomes unreachable mid-process.
- **Methodology:** Injected a manual `PDOException` into the sync loop.
- **Action:** Executed sync engine.
- **Expected Result:** Engine catches the failure and persists the full error envelope (data, error message, timestamp) into `sync_dead_letter_queue` on the source DB.
- **Actual Result:** PASSED. Records were serialised to JSON and stored in the DLQ.
- **Recovery Check:** Restored connection and ran `php src/RecoveryWorker.php`. All records moved to destination with status `RECOVERED`. DLQ entries deleted on success.

---

## Test Suite 3: Total Infrastructure Collapse (Tier 3 Fallback)
**Scenario:** Both destination and source SQL services are unavailable (simulating `MySQL server has gone away`).
- **Methodology:** Severed the active MySQL session during execution.
- **Action:** Executed sync engine.
- **Expected Result:** Tier 3 activates when the destination insert fails AND the subsequent DLQ insert on the source DB also fails. The engine writes the failed payload to a local filesystem JSON log. `file_put_contents` return value is checked; a failed disk write logs `CRITICAL`.
- **Actual Result:** PASSED. Engine created the `logs/` directory (permissions 0750) and persisted payloads as timestamped `.json` log files.
- **Recovery Check:** Restored SQL service and ran `php src/EmergencyIngestor.php`. All unprocessed log files (not just today's) were detected via `glob()`. Each file was renamed to `.inprogress` before reading to prevent double-ingestion. Data was synced in 500-row batches and files archived to `.processed`.

---

## Test Suite 4: State Tracking and Idempotency
**Scenario:** The script is run multiple times consecutively.
- **Methodology:** Ran `SyncEngine.php` immediately after a successful run.
- **Action:** Executed sync engine.
- **Expected Result:** Engine reports "0 new records to sync." and does not re-process historical data. Cursor value is unchanged.
- **Actual Result:** PASSED. Engine read `last_synced_id` from `sync_cursor` (inside a transaction with `FOR UPDATE`) and fetched zero rows. `ON DUPLICATE KEY UPDATE` provides a second layer of idempotency if IDs overlap.

---

## Test Suite 5: Concurrency Safety (Cron Collision Test)
**Scenario:** A cron job triggers the script while a previous sync is still running.
- **Methodology:** Added an artificial `sleep(10)` delay to the sync loop. Ran the script in two terminal windows simultaneously.
- **Action:** Executed second sync engine instance.
- **Expected Result:** Second instance exits immediately, recognising the active lock.
- **Actual Result:** PASSED. `flock(LOCK_EX | LOCK_NB)` rejected the second instance atomically. Output: `Sync is already running. Exiting to prevent overlap.`

---

## Test Suite 6: DLQ Max-Attempts and Quarantine
**Scenario:** A DLQ record fails repeatedly due to permanently corrupt data.
- **Methodology:** Inserted a malformed payload into `sync_dead_letter_queue` and ran `RecoveryWorker.php` repeatedly.
- **Expected Result:** After `MAX_ATTEMPTS` (5) failures, the record is marked `quarantined = 1` and no longer retried. An `ERROR` log line is emitted.
- **Actual Result:** PASSED. Record was quarantined after 5 attempts. Subsequent recovery runs skipped it via `WHERE quarantined = 0`.

---

## Test Suite 7: Row Validation
**Scenario:** Source database contains a row with a missing email and a negative amount.
- **Methodology:** Inserted invalid rows directly into `wp_donations`.
- **Expected Result:** Rows are skipped with a `WARN` log line. No crash. No silent write to destination.
- **Actual Result:** PASSED. `validate_row()` caught both cases. Invalid rows were counted in `rows_failed` in `sync_audit_log`.

---

## Final Verdict
The Sentinel Sync Engine successfully handled all injected faults across the networking, database, process-scheduling, and data-integrity layers. The system is verified as memory-safe, fault-tolerant, and production-ready.
