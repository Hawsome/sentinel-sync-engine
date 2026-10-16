# System Architecture: Sentinel Sync Engine

## Overview
Sentinel moves donation records from a CMS database (source) into an accounting database (destination). It is designed around one rule: **the cursor never moves past a row until that row is either in the destination or recorded somewhere a human or a worker will find it.**

## Code Layout

| File | Role |
|------|------|
| `src/helpers.php` | Validation, money conversion, locking, error classification, shared upsert |
| `src/lib/sync.php` | Tier 1: `run_sync()` pages from source to destination |
| `src/lib/recovery.php` | Tier 2 drain: `run_recovery()` replays the DLQ |
| `src/lib/ingest.php` | Tier 3 drain: `run_ingest()` replays emergency log files |
| `src/SyncEngine.php`, `RecoveryWorker.php`, `EmergencyIngestor.php` | Thin cron entry points: lock, connect, run, set exit code |

The logic lives in functions that take PDO connections as arguments. That is what makes the integration tests possible: they hand the functions throwaway databases and assert on the results.

## Core Design Patterns

### 1. Keyset Pagination on `(updated_at, id)`, One Transaction per Page
The engine reads the source in pages, ordered by when each row last changed:

```sql
SELECT id, donor_email, amount_kobo, status, created_at, updated_at
FROM wp_donations
WHERE updated_at >= :at
  AND (updated_at > :at OR id > :last_id)        -- (updated_at, id) > (:at, :last_id)
  AND updated_at <= NOW(6) - INTERVAL :lag SECOND
ORDER BY updated_at, id
LIMIT 500
```

**Why `updated_at` and not `id`?** An `id` cursor only ever moves forward over new rows. Once it passes a donation, an edit or a refund to that donation is never seen. `updated_at` (maintained by MySQL via `ON UPDATE CURRENT_TIMESTAMP(6)`) moves a changed row to the front of the queue, so inserts and updates travel the same path.

**Why the `id` as well?** Timestamps tie. A single `INSERT` or `UPDATE` touching 1,000 rows stamps them all with the same microsecond. With a timestamp-only cursor, a page boundary inside that tie either re-reads the same page forever (`>=`) or skips the rest of the tie (`>`). The `id` orders rows within a tie, so the pair is unique.

**Why the odd `WHERE`?** It is the row comparison `(updated_at, id) > (:at, :last_id)`, spelled so the `(updated_at, id)` index serves it as one range scan starting at `:at`.

**Why not one big query?** `pdo_mysql` buffers result sets by default, so `execute()` on an unbounded `SELECT` pulls every matching row into PHP memory before the first `fetch()`. Unbuffered mode avoids that, but blocks every other query on the connection until the result is drained, and the DLQ insert shares that connection. A `LIMIT` caps memory at one page.

**Why keyset and not `OFFSET`?** `OFFSET n` makes MySQL walk and discard `n` rows on every page. A keyset `WHERE` seeks straight to the cursor in the index and costs the same on page 1 and page 10,000.

Each page runs inside one destination transaction:

1. `SELECT last_updated_at, last_synced_id ... FOR UPDATE` (locks the cursor row)
2. Fetch the page from the source
3. Upsert each valid row
4. Park every failed row in the DLQ (or emergency log)
5. Advance the cursor to the page's last `(updated_at, id)`
6. Commit

Rows and cursor commit together, so a crash costs at most one page of rework. Because the cursor row is locked until commit, a second instance on another host blocks at step 1 and then reads the advanced cursor. The file lock stops overlap on one host; the row lock covers the rest.

**The lag window.** A row's `updated_at` is stamped when its statement runs, but other connections only see the row when its transaction commits. Picture a CMS transaction that stamps a row at 12:00:00, then takes five seconds to commit. Meanwhile the sync reads a row stamped 12:00:02 and moves the cursor there. When the 12:00:00 row finally appears, it is behind the cursor and will never be read. The old `id` cursor had exactly the same hole, because InnoDB hands out auto-increment IDs before commit.

The engine therefore only reads rows whose `updated_at` is at least `SYNC_LAG_SECONDS` (default 60) in the past, measured by the source server's own clock (`NOW(6)`), so clock drift between hosts is irrelevant. The rule: **the lag must exceed the longest write transaction on the source.** The cost is that a donation takes up to a minute longer to reach accounting.

**Time zones.** `updated_at` is a `TIMESTAMP`, stored as UTC, not a `DATETIME`, which stores whatever wall-clock time the writer's session used. MySQL renders `TIMESTAMP` values in the session time zone, though, and the cursor is read on the destination and compared on the source. `connect()` pins every session to `+00:00`, so both servers speak the same time whatever their defaults.

### 2. Failure Classification
Not every database error means the same thing. `is_transient_db_error()` separates them:

| Kind | Examples | Response |
|------|----------|----------|
| **Transient** | Deadlock (1213), lock wait timeout (1205), server gone away (2006), lost connection (2013), SQLSTATE `08xxx`, `40001` | Roll back the page, hold the cursor, exit non-zero. The next run retries the whole page. |
| **Row-level** | Constraint violation, data too long, a trigger rejecting the row | Park the row in the DLQ, carry on with the page. |

The distinction matters because MySQL rolls back the **entire** transaction on a deadlock. Treating a deadlock as a row failure would silently lose every earlier row in the page while counting them as synced.

Aborting on the first transient error also acts as a circuit breaker. If the destination dies mid-run, the engine stops at once instead of timing out on every remaining row and flooding the DLQ.

### 3. The Dead Letter Queue (DLQ)
A failed row is serialised as JSON into `sync_dead_letter_queue` on the source database:

