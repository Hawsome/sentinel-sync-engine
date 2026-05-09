<?php
require_once 'config.php';

try {
    $source_pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_SOURCE, DB_USER, DB_PASS);
    $dest_pdo   = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_DEST, DB_USER, DB_PASS);
    
    $query = $source_pdo->query("SELECT * FROM wp_donations");
    $donations = $query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($donations as $row) {
        try {
            echo "Processing ID: " . $row['id'] . "... ";

            // Convert Kobo (int) to Naira (decimal)
            $amount_in_naira = $row['amount_kobo'] / 100;

            $sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status) 
                    VALUES (:remote_id, :email, :amount, 'SUCCESS')
                    ON DUPLICATE KEY UPDATE sync_status = 'UPDATED'"; 
            
            $stmt = $dest_pdo->prepare($sql);
            $stmt->execute([
                ':remote_id' => $row['id'],
                ':email'     => $row['donor_email'],
                ':amount'    => $amount_in_naira
            ]);

            echo "Synced! \n";

        } catch (Exception $e) {
            echo "FAILED! Saving to Dead Letter Queue... ";
            $dlq_sql = "INSERT INTO sync_dead_letter_queue (payload, error_message) VALUES (:payload, :error)";
            $dlq_stmt = $source_pdo->prepare($dlq_sql);
            $dlq_stmt->execute([
                ':payload' => json_encode($row),
                ':error'   => $e->getMessage()
            ]);
            echo "Saved. \n";
        }
    }
} catch (PDOException $e) {
    die("CRITICAL CONNECTION ERROR: " . $e->getMessage());
}