<?php
// Shared helpers included by all engine scripts and the test suite.
// Nothing here reads config.php, so tests can load it without a .env file.

const BATCH_SIZE       = 500;
const MAX_ATTEMPTS     = 5;
const MAX_EMAIL_LENGTH = 254;
const MAX_AMOUNT_KOBO  = 4294967295; // INT UNSIGNED ceiling of wp_donations.amount_kobo
const DONATION_STATUSES = ['completed', 'refunded'];
const LOG_DIR          = __DIR__ . '/../logs';

// MySQL driver codes that mean "the connection or transaction is gone, try the
// whole unit again later", as opposed to "this particular row is bad".
const TRANSIENT_DRIVER_CODES = [
    1205, // ER_LOCK_WAIT_TIMEOUT
    1213, // ER_LOCK_DEADLOCK (MySQL rolls back the entire transaction)
    2002, // CR_CONNECTION_ERROR
    2006, // CR_SERVER_GONE_ERROR
    2013, // CR_SERVER_LOST
    4031, // ER_CLIENT_INTERACTION_TIMEOUT
];

function log_msg(string $level, string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . PHP_EOL;
}

function connect(string $host, string $port, string $dbname, string $user, string $pass): PDO {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    // The cursor timestamp is read from the destination and compared on the
    // source. MySQL renders TIMESTAMP values in the session time zone, so two
    // servers with different defaults would shift every comparison by hours.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * Take an exclusive, non-blocking lock. Returns the handle, or null if another
 * process holds it. The caller keeps the handle open for the life of the run;
 * the OS releases the lock when the process exits, however it exits.
 *
 * The lock file is deliberately never deleted. Unlinking it after unlocking
 * lets a waiting process lock the old inode while a newcomer creates and locks
 * a new one at the same path, and then both believe they hold the lock.
 *
 * @return resource|null
 * @phpstan-impure
 */
function acquire_lock(string $path) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $handle = fopen($path, 'c');
    if ($handle === false) {
        return null;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, (string)getmypid());
    return $handle;
}

function is_transient_db_error(PDOException $e): bool {
    $sqlstate = (string)($e->errorInfo[0] ?? $e->getCode());
    $driver   = (int)($e->errorInfo[1] ?? 0);

    return in_array($driver, TRANSIENT_DRIVER_CODES, true)
        || $sqlstate === '40001'          // serialization failure / deadlock
        || str_starts_with($sqlstate, '08'); // connection exception class
}

/**
 * @param array<string, mixed> $row
 */
function validate_row(array $row): ?string {
    $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        return 'Invalid or missing id';
    }
    $amount = filter_var($row['amount_kobo'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => MAX_AMOUNT_KOBO],
    ]);
    if ($amount === false) {
        return 'Invalid or missing amount_kobo (must be a positive integer)';
    }
    $email = $row['donor_email'] ?? null;
    if (!is_string($email) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid or missing donor_email';
    }
    if (strlen($email) > MAX_EMAIL_LENGTH) {
        return 'donor_email exceeds maximum length';
    }
    // Both optional so dead letters written before these columns existed still replay.
    if (isset($row['status']) && !in_array($row['status'], DONATION_STATUSES, true)) {
        return 'Invalid status (must be one of: ' . implode(', ', DONATION_STATUSES) . ')';
    }
    if (isset($row['updated_at'])
        && (!is_string($row['updated_at'])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $row['updated_at']))) {
        return 'Invalid updated_at (expected Y-m-d H:i:s[.u])';
    }
    return null;
}

/**
 * Integer kobo to an exact decimal string for a DECIMAL(10,2) column.
 * Pure integer arithmetic: no float ever touches the money.
 */
function kobo_to_naira(int $kobo): string {
    return sprintf('%d.%02d', intdiv($kobo, 100), $kobo % 100);
}

/**
 * Insert, or update only if the incoming row is at least as new as the stored one.
 *
 * The guard is what makes late replays safe now that source rows change: a dead
 * letter parked an hour ago must not overwrite the refund that synced since.
 *
 * Unversioned rows (NULL source_updated_at, from before versioning) behave as
 * the oldest possible version: they fill an empty slot, accept any update, and
 * never overwrite a versioned row. In SQL terms, "NULL >= x" is NULL, which
 * IF() treats as false, so the stored value is kept.
 */
function prepare_upsert(PDO $dest, string $insert_status, string $update_status): PDOStatement {
    $fresh = "(source_updated_at IS NULL OR VALUES(source_updated_at) >= source_updated_at)";

    // The MySQL manual says single-table assignments are "generally evaluated
    // from left to right", and a later one sees a column's new value. That is
    // a tendency, not a promise, so this statement must not depend on order.
    // It doesn't: source_updated_at carries the same guard as the rest, so it
    // only changes when the row is fresh, and then "new >= new" is still true.
    // An unguarded "source_updated_at = VALUES(source_updated_at)" evaluated
    // first would make every later IF() true and silently defeat the guard.
    // It goes last anyway, for the next reader.
    $stmt = $dest->prepare(
        "INSERT INTO synced_donations
             (remote_id, email, amount_naira, donation_status, source_updated_at, sync_status)
         VALUES (:remote_id, :email, :amount, :donation_status, :source_updated_at, :insert_status)
         ON DUPLICATE KEY UPDATE
             email             = IF($fresh, VALUES(email), email),
             amount_naira      = IF($fresh, VALUES(amount_naira), amount_naira),
             donation_status   = IF($fresh, VALUES(donation_status), donation_status),
             sync_status       = IF($fresh, :update_status, sync_status),
             processed_at      = IF($fresh, CURRENT_TIMESTAMP, processed_at),
             source_updated_at = IF($fresh, VALUES(source_updated_at), source_updated_at)"
    );
    $stmt->bindValue(':insert_status', $insert_status);
    $stmt->bindValue(':update_status', $update_status);
    return $stmt;
}

/**
 * Caller must have run validate_row() first.
 *
 * @param array<string, mixed> $row
 */
function upsert_donation(PDOStatement $stmt, array $row): void {
    $stmt->bindValue(':remote_id', (int)$row['id'], PDO::PARAM_INT);
    $stmt->bindValue(':email', (string)$row['donor_email']);
    $stmt->bindValue(':amount', kobo_to_naira((int)$row['amount_kobo']));
    $stmt->bindValue(':donation_status', (string)($row['status'] ?? 'completed'));
    $stmt->bindValue(':source_updated_at', $row['updated_at'] ?? null, isset($row['updated_at']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();
}
