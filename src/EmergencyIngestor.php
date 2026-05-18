<?php
require_once 'config.php';

// 1. Identify the log file
$log_file = 'logs/emergency_sync_' . date('Y-m-d') . '.json';

if (!file_exists($log_file)) {
    die("No emergency logs found for today. \n");
}

try {
    // 2. We only need the Destination PDO now (Source DB was dead, remember?)
    $dest_pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_DEST, DB_USER, DB_PASS);
    $dest_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Emergency Ingestor Started. Reading $log_file... \n";

    // 3. Read the file line by line
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $index => $line) {
        $payload = json_decode($line, true);
        $row = $payload['data']; // Extract the original donation data

        try {
            echo "Ingesting ID: " . $row['id'] . "... ";

            $amount_in_naira = $row['amount_kobo'] / 100;

            // 4. Standard Idempotent Sync
            $sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status) 
                    VALUES (:remote_id, :email, :amount, 'EMERGENCY_RECOVERY')
                    ON DUPLICATE KEY UPDATE sync_status = 'EMERGENCY_UPDATED'"; 
            
            $stmt = $dest_pdo->prepare($sql);
            $stmt->execute([
                ':remote_id' => $row['id'],
                ':email'     => $row['donor_email'],
                ':amount'    => $amount_in_naira
            ]);

            echo "Synced! \n";

        } catch (Exception $e) {
            echo "Still failing: " . $e->getMessage() . "\n";
        }
    }

    // 5. ARCHIVE THE LOG (So we don't double-process)
    rename($log_file, $log_file . '.processed');
    echo "\n--- Emergency Ingestion Complete. Log archived. --- \n";

} catch (PDOException $e) {
    die("DESTINATION STILL UNREACHABLE: " . $e->getMessage());
}