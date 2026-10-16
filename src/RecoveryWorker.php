<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/recovery.php';

$lock = acquire_lock(LOG_DIR . '/recovery.lock');
if ($lock === null) {
    log_msg('INFO', "Recovery is already running.");
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

$stats = run_recovery($source_pdo, $dest_pdo);

log_msg(
    $stats['status'] === 'COMPLETED' ? 'INFO' : 'ERROR',
    sprintf(
        "Recovery %s. Recovered=%d Failed=%d Quarantined=%d Duration=%.2fs",
        strtolower($stats['status']), $stats['recovered'], $stats['failed'],
        $stats['quarantined'], microtime(true) - $run_start
    )
);

exit($stats['status'] === 'COMPLETED' ? 0 : 1);
