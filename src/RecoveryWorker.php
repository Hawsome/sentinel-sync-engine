<?php
require_once 'config.php';

// --- Concurrency Lock ---
if (!file_exists(__DIR__ . '/../logs')) { mkdir(__DIR__ . '/../logs', 0777, true); }
$lock_file = __DIR__ . '/../logs/recovery.lock';

if (file_exists($lock_file)) {
    if (time() - filemtime($lock_file) > 3600) {
        unlink($lock_file);
    } else {
        die("Recovery is already running.\n");
    }
}
file_put_contents($lock_file, time());

try {
    $source_pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_SOURCE, DB_USER, DB_PASS);
    $source_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dest_pdo   = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_DEST, DB_USER, DB_PASS);
    $dest_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // --- Memory Fix ---
    $query = $source_pdo->query("SELECT * FROM sync_dead_letter_queue");

    $sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status) 
            VALUES (:remote_id, :email, :amount, 'RECOVERED')
            ON DUPLICATE KEY UPDATE sync_status = 'RECOVERED_UPDATE'"; 
    $stmt = $dest_pdo->prepare($sql);

    $delete_stmt = $source_pdo->prepare("DELETE FROM sync_dead_letter_queue WHERE id = :id");
    $update_stmt = $source_pdo->prepare("UPDATE sync_dead_letter_queue SET attempts = attempts + 1 WHERE id = :id");

    $has_records = false;
    
    while ($item = $query->fetch(PDO::FETCH_ASSOC)) {
        $has_records = true;
        $data = json_decode($item['payload'], true);
        $queue_id = $item['id'];

        try {
            echo "Retrying ID: " . $data['id'] . "... ";
            $amount_in_naira = $data['amount_kobo'] / 100;
            
            $stmt->execute([
                ':remote_id' => $data['id'],
                ':email'     => $data['donor_email'],
                ':amount'    => $amount_in_naira
            ]);

            $delete_stmt->execute([':id' => $queue_id]);

            echo "Recovered! \n";

        } catch (Exception $e) {
            echo "Still failing. \n";
            $update_stmt->execute([':id' => $queue_id]);
        }
    }
    
    if (!$has_records) {
        echo "Queue is empty. No recovery needed. \n";
    }

} catch (PDOException $e) {
    echo "CRITICAL CONNECTION ERROR: " . $e->getMessage() . "\n";
} finally {
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
}