<?php
// Tier 1: source -> destination, one keyset page at a time.
//
// Each page is a single destination transaction that covers both the rows and
// the cursor, so a page either lands completely or not at all. A crash at row
// 90,000 costs one page of rework, not a full restart.

const SYNC_ENGINE_NAME = 'SyncEngine';

/**
 * @return array{synced:int, failed:int, dlq:int, emergency:int, cursor:int, status:string, error:?string}
 */
function run_sync(PDO $source, PDO $dest, string $emergency_dir): array {
    $stats = [
        'synced' => 0, 'failed' => 0, 'dlq' => 0, 'emergency' => 0,
        'cursor' => 0, 'status' => 'COMPLETED', 'error' => null,
    ];

    try {
        $dest->prepare("INSERT IGNORE INTO sync_cursor (engine, last_synced_id) VALUES (:engine, 0)")
             ->execute([':engine' => SYNC_ENGINE_NAME]);

        $cursor_stmt  = $dest->prepare("SELECT last_synced_id FROM sync_cursor WHERE engine = :engine FOR UPDATE");
        $advance_stmt = $dest->prepare("UPDATE sync_cursor SET last_synced_id = :id WHERE engine = :engine");
        $upsert       = prepare_upsert($dest, 'SUCCESS', 'UPDATED');

        // LIMIT keeps memory bounded. pdo_mysql buffers whole result sets by
        // default, so "WHERE id > x" without a LIMIT would load every new row.
        $page_stmt = $source->prepare(
            "SELECT id, donor_email, amount_kobo, created_at
             FROM wp_donations
             WHERE id > :last_id
             ORDER BY id ASC
             LIMIT :page_size"
        );
        $page_stmt->bindValue(':page_size', BATCH_SIZE, PDO::PARAM_INT);
    } catch (PDOException $e) {
        $stats['status'] = 'ABORTED';
        $stats['error']  = $e->getMessage();
        log_msg('CRITICAL', "Sync could not start: " . $e->getMessage());
        return $stats;
    }

    while (true) {
        try {
            $dest->beginTransaction();

            // FOR UPDATE holds the cursor row until commit. A second instance on
            // another host blocks here, then reads the advanced cursor.
            $cursor_stmt->execute([':engine' => SYNC_ENGINE_NAME]);
            $last_id = (int)$cursor_stmt->fetchColumn();
            $cursor_stmt->closeCursor();
            $stats['cursor'] = $last_id;

            $page_stmt->bindValue(':last_id', $last_id, PDO::PARAM_INT);
            $page_stmt->execute();
            $page = $page_stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($page === []) {
                $dest->commit();
                break;
            }

            $synced = 0;
            $dead   = [];

            foreach ($page as $row) {
                $validation_error = validate_row($row);
                if ($validation_error !== null) {
                    // Bad data will not fix itself on retry: quarantine for a human.
                    $dead[] = ['row' => $row, 'error' => "Validation: $validation_error", 'quarantine' => true];
                    continue;
                }
                try {
                    upsert_donation($upsert, $row);
                    $synced++;
                } catch (PDOException $e) {
                    if (is_transient_db_error($e)) {
                        throw $e; // the transaction may already be gone; abandon the page
                    }
                    $dead[] = ['row' => $row, 'error' => $e->getMessage(), 'quarantine' => false];
                }
            }

            // Record every failure BEFORE the cursor moves past it. If parking
            // fails entirely, this throws and the page is rolled back and retried.
            foreach ($dead as $item) {
                $where = park_dead_letter($source, $emergency_dir, $item['row'], $item['error'], $item['quarantine']);
                $stats[$where]++;
                $stats['failed']++;
                log_msg('WARN', "ID {$item['row']['id']} routed to " . strtoupper($where) . ": {$item['error']}");
            }

            $new_cursor = (int)$page[count($page) - 1]['id'];
            $advance_stmt->execute([':id' => $new_cursor, ':engine' => SYNC_ENGINE_NAME]);
            $dest->commit();

            $stats['synced'] += $synced;
            $stats['cursor']  = $new_cursor;
            log_msg('INFO', "Committed page: synced=$synced failed=" . count($dead) . " cursor=$new_cursor");

        } catch (Throwable $e) {
            if ($dest->inTransaction()) {
                try {
                    $dest->rollBack();
                } catch (PDOException) {
                    // Connection already dead; the server rolled back for us.
                }
            }
            $stats['status'] = 'ABORTED';
            $stats['error']  = $e->getMessage();
            log_msg('CRITICAL', "Sync aborted, cursor held at {$stats['cursor']}: " . $e->getMessage());
            break;
        }
    }

    return $stats;
}

/**
 * Tier 2, falling back to tier 3. Returns which tier took the record.
 * Throws if neither can, so the caller does not advance past the row.
 *
 * @param array<string, mixed> $row
 * @return 'dlq'|'emergency'
 */
function park_dead_letter(PDO $source, string $emergency_dir, array $row, string $error, bool $quarantine): string {
    $payload = json_encode(
        ['data' => $row, 'error' => $error, 'timestamp' => date('Y-m-d H:i:s')],
        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
    );

    try {
        $source->prepare(
            "INSERT INTO sync_dead_letter_queue (payload, error_message, quarantined)
             VALUES (:payload, :error, :quarantined)"
        )->execute([':payload' => $payload, ':error' => $error, ':quarantined' => (int)$quarantine]);
        return 'dlq';
    } catch (PDOException $dlq_error) {
        if (!is_dir($emergency_dir)) {
            mkdir($emergency_dir, 0750, true);
        }
        $file = $emergency_dir . '/emergency_sync_' . date('Y-m-d') . '.json';
        if (file_put_contents($file, $payload . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(
                "ID {$row['id']}: DLQ failed ({$dlq_error->getMessage()}) and emergency log write to $file failed"
            );
        }
        return 'emergency';
    }
}

/**
 * Best effort: a failed run is exactly the run worth auditing, but if the
 * destination is down there is nowhere to write it except the process log.
 *
 * @param array{synced:int, failed:int, dlq:int, emergency:int, cursor:int, status:string, error:?string} $stats
 */
function write_audit(PDO $dest, array $stats, float $duration): void {
    try {
        $dest->prepare(
            "INSERT INTO sync_audit_log
                 (engine, status, rows_synced, rows_failed, rows_dlq, rows_emergency,
                  duration_seconds, cursor_end, error_message)
             VALUES (:engine, :status, :synced, :failed, :dlq, :emergency, :duration, :cursor, :error)"
        )->execute([
            ':engine'    => SYNC_ENGINE_NAME,
            ':status'    => $stats['status'],
            ':synced'    => $stats['synced'],
            ':failed'    => $stats['failed'],
            ':dlq'       => $stats['dlq'],
            ':emergency' => $stats['emergency'],
            ':duration'  => round($duration, 2),
            ':cursor'    => $stats['cursor'],
            ':error'     => $stats['error'],
        ]);
    } catch (PDOException $e) {
        log_msg('ERROR', "Could not write audit row: " . $e->getMessage());
    }
}
