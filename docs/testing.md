# Testing

Every resilience claim in the README has a test that tries to break it. The suite runs on every push and pull request in GitHub Actions against a real MySQL 8.4 server.

## Running the Tests

```bash
composer install
composer test        # unit tests; integration tests skip without a database
composer analyse     # PHPStan, level 8
```

To run the integration suite locally, point it at a MySQL server where the user can create and drop databases. It builds `sentinel_test_source` and `sentinel_test_dest` from the real scripts in `sql/` and drops them on the next run.

```bash
SENTINEL_TEST_DB_HOST=127.0.0.1 SENTINEL_TEST_DB_USER=root SENTINEL_TEST_DB_PASS=secret composer test
```

CI runs with `--fail-on-skipped`, so a misconfigured database fails the build instead of quietly skipping half the suite.

## How Failures Are Simulated

The tests do not mock PDO. They make MySQL itself fail, using triggers:

- **Row-level rejection:** a `BEFORE INSERT` trigger on `synced_donations` raises `SIGNAL SQLSTATE '45000'` for one email address.
- **Transient failure:** the same trigger raises `SQLSTATE '40001'`, the deadlock state, which exercises the abort-and-roll-back path.
- **DLQ outage:** a trigger on `sync_dead_letter_queue` rejects every insert, forcing the emergency log tier.
- **Disk failure:** the emergency log directory is replaced by a plain file, so every write fails.

## What Is Covered

### Unit (`tests/Unit`)
| Behaviour | Test |
|-----------|------|
| Kobo to naira is exact, up to the `INT UNSIGNED` ceiling | `testKoboToNairaIsExact` |
| Validation rejects fractional, exponent, negative and oversized amounts, bad or oversized emails, unknown statuses and malformed timestamps | `testInvalidRowsAreRejected` |
| Deadlocks and dropped connections count as transient; constraint errors do not | `testTransientErrorClassification` |
| The lock is exclusive, and the lock file survives release | `testLockIsExclusiveAndFileSurvivesRelease` |

### Sync engine (`tests/Integration/SyncEngineTest.php`)
| Behaviour | Test |
|-----------|------|
| Rows land with exact amounts; cursor advances | `testSyncsRowsWithExactAmountsAndAdvancesCursor` |
| A second run does nothing | `testSecondRunIsANoOp` |
| Pages cross ties on `updated_at` without looping or skipping | `testPagesThroughTiesOnUpdatedAt` |
| A refund to an already-synced row reaches the destination | `testRefundPropagatesToDestination` |
| An edited amount reaches the destination | `testEditToAmountPropagates` |
| Rows inside the lag window wait; older rows sync | `testLagWindowHoldsBackRecentRows` |
| An unknown status is quarantined | `testInvalidStatusIsQuarantined` |
| An invalid row is quarantined in the DLQ, not lost behind the cursor | `testInvalidRowIsQuarantinedNotLost` |
| A row-level destination failure goes to the DLQ for retry | `testRowLevelDestinationFailureGoesToDlqForRetry` |
| A transient failure rolls back the page and holds the cursor | `testTransientFailureAbortsWithoutAdvancingCursor` |
| With the DLQ down, the row goes to the emergency log | `testFallsBackToEmergencyLogWhenDlqIsDown` |
| With every tier down, the cursor holds | `testCursorHoldsWhenNoTierCanParkTheRow` |
| Aborted runs are audited | `testAuditRowRecordsAbortedRuns` |

### Recovery and ingest (`tests/Integration/RecoveryAndIngestTest.php`)
| Behaviour | Test |
|-----------|------|
| Dead letters replay and are deleted | `testRecoveryReplaysAndDeletesDeadLetters` |
| Row failures count attempts, then quarantine | `testRowFailureCountsAttemptsThenQuarantines` |
| An outage does not burn attempts | `testTransientFailureDoesNotBurnAttempts` |
| Malformed payloads are quarantined | `testMalformedPayloadIsQuarantined` |
| An older snapshot cannot overwrite a newer row | `testStaleDeadLetterDoesNotOverwriteNewerRow` |
| A newer snapshot does overwrite | `testNewerDeadLetterDoesOverwrite` |
| An unversioned (legacy) snapshot cannot overwrite a versioned row | `testUnversionedDeadLetterDoesNotOverwriteVersionedRow` |
| A versioned row replaces an unversioned one | `testVersionedRowReplacesUnversionedRow` |
| Valid lines ingest; bad lines go to `.rejected` | `testIngestLoadsValidLinesAndRejectsBadOnes` |
| An orphaned `.inprogress` file from a crash is picked up | `testIngestPicksUpOrphanedInProgressFile` |
| A transient failure leaves the file for the next run | `testIngestLeavesFileInProgressOnTransientFailure` |

## Not Covered

- A real dropped TCP connection. `SQLSTATE 40001` exercises the same code path, but the driver's behaviour when the socket dies is not tested.
- Two engines on different hosts contending for the cursor row lock.
- A source transaction that outlives the lag window. That is a documented limit, not a tested guarantee.
- The upgrade scripts in `sql/migrations/`. CI builds fresh schemas only.
- The limitations listed in [architecture.md](architecture.md#known-limitations).
