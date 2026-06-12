# Reliability Verification & Chaos Engineering Report

This document outlines the rigorous testing protocol used to validate the resilience and data integrity of the Sentinel Sync Engine.

## Testing Objectives
1. **Zero Data Loss:** Ensure records are never dropped, even during total database failure.
2. **Idempotency & State Tracking:** Verify the system never duplicates data and correctly tracks its progress.
3. **Self-Healing:** Confirm the system can recover state once connectivity is restored.
4. **Concurrency Safety:** Ensure multiple overlapping instances do not corrupt data.

---

## Test Suite 1: Direct Synchronization (Tier 1)
**Scenario:** Source and Destination databases are healthy.
- **Action:** Executed `php src/SyncEngine.php` with 100 sample records.
- **Expected Result:** 100% of records should appear in `accounting_dest_db` with status `SUCCESS`.
- **Actual Result:** **PASSED**. 100/100 records synced utilizing memory-safe batch transactions.

---

## Test Suite 2: Destination Outage (Tier 2 Fallback)
**Scenario:** The destination accounting database becomes unreachable mid-process.
- **Methodology:** Injected a manual `PDOException` into the sync loop.
- **Action:** Executed sync engine.
- **Expected Result:** Engine should catch the failure and persist the data into the local `sync_dead_letter_queue` SQL table.
- **Actual Result:** **PASSED**. Records were successfully serialized to JSON and stored in the DLQ.
- **Recovery Check:** Fixed connection and ran `php src/RecoveryWorker.php`. All records moved to destination with status `RECOVERED`.

---

## Test Suite 3: Total Infrastructure Collapse (Tier 3 Fallback)
**Scenario:** Both Destination and Source SQL services are unavailable (Simulating `MySQL server has gone away`).
- **Methodology:** Severed the active MySQL session during execution.
- **Action:** Executed sync engine.
- **Expected Result:** System should recognize SQL is unavailable and fall back to the physical local filesystem.
- **Actual Result:** **PASSED**. System created a `/logs` directory and persisted failed payloads as timestamped `.json` log files.
- **Recovery Check:** Restored SQL service and ran `php src/EmergencyIngestor.php`. Logs were streamed memory-safely, data was synced in batches, and files were archived to `.processed`.

---

## Test Suite 4: State Tracking & Idempotency Test
**Scenario:** The script is run multiple times consecutively.
- **Methodology:** Ran `SyncEngine.php` immediately after a successful run.
- **Action:** Executed sync engine.
- **Expected Result:** The engine should report "0 records to sync." It should not attempt to pull historical data.
- **Actual Result:** **PASSED**. The engine successfully established a `MAX(remote_id)` watermark and only processed new records. Idempotency (`ON DUPLICATE KEY UPDATE`) caught any manually duplicated edge-cases.

---

## Test Suite 5: Concurrency Safety (Cron Collision Test)
**Scenario:** A cron job triggers the script while a previous sync is still processing a massive payload.
- **Methodology:** Added an artificial `sleep(10)` delay to the sync loop. Ran the script in two separate terminal windows simultaneously.
- **Action:** Executed second sync engine instance.
- **Expected Result:** The second instance should immediately exit, recognizing the active lock.
- **Actual Result:** **PASSED**. System output `Sync is already running. Exiting to prevent overlap.`

---

## Final Verdict
The Sentinel Sync Engine successfully handled all injected faults across the networking, database, and process-scheduling layers. The system is verified as highly efficient, memory-safe, **Fault-Tolerant**, and **Mission-Critical Ready**.