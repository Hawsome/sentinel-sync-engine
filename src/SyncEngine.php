<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/sync.php';

// Held until exit; the OS releases it even if the process is killed.
$lock = acquire_lock(LOG_DIR . '/sync.lock');
if ($lock === null) {
    log_msg('INFO', "Sync is already running. Exiting to prevent overlap.");
    exit(0);
}

$run_start = microtime(true);

try {
    $source_pdo = connect_source();
    $dest_pdo   = connect_dest();
} catch (PDOException $e) {
    log_msg('CRITICAL', "Connection error: " . $e->getMessage());
    exit(1);
}

$stats    = run_sync($source_pdo, $dest_pdo, LOG_DIR);
$duration = microtime(true) - $run_start;
write_audit($dest_pdo, $stats, $duration);

log_msg(
    $stats['status'] === 'COMPLETED' ? 'INFO' : 'ERROR',
    sprintf(
        "Run %s. Synced=%d Failed=%d DLQ=%d Emergency=%d Cursor=%d Duration=%.2fs",
        strtolower($stats['status']), $stats['synced'], $stats['failed'],
        $stats['dlq'], $stats['emergency'], $stats['cursor'], $duration
    )
);

exit($stats['status'] === 'COMPLETED' ? 0 : 1);
