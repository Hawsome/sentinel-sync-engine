<?php

final class SyncEngineTest extends DatabaseTestCase
{
    public function testSyncsRowsWithExactAmountsAndAdvancesCursor(): void
    {
        $this->insertDonation('a@example.com', 5000);
        $this->insertDonation('b@example.com', 12345);
        $last = $this->insertDonation('c@example.com', 1);

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame('COMPLETED', $stats['status']);
        $this->assertSame(3, $stats['synced']);
        $this->assertSame($last, $this->cursor());
        $amounts = $this->column($this->dest, 'SELECT amount_naira FROM synced_donations ORDER BY remote_id');
        $this->assertSame(['50.00', '123.45', '0.01'], array_map('strval', $amounts));
    }

    public function testSecondRunIsANoOp(): void
    {
        $this->insertDonation('a@example.com', 5000);
        run_sync($this->source, $this->dest, $this->logDir, 0);

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame(0, $stats['synced']);
        $this->assertSame(1, $this->rowCount($this->dest, 'synced_donations'));
    }

    /**
     * One INSERT stamps every row with the same updated_at, so every page
     * boundary falls inside a tie. Only the id half of the cursor gets past it;
     * a timestamp-only cursor would either loop on page one or skip the rest.
     */
    public function testPagesThroughTiesOnUpdatedAt(): void
    {
        $values = implode(',', array_fill(0, BATCH_SIZE * 2 + 7, "('bulk@example.com', 100)"));
        $this->source->exec("INSERT INTO wp_donations (donor_email, amount_kobo) VALUES $values");
        $this->assertSame(1, (int)$this->scalar($this->source, 'SELECT COUNT(DISTINCT updated_at) FROM wp_donations'));

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame(BATCH_SIZE * 2 + 7, $stats['synced']);
        $this->assertSame(BATCH_SIZE * 2 + 7, $this->rowCount($this->dest, 'synced_donations'));
        $this->assertSame((int)$this->scalar($this->source, 'SELECT MAX(id) FROM wp_donations'), $this->cursor());
    }

    /** Regression: the id-only cursor never saw changes to rows it had already passed. */
    public function testRefundPropagatesToDestination(): void
    {
        $id = $this->insertDonation('a@example.com', 5000);
        run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->source->exec("UPDATE wp_donations SET status = 'refunded' WHERE id = $id");
        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame(1, $stats['synced']);
        $this->assertSame(
            ['donation_status' => 'refunded', 'sync_status' => 'UPDATED'],
            $this->row($this->dest, "SELECT donation_status, sync_status FROM synced_donations WHERE remote_id = $id")
        );
    }

    public function testEditToAmountPropagates(): void
    {
        $id = $this->insertDonation('a@example.com', 5000);
        run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->source->exec("UPDATE wp_donations SET amount_kobo = 4500 WHERE id = $id");
        run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame('45.00', (string)$this->scalar($this->dest, "SELECT amount_naira FROM synced_donations WHERE remote_id = $id"));
    }

    /** Rows newer than the lag window may belong to transactions that have not committed yet. */
    public function testLagWindowHoldsBackRecentRows(): void
    {
        $this->insertDonation('a@example.com', 5000);
        $this->source->exec("INSERT INTO wp_donations (donor_email, amount_kobo, updated_at)
                             VALUES ('old@example.com', 100, NOW(6) - INTERVAL 2 HOUR)");

        $stats = run_sync($this->source, $this->dest, $this->logDir, 3600);

        $this->assertSame(1, $stats['synced'], 'only the row older than the lag is read');
        $this->assertSame(['old@example.com'], $this->column($this->dest, 'SELECT email FROM synced_donations'));
    }

    public function testInvalidStatusIsQuarantined(): void
    {
        $this->source->exec("INSERT INTO wp_donations (donor_email, amount_kobo, status) VALUES ('a@example.com', 100, 'chargeback')");

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame(0, $stats['synced']);
        $this->assertSame(1, $this->rowCount($this->source, 'sync_dead_letter_queue', 'quarantined = 1'));
    }

    /** Regression: invalid rows used to be logged and then lost behind the cursor. */
    public function testInvalidRowIsQuarantinedNotLost(): void
    {
        $bad  = $this->insertDonation('not-an-email', 5000);
        $good = $this->insertDonation('ok@example.com', 5000);

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame(1, $stats['synced']);
        $this->assertSame(1, $stats['dlq']);
        $this->assertSame($good, $this->cursor());
        $dlq = $this->row($this->source, 'SELECT payload, quarantined FROM sync_dead_letter_queue');
        $this->assertSame(1, (int)$dlq['quarantined']);
        $this->assertSame($bad, json_decode((string)$dlq['payload'], true)['data']['id']);
    }

    public function testRowLevelDestinationFailureGoesToDlqForRetry(): void
    {
        $this->rejectEmailAtDestination('reject@example.com');
        $this->insertDonation('reject@example.com', 5000);
        $this->insertDonation('ok@example.com', 5000);

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame('COMPLETED', $stats['status']);
        $this->assertSame(1, $stats['dlq']);
        $this->assertSame(1, $this->rowCount($this->source, 'sync_dead_letter_queue', 'quarantined = 0'));
    }

    /** A deadlock or dropped connection must roll back the whole page and hold the cursor. */
    public function testTransientFailureAbortsWithoutAdvancingCursor(): void
    {
        $this->insertDonation('first@example.com', 5000);
        $this->insertDonation('deadlock@example.com', 5000);
        $this->rejectEmailAtDestination('deadlock@example.com', '40001');

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame('ABORTED', $stats['status']);
        $this->assertSame(0, $this->cursor());
        $this->assertSame(0, $this->rowCount($this->dest, 'synced_donations'), 'first row must be rolled back with its page');
        $this->assertSame(0, $this->rowCount($this->source, 'sync_dead_letter_queue'), 'nothing parked for an aborted page');
    }

    public function testFallsBackToEmergencyLogWhenDlqIsDown(): void
    {
        $this->rejectEmailAtDestination('reject@example.com');
        $this->breakDeadLetterQueue();
        $id = $this->insertDonation('reject@example.com', 5000);

        $stats = run_sync($this->source, $this->dest, $this->logDir, 0);

        $this->assertSame(1, $stats['emergency']);
        $files = glob($this->logDir . '/emergency_sync_*.json') ?: [];
        $this->assertCount(1, $files);
        $line = json_decode(trim((string)file_get_contents($files[0])), true);
        $this->assertSame($id, $line['data']['id']);
    }

    public function testCursorHoldsWhenNoTierCanParkTheRow(): void
    {
        $this->rejectEmailAtDestination('reject@example.com');
        $this->breakDeadLetterQueue();
        $this->insertDonation('reject@example.com', 5000);
        $missing_dir = $this->logDir . '/missing';
        touch($missing_dir); // a file where the directory should be: mkdir and writes fail

        $stats = @run_sync($this->source, $this->dest, $missing_dir, 0);
        unlink($missing_dir);

        $this->assertSame('ABORTED', $stats['status']);
        $this->assertSame(0, $this->cursor());
    }

    public function testAuditRowRecordsAbortedRuns(): void
    {
        write_audit($this->dest, [
            'synced' => 0, 'failed' => 0, 'dlq' => 0, 'emergency' => 0,
            'cursor' => 0, 'cursor_at' => null, 'status' => 'ABORTED', 'error' => 'boom',
        ], 1.234);

        $row = $this->row($this->dest, 'SELECT status, error_message FROM sync_audit_log');
        $this->assertSame(['status' => 'ABORTED', 'error_message' => 'boom'], $row);
    }
}
