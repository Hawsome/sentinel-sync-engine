<?php
// Tier 3 drain: replay emergency log files into the destination.
//
// File lifecycle:  x.json -> x.json.inprogress -> x.json.processed
// Bad lines go to x.json.rejected with the reason, so nothing is dropped silently.
//
// A crash or outage leaves x.json.inprogress behind. The caller holds the
// ingestor lock, so any .inprogress file found at startup is an orphan from a
// dead run and is replayed from the top. The upsert makes the replay harmless.

/**
 * @return array{files:int, ingested:int, rejected:int, status:string, error:?string}
 */
function run_ingest(PDO $dest, string $log_dir): array {
    $stats = ['files' => 0, 'ingested' => 0, 'rejected' => 0, 'status' => 'COMPLETED', 'error' => null];

    $files = find_emergency_logs($log_dir);
    if ($files === []) {
        log_msg('INFO', "No unprocessed emergency logs found.");
        return $stats;
    }

    try {
        $upsert = prepare_upsert($dest, 'EMERGENCY_RECOVERY', 'EMERGENCY_UPDATED');

        foreach ($files as $file) {
            $base        = preg_replace('/\.inprogress$/', '', $file);
            $in_progress = $base . '.inprogress';

            if ($file !== $in_progress && !rename($file, $in_progress)) {
                log_msg('ERROR', "Could not rename $file. Skipping to avoid double-processing.");
                continue;
            }
            log_msg('INFO', "Processing: $in_progress");

            [$ingested, $rejected] = ingest_file($dest, $upsert, $in_progress, $base . '.rejected');

            rename($in_progress, $base . '.processed');
            $stats['files']++;
            $stats['ingested'] += $ingested;
            $stats['rejected'] += $rejected;
            log_msg('INFO', "Done: $base. Ingested=$ingested Rejected=$rejected. Archived.");
        }
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
        log_msg('CRITICAL', "Ingest aborted, file left in place for the next run: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Orphans first, so a stranded file from a crashed run is not starved.
 *
 * @return list<string>
 */
function find_emergency_logs(string $log_dir): array {
    return array_merge(
        glob($log_dir . '/emergency_sync_*.json.inprogress') ?: [],
        glob($log_dir . '/emergency_sync_*.json') ?: []
    );
}

/**
 * @return array{0:int, 1:int} [ingested, rejected]
 */
function ingest_file(PDO $dest, PDOStatement $upsert, string $path, string $rejected_path): array {
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("Could not open $path");
    }
    // Wait out a sync process that opened the file before the rename and is
    // still appending to it (writers use LOCK_EX).
    flock($handle, LOCK_SH);

    // 'w', not 'a': a replayed orphan regenerates its rejections from scratch.
    $rejects = fopen($rejected_path, 'w');
    if ($rejects === false) {
        fclose($handle);
        throw new RuntimeException("Could not open $rejected_path");
    }

    $ingested = 0;
    $rejected = 0;
    $reject   = function (string $line, string $reason) use ($rejects, &$rejected, $path): void {
        fwrite($rejects, json_encode(['reason' => $reason, 'line' => $line]) . PHP_EOL);
        $rejected++;
        log_msg('WARN', "Rejected line in $path: $reason");
    };

    try {
        $dest->beginTransaction();
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $envelope = json_decode($line, true);
            $row      = is_array($envelope) ? ($envelope['data'] ?? null) : null;

            $validation_error = is_array($row) ? validate_row($row) : 'missing data envelope';
            if ($validation_error !== null) {
                $reject($line, $validation_error);
                continue;
            }

            try {
                upsert_donation($upsert, $row);
                $ingested++;
            } catch (PDOException $e) {
                if (is_transient_db_error($e)) {
                    throw $e;
                }
                $reject($line, $e->getMessage());
                continue;
            }

            if ($ingested % BATCH_SIZE === 0) {
                $dest->commit();
                $dest->beginTransaction();
            }
        }
        $dest->commit();
    } finally {
        fclose($rejects);
        fclose($handle);
    }

    if ($rejected === 0) {
        @unlink($rejected_path);
    }
    return [$ingested, $rejected];
}
