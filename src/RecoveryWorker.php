<?php
require_once 'config.php';
require_once 'helpers.php';

const RECOVERY_BATCH_LIMIT = 100;

// --- Atomic file lock ---
if (!file_exists(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0750, true);
}
$lock_file   = __DIR__ . '/../logs/recovery.lock';
$lock_handle = fopen($lock_file, 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    die("Recovery is already running.\n");
}
ftruncate($lock_handle, 0);
fwrite($lock_handle, (string)getmypid());

$run_start        = microtime(true);
$total_recovered  = 0;
$total_failed     = 0;
$total_quarantined = 0;

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

    $query = $source_pdo->prepare(
        "SELECT id, payload, error_message, attempts
         FROM sync_dead_letter_queue
         WHERE attempts < :max_attempts
           AND quarantined = 0
         ORDER BY id ASC
         LIMIT :batch_limit"
    );
    $query->bindValue(':max_attempts', MAX_ATTEMPTS, PDO::PARAM_INT);
    $query->bindValue(':batch_limit', RECOVERY_BATCH_LIMIT, PDO::PARAM_INT);
    $query->execute();

    $insert_sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status)
                   VALUES (:remote_id, :email, :amount, 'RECOVERED')
                   ON DUPLICATE KEY UPDATE
                       email        = VALUES(email),
                       amount_naira = VALUES(amount_naira),
                       sync_status  = 'RECOVERED_UPDATED',
                       processed_at = CURRENT_TIMESTAMP";
    $stmt = $dest_pdo->prepare($insert_sql);

    $delete_stmt     = $source_pdo->prepare("DELETE FROM sync_dead_letter_queue WHERE id = :id");
    $increment_stmt  = $source_pdo->prepare("UPDATE sync_dead_letter_queue SET attempts = attempts + 1 WHERE id = :id");
    $quarantine_stmt = $source_pdo->prepare("UPDATE sync_dead_letter_queue SET quarantined = 1 WHERE id = :id");

    $has_records = false;

    while ($item = $query->fetch(PDO::FETCH_ASSOC)) {
        $has_records = true;
        $queue_id = (int)$item['id'];

        $envelope = json_decode($item['payload'], true);
        // Support both payload formats: wrapped {data: {...}} and bare row
        $data = isset($envelope['data']) ? $envelope['data'] : $envelope;

        if (empty($data['id']) || empty($data['amount_kobo']) || empty($data['donor_email'])) {
            log_msg('WARN', "DLQ id=$queue_id has malformed payload — quarantining.");
            $quarantine_stmt->execute([':id' => $queue_id]);
            $total_quarantined++;
            continue;
        }

        try {
            $amount_naira = round((int)$data['amount_kobo'] / 100, 2);

            $stmt->execute([
                ':remote_id' => (int)$data['id'],
                ':email'     => $data['donor_email'],
                ':amount'    => $amount_naira,
            ]);

            $delete_stmt->execute([':id' => $queue_id]);
            $total_recovered++;
            log_msg('INFO', "Recovered DLQ id=$queue_id (remote_id={$data['id']})");

        } catch (Exception $e) {
            $new_attempts = (int)$item['attempts'] + 1;
            $increment_stmt->execute([':id' => $queue_id]);
            $total_failed++;

            if ($new_attempts >= MAX_ATTEMPTS) {
                $quarantine_stmt->execute([':id' => $queue_id]);
                $total_quarantined++;
                log_msg('ERROR', "DLQ id=$queue_id quarantined after $new_attempts attempts: " . $e->getMessage());
            } else {
                log_msg('WARN', "DLQ id=$queue_id still failing (attempt $new_attempts/" . MAX_ATTEMPTS . "): " . $e->getMessage());
            }
        }
    }

    if (!$has_records) {
        log_msg('INFO', "Queue is empty. No recovery needed.");
    } else {
        $duration = round(microtime(true) - $run_start, 2);
        log_msg('INFO', "Recovery complete. Recovered=$total_recovered Failed=$total_failed Quarantined=$total_quarantined Duration={$duration}s");
    }

} catch (PDOException $e) {
    log_msg('CRITICAL', "Connection error: " . $e->getMessage());
} finally {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    @unlink($lock_file);
}
