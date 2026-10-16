<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ingest.php';

// Lock first, then scan: a second instance must not see the file list at all.
$lock = acquire_lock(LOG_DIR . '/emergency.lock');
if ($lock === null) {
    log_msg('INFO', "Emergency ingestor is already running.");
    exit(0);
}

if (find_emergency_logs(LOG_DIR) === []) {
    log_msg('INFO', "No unprocessed emergency logs found.");
    exit(0);
}

try {
    $dest_pdo = connect_dest();
} catch (PDOException $e) {
    log_msg('CRITICAL', "Destination unreachable: " . $e->getMessage());
    exit(1);
}

$stats = run_ingest($dest_pdo, LOG_DIR);

if ($stats['files'] > 0 || $stats['status'] !== 'COMPLETED') {
    log_msg(
        $stats['status'] === 'COMPLETED' ? 'INFO' : 'ERROR',
        sprintf(
            "Ingest %s. Files=%d Ingested=%d Rejected=%d",
            strtolower($stats['status']), $stats['files'], $stats['ingested'], $stats['rejected']
        )
    );
}

exit($stats['status'] === 'COMPLETED' ? 0 : 1);
