<?php
require_once 'config.php';

// --- Concurrency Lock ---
if (!file_exists(__DIR__ . '/../logs')) { mkdir(__DIR__ . '/../logs', 0777, true); }
$lock_file = __DIR__ . '/../logs/sync.lock';

if (file_exists($lock_file)) {
    // Check if lock is stale (e.g. older than 1 hour)
    if (time() - filemtime($lock_file) > 3600) {
        unlink($lock_file);
    } else {
        die("Sync is already running. Exiting to prevent overlap.\n");
    }
}
file_put_contents($lock_file, time());

try {
    $source_pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_SOURCE, DB_USER, DB_PASS);
    $source_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $dest_pdo   = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_DEST, DB_USER, DB_PASS);
    $dest_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- State Tracking ---
    $max_id_query = $dest_pdo->query("SELECT MAX(remote_id) as last_id FROM synced_donations");
    $result = $max_id_query->fetch(PDO::FETCH_ASSOC);
    $last_id = $result['last_id'] ? (int)$result['last_id'] : 0;
    
    echo "Starting sync from ID > $last_id\n";

    // --- Use fetch instead of fetchAll, and filter by last_id ---
    $query = $source_pdo->prepare("SELECT * FROM wp_donations WHERE id > :last_id ORDER BY id ASC");
    $query->execute([':last_id' => $last_id]);

    $batch_size = 500;
    $count = 0;
    $has_records = false;

    // Prepared statement for inserts to reuse
    $sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status) 
            VALUES (:remote_id, :email, :amount, 'SUCCESS')
            ON DUPLICATE KEY UPDATE sync_status = 'UPDATED'"; 
    $stmt = $dest_pdo->prepare($sql);

    // DLQ Prepared statement
    $dlq_sql = "INSERT INTO sync_dead_letter_queue (payload, error_message) VALUES (:payload, :error)";
    $dlq_stmt = $source_pdo->prepare($dlq_sql);

    $dest_pdo->beginTransaction();

    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $has_records = true;
        try {
            echo "Processing ID: " . $row['id'] . "... ";

            // Convert Kobo (int) to Naira (decimal)
            $amount_in_naira = $row['amount_kobo'] / 100;

            $stmt->execute([
                ':remote_id' => $row['id'],
                ':email'     => $row['donor_email'],
                ':amount'    => $amount_in_naira
            ]);

            echo "Synced! \n";

            $count++;
            
            // --- Batching Fix ---
            if ($count % $batch_size === 0) {
                $dest_pdo->commit();
                $dest_pdo->beginTransaction();
            }

        } catch (Exception $e) {
            echo "FAILED! ";
            
            $error_payload = [
                'data' => $row,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];

            try {
                // Attempt 1: Save to Database DLQ
                $dlq_stmt->execute([
                    ':payload' => json_encode($row),
                    ':error'   => $e->getMessage()
                ]);
                echo "Saved to DB Queue. \n";

            } catch (PDOException $db_error) {
                // Attempt 2: Database is DEAD. Save to emergency local file.
                echo "DB DEAD. Saving to Emergency Log... ";
                
                $log_file_emergency = __DIR__ . '/../logs/emergency_sync_' . date('Y-m-d') . '.json';
                
                // Append the failed data to a local file
                file_put_contents($log_file_emergency, json_encode($error_payload) . PHP_EOL, FILE_APPEND);
                
                echo "Saved to disk. \n";
            }
        }
    }
    
    if ($dest_pdo->inTransaction()) {
        $dest_pdo->commit();
    }

    if (!$has_records) {
        echo "0 records to sync.\n";
    }

} catch (PDOException $e) {
    echo "CRITICAL CONNECTION ERROR: " . $e->getMessage() . "\n";
} finally {
    // Release the lock
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
}