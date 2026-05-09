<?php
require_once 'config.php';

try {
    $source_pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_SOURCE, DB_USER, DB_PASS);
    $dest_pdo   = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_DEST, DB_USER, DB_PASS);
    
    $query = $source_pdo->query("SELECT * FROM sync_dead_letter_queue");
    $failed_items = $query->fetchAll(PDO::FETCH_ASSOC);

    if (count($failed_items) === 0) {
        die("Queue is empty. No recovery needed. \n");
    }

    foreach ($failed_items as $item) {
        $data = json_decode($item['payload'], true);
        $queue_id = $item['id'];

        try {
            echo "Retrying ID: " . $data['id'] . "... ";
            $amount_in_naira = $data['amount_kobo'] / 100;

            $sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status) 
                    VALUES (:remote_id, :email, :amount, 'RECOVERED')
                    ON DUPLICATE KEY UPDATE sync_status = 'RECOVERED_UPDATE'"; 
            
            $stmt = $dest_pdo->prepare($sql);
            $stmt->execute([
                ':remote_id' => $data['id'],
                ':email'     => $data['donor_email'],
                ':amount'    => $amount_in_naira
            ]);

            $source_pdo->prepare("DELETE FROM sync_dead_letter_queue WHERE id = :id")
                       ->execute([':id' => $queue_id]);

            echo "Recovered! \n";

        } catch (Exception $e) {
            echo "Still failing. \n";
            $source_pdo->prepare("UPDATE sync_dead_letter_queue SET attempts = attempts + 1 WHERE id = :id")
                       ->execute([':id' => $queue_id]);
        }
    }
} catch (PDOException $e) {
    die("CRITICAL CONNECTION ERROR: " . $e->getMessage());
}