<?php
require_once 'config.php';
require_once 'helpers.php';

// --- Atomic file lock ---
if (!file_exists(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0750, true);
}
$lock_file   = __DIR__ . '/../logs/sync.lock';
$lock_handle = fopen($lock_file, 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    die("Sync is already running. Exiting to prevent overlap.\n");
}
ftruncate($lock_handle, 0);
fwrite($lock_handle, (string)getmypid());

$run_start     = microtime(true);
$total_synced  = 0;
$total_failed  = 0;
$total_dlq     = 0;
$total_emergency = 0;

try {
    $source_pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_SOURCE . ";charset=utf8mb4",
        DB_USER, DB_PASS
    );
    $source_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dest_pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DEST . ";charset=utf8mb4",
        DB_USER, DB_PASS
    );
    $dest_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Persistent cursor read inside a transaction so FOR UPDATE actually locks ---
    $dest_pdo->beginTransaction();
    $cursor_row = $dest_pdo->query(
        "SELECT last_synced_id FROM sync_cursor WHERE engine = 'SyncEngine' FOR UPDATE"
    )->fetch(PDO::FETCH_ASSOC);
    $last_id    = $cursor_row ? (int)$cursor_row['last_synced_id'] : 0;
    $highest_id = $last_id;

    log_msg('INFO', "Starting sync from ID > $last_id");

    // Explicit column list prevents future source-schema columns leaking into dest/DLQ
    $query = $source_pdo->prepare(
        "SELECT id, donor_email, amount_kobo, created_at
         FROM wp_donations
         WHERE id > :last_id
         ORDER BY id ASC"
    );
    $query->execute([':last_id' => $last_id]);

    $insert_sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status)
                   VALUES (:remote_id, :email, :amount, 'SUCCESS')
                   ON DUPLICATE KEY UPDATE
                       email        = VALUES(email),
                       amount_naira = VALUES(amount_naira),
                       sync_status  = 'UPDATED',
                       processed_at = CURRENT_TIMESTAMP";
    $stmt = $dest_pdo->prepare($insert_sql);

    $dlq_sql  = "INSERT INTO sync_dead_letter_queue (payload, error_message) VALUES (:payload, :error)";
    $dlq_stmt = $source_pdo->prepare($dlq_sql);

    $count       = 0;
    $has_records = false;

    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $has_records = true;

        $validation_error = validate_row($row);
        if ($validation_error !== null) {
            log_msg('WARN', "Skipping ID {$row['id']}: $validation_error");
            $total_failed++;
            continue;
        }

        try {
            $amount_naira = round((int)$row['amount_kobo'] / 100, 2);

            $stmt->execute([
                ':remote_id' => (int)$row['id'],
                ':email'     => $row['donor_email'],
                ':amount'    => $amount_naira,
            ]);

            $highest_id = max($highest_id, (int)$row['id']);
            $count++;
            $total_synced++;

            if ($count % BATCH_SIZE === 0) {
                $dest_pdo->commit();
                log_msg('INFO', "Committed batch of " . BATCH_SIZE . " rows. Cursor: $highest_id");
                $dest_pdo->beginTransaction();
            }

        } catch (Exception $e) {
            $total_failed++;

            $error_payload = [
                'data'      => $row,
                'error'     => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            try {
                $dlq_stmt->execute([
                    ':payload' => json_encode($error_payload),
                    ':error'   => $e->getMessage(),
                ]);
                $total_dlq++;
                log_msg('WARN', "ID {$row['id']} routed to DLQ: " . $e->getMessage());

            } catch (PDOException $db_error) {
                $log_file_emergency = __DIR__ . '/../logs/emergency_sync_' . date('Y-m-d') . '.json';
                $written = file_put_contents(
                    $log_file_emergency,
                    json_encode($error_payload) . PHP_EOL,
                    FILE_APPEND
                );
                if ($written === false) {
                    log_msg('CRITICAL', "Disk write FAILED for ID {$row['id']}. Check disk space and permissions.");
                } else {
                    $total_emergency++;
                    log_msg('WARN', "ID {$row['id']} written to emergency log (DB dead): " . $db_error->getMessage());
                }
            }
        }
    }

    if ($dest_pdo->inTransaction()) {
        $dest_pdo->commit();
    }

    // Persist cursor
    if ($highest_id > $last_id) {
        $dest_pdo->prepare(
            "INSERT INTO sync_cursor (engine, last_synced_id)
             VALUES ('SyncEngine', :id)
             ON DUPLICATE KEY UPDATE last_synced_id = :id"
        )->execute([':id' => $highest_id]);
    }

    // Audit log
    $duration = round(microtime(true) - $run_start, 2);
    $dest_pdo->prepare(
        "INSERT INTO sync_audit_log
             (engine, rows_synced, rows_failed, rows_dlq, rows_emergency, duration_seconds, cursor_end)
         VALUES ('SyncEngine', :synced, :failed, :dlq, :emergency, :duration, :cursor)"
    )->execute([
        ':synced'    => $total_synced,
        ':failed'    => $total_failed,
        ':dlq'       => $total_dlq,
        ':emergency' => $total_emergency,
        ':duration'  => $duration,
        ':cursor'    => $highest_id,
    ]);

    if (!$has_records) {
        log_msg('INFO', "0 new records to sync.");
    } else {
        log_msg('INFO', "Run complete. Synced=$total_synced Failed=$total_failed DLQ=$total_dlq Emergency=$total_emergency Duration={$duration}s");
    }

} catch (PDOException $e) {
    log_msg('CRITICAL', "Connection error: " . $e->getMessage());
} finally {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    @unlink($lock_file);
}