```json
{
  "data": { "id": 42, "donor_email": "...", "amount_kobo": 5000, "status": "completed",
            "created_at": "...", "updated_at": "2026-06-29 11:59:00.123456" },
  "error": "SQLSTATE[...] ...",
  "timestamp": "2026-06-29 12:00:00"
}
```

**Ordering:** dead letters are written *before* the destination commit. If the commit then fails, the page is retried and the rows are parked a second time. Duplicate dead letters are harmless because recovery is an upsert. The reverse order (commit, then park) would let a crash advance the cursor past a row recorded nowhere.

**Validation failures** go to the DLQ with `quarantined = 1`. Bad data will not fix itself on retry, so it waits for a human instead of being logged and lost.

### 4. Recovery Without Self-Harm
`RecoveryWorker` replays up to 100 dead letters per run (`RECOVERY_BATCH_LIMIT`).

- A **row-level** failure increments `attempts` and stores the latest error. At `MAX_ATTEMPTS` (5) the record is quarantined.
- A **transient** failure stops the run and touches nothing. Otherwise, five recovery runs during one outage would quarantine every healthy record in the queue.

The upsert (destination) and the delete (source) cannot share a transaction. If the delete fails, the next run replays the row, which the upsert makes harmless.

### 5. Emergency Log (Tier 3)
If the DLQ insert also fails, the payload is appended to `logs/emergency_sync_YYYY-MM-DD.json` with `LOCK_EX`. If that write fails too, the page is rolled back and the cursor holds. Nothing advances past an unrecorded row.

`EmergencyIngestor` file lifecycle:

```
x.json  ->  x.json.inprogress  ->  x.json.processed
                                   x.json.rejected   (bad lines, with reasons)
```

The ingestor holds its lock before it scans, so any `.inprogress` file it finds is an orphan from a crashed run. It replays orphans from the top; the upsert makes that safe, and the `.rejected` file is regenerated rather than appended.

### 6. Idempotency and Freshness (`remote_id` + `source_updated_at`)
`synced_donations.remote_id` is `UNIQUE`, so replaying a page, a dead letter or a log file can never create a duplicate.

Once source rows can change, "never a duplicate" is not enough. A dead letter is a snapshot: replayed an hour late, it could overwrite the refund that synced in the meantime. So the destination stores the source version it reflects, `source_updated_at`, and every column update is guarded:

```sql
ON DUPLICATE KEY UPDATE
    email             = IF(fresh, VALUES(email), email),
    ...
    source_updated_at = IF(fresh, VALUES(source_updated_at), source_updated_at)

-- fresh = (source_updated_at IS NULL OR VALUES(source_updated_at) >= source_updated_at)
```

Older snapshots become no-ops. Recovery still deletes them from the DLQ: they are resolved, not failed.

Two details are worth knowing:

- **Unversioned rows** (dead letters written before versioning existed) carry `NULL`. In SQL, `NULL >= x` is `NULL`, which `IF()` treats as false, so an unversioned snapshot never overwrites a versioned row. It still fills an empty slot, and a versioned row always replaces it.
- **Assignment order.** MySQL documents single-table assignments as "generally" left to right, with later ones seeing earlier new values. An unguarded `source_updated_at = VALUES(...)` placed first would make every later `IF()` true. Because every assignment here carries the same guard, the statement is correct in any order. It does not depend on behaviour the manual will not promise.

### 7. Financial Precision
- **Source:** integer kobo (`INT UNSIGNED`)
- **Transit:** `kobo_to_naira()` formats with `intdiv()` and `%`, producing an exact string such as `"123.45"`. No float touches the amount.
- **Destination:** `DECIMAL(10,2) UNSIGNED`

### 8. Concurrency Locks
Each script takes `flock(LOCK_EX | LOCK_NB)` on its own file in `logs/`. The OS releases the lock when the process exits, however it exits.

The lock file is **never deleted**. Deleting it after unlocking opens a race: a waiting process locks the old file while a newcomer creates and locks a new one at the same path, and both run.

### 9. Observability
- Timestamped, levelled log lines (`INFO`, `WARN`, `ERROR`, `CRITICAL`).
- **Exit codes:** `0` for a clean run or "already running"; `1` for any connection failure or aborted run. Cron wrappers and monitors can rely on it.
- Every sync run, including aborted ones, writes a row to `sync_audit_log` with `status` and `error_message`. If the destination itself is down there is nowhere to write it, so that case is logged instead.

### 10. Configuration
Credentials load via `vlucas/phpdotenv` with no hardcoded fallbacks. `DB_SOURCE_HOST` and `DB_DEST_HOST` optionally override `DB_HOST`. The DLQ only earns its keep when the two databases can fail independently, which usually means separate servers.

## Known Limitations

- **Hard deletes are invisible.** A deleted row has no `updated_at` to find. Refunds and cancellations must be status changes (`status = 'refunded'`), which is how accounting systems treat them anyway.
- **Every writer must bump `updated_at`.** `ON UPDATE CURRENT_TIMESTAMP(6)` covers ordinary `UPDATE`s, but a writer that sets `updated_at` explicitly to an older value, or a bulk load that bypasses it, can hide a change behind the cursor.
- **The lag is a bound, not a proof.** A source write transaction that outlives `SYNC_LAG_SECONDS` can still commit a row behind the cursor. Size the lag to the source's real transaction lengths. The airtight alternative is reading the binlog (change data capture), which is a different tool.
- **`TIMESTAMP` ends in 2038.** MySQL's `TIMESTAMP` range stops at `2038-01-19 03:14:07` UTC. Before then, move `updated_at` to `DATETIME(6)` written in UTC by every client.
- **Emergency log append race.** A sync process that opened today's emergency file just before the ingestor renamed it can append one line after the ingestor finishes reading. The line survives in `.processed` but is not ingested.
