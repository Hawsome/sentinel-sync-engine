<?php
require_once 'config.php';
require_once 'helpers.php';

// Scan ALL unprocessed emergency logs, not just today's — prevents date-boundary data loss
$log_dir   = __DIR__ . '/../logs';
$log_files = glob($log_dir . '/emergency_sync_*.json');

if (empty($log_files)) {
    log_msg('INFO', "No unprocessed emergency logs found.");
    exit(0);
}

try {
    $dest_pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DEST . ";charset=utf8mb4",
        DB_USER, DB_PASS
    );
    $dest_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status)
            VALUES (:remote_id, :email, :amount, 'EMERGENCY_RECOVERY')
            ON DUPLICATE KEY UPDATE
                email        = VALUES(email),
                amount_naira = VALUES(amount_naira),
                sync_status  = 'EMERGENCY_UPDATED',
                processed_at = CURRENT_TIMESTAMP";
    $stmt = $dest_pdo->prepare($sql);

    foreach ($log_files as $log_file) {
        log_msg('INFO', "Processing: $log_file");

        // Rename BEFORE processing — prevents re-processing if we crash mid-file
        $in_progress_file = $log_file . '.inprogress';
        if (!rename($log_file, $in_progress_file)) {
            log_msg('ERROR', "Could not rename $log_file — skipping to avoid double-processing.");
            continue;
        }

        $handle = fopen($in_progress_file, 'r');
        if (!$handle) {
            log_msg('ERROR', "Could not open $in_progress_file");
            rename($in_progress_file, $log_file);
            continue;
        }

        $dest_pdo->beginTransaction();
        $count   = 0;
        $skipped = 0;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $envelope = json_decode($line, true);
            $row      = isset($envelope['data']) ? $envelope['data'] : null;

            $validation_error = ($row !== null) ? validate_row($row) : 'missing data envelope';
            if ($validation_error !== null) {
                log_msg('WARN', "Skipping malformed line in $in_progress_file: $validation_error");
                $skipped++;
                continue;
            }

            try {
                $amount_naira = round((int)$row['amount_kobo'] / 100, 2);

                $stmt->execute([
                    ':remote_id' => (int)$row['id'],
                    ':email'     => $row['donor_email'],
                    ':amount'    => $amount_naira,
                ]);

                $count++;

                if ($count % BATCH_SIZE === 0) {
                    $dest_pdo->commit();
                    log_msg('INFO', "Committed batch of " . BATCH_SIZE . " rows from $in_progress_file");
                    $dest_pdo->beginTransaction();
                }

            } catch (Exception $e) {
                log_msg('ERROR', "Row ID {$row['id']} still failing: " . $e->getMessage());
                $skipped++;
            }
        }

        if ($dest_pdo->inTransaction()) {
            $dest_pdo->commit();
        }

        fclose($handle);

        rename($in_progress_file, $in_progress_file . '.processed');
        log_msg('INFO', "Done: $log_file — Ingested=$count Skipped=$skipped. Archived.");
    }

} catch (PDOException $e) {
    log_msg('CRITICAL', "Destination unreachable: " . $e->getMessage());
    exit(1);
}
