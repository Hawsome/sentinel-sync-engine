<?php

final class RecoveryAndIngestTest extends DatabaseTestCase
{
    private function parkRow(int $id, string $email, int $kobo): void
    {
        $payload = json_encode(['data' => ['id' => $id, 'donor_email' => $email, 'amount_kobo' => $kobo]]);
        $this->source->prepare('INSERT INTO sync_dead_letter_queue (payload, error_message) VALUES (:p, :e)')
            ->execute([':p' => $payload, ':e' => 'seeded by test']);
    }

    public function testRecoveryReplaysAndDeletesDeadLetters(): void
    {
        $this->parkRow(42, 'a@example.com', 5000);

        $stats = run_recovery($this->source, $this->dest);

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(0, $this->rowCount($this->source, 'sync_dead_letter_queue'));
        $this->assertSame('RECOVERED', $this->scalar($this->dest, 'SELECT sync_status FROM synced_donations WHERE remote_id = 42'));
    }

    public function testRowFailureCountsAttemptsThenQuarantines(): void
    {
        $this->rejectEmailAtDestination('reject@example.com');
        $this->parkRow(42, 'reject@example.com', 5000);

        for ($i = 0; $i < MAX_ATTEMPTS; $i++) {
            run_recovery($this->source, $this->dest);
        }

        $row = $this->row($this->source, 'SELECT attempts, quarantined FROM sync_dead_letter_queue');
        $this->assertSame([MAX_ATTEMPTS, 1], [(int)$row['attempts'], (int)$row['quarantined']]);
    }

    /** Regression: an outage used to burn attempts and quarantine healthy records. */
    public function testTransientFailureDoesNotBurnAttempts(): void
    {
        $this->rejectEmailAtDestination('a@example.com', '40001');
        $this->parkRow(42, 'a@example.com', 5000);

        for ($i = 0; $i < MAX_ATTEMPTS + 1; $i++) {
            $stats = run_recovery($this->source, $this->dest);
            $this->assertSame('ABORTED', $stats['status']);
        }

        $row = $this->row($this->source, 'SELECT attempts, quarantined FROM sync_dead_letter_queue');
        $this->assertSame([0, 0], [(int)$row['attempts'], (int)$row['quarantined']]);
    }

    public function testMalformedPayloadIsQuarantined(): void
    {
        $this->source->exec("INSERT INTO sync_dead_letter_queue (payload) VALUES ('{\"data\": {\"id\": 1}}')");

        $stats = run_recovery($this->source, $this->dest);

        $this->assertSame(1, $stats['quarantined']);
        $this->assertSame(1, $this->rowCount($this->source, 'sync_dead_letter_queue', 'quarantined = 1'));
    }

    public function testIngestLoadsValidLinesAndRejectsBadOnes(): void
    {
        $file = $this->logDir . '/emergency_sync_2026-01-01.json';
        file_put_contents($file, implode(PHP_EOL, [
            json_encode(['data' => ['id' => 1, 'donor_email' => 'a@example.com', 'amount_kobo' => 100]]),
            'this is not json',
            json_encode(['data' => ['id' => 2, 'donor_email' => 'bad', 'amount_kobo' => 100]]),
            json_encode(['data' => ['id' => 3, 'donor_email' => 'c@example.com', 'amount_kobo' => 300]]),
        ]) . PHP_EOL);

        $stats = run_ingest($this->dest, $this->logDir);

        $this->assertSame(['files' => 1, 'ingested' => 2, 'rejected' => 2, 'status' => 'COMPLETED', 'error' => null], $stats);
        $this->assertFileExists($file . '.processed');
        $this->assertCount(2, file($file . '.rejected', FILE_IGNORE_NEW_LINES) ?: []);
    }

    /** Regression: a crash used to strand .inprogress files forever. */
    public function testIngestPicksUpOrphanedInProgressFile(): void
    {
        $orphan = $this->logDir . '/emergency_sync_2026-01-01.json.inprogress';
        file_put_contents($orphan, json_encode(['data' => ['id' => 9, 'donor_email' => 'a@example.com', 'amount_kobo' => 100]]) . PHP_EOL);

        $stats = run_ingest($this->dest, $this->logDir);

        $this->assertSame(1, $stats['ingested']);
        $this->assertFileExists($this->logDir . '/emergency_sync_2026-01-01.json.processed');
        $this->assertFileDoesNotExist($this->logDir . '/emergency_sync_2026-01-01.json.rejected');
    }

    public function testIngestLeavesFileInProgressOnTransientFailure(): void
    {
        $this->rejectEmailAtDestination('a@example.com', '40001');
        $file = $this->logDir . '/emergency_sync_2026-01-01.json';
        file_put_contents($file, json_encode(['data' => ['id' => 9, 'donor_email' => 'a@example.com', 'amount_kobo' => 100]]) . PHP_EOL);

        $stats = run_ingest($this->dest, $this->logDir);

        $this->assertSame('ABORTED', $stats['status']);
        $this->assertFileExists($file . '.inprogress', 'next run must find it');
    }
}
