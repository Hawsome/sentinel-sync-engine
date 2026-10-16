<?php
// Shared helpers included by all engine scripts and the test suite.
// Nothing here reads config.php, so tests can load it without a .env file.

const BATCH_SIZE       = 500;
const MAX_ATTEMPTS     = 5;
const MAX_EMAIL_LENGTH = 254;
const MAX_AMOUNT_KOBO  = 4294967295; // INT UNSIGNED ceiling of wp_donations.amount_kobo
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
    return new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
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
    return null;
}

/**
 * Integer kobo to an exact decimal string for a DECIMAL(10,2) column.
 * Pure integer arithmetic: no float ever touches the money.
 */
function kobo_to_naira(int $kobo): string {
    return sprintf('%d.%02d', intdiv($kobo, 100), $kobo % 100);
}

function prepare_upsert(PDO $dest, string $insert_status, string $update_status): PDOStatement {
    $stmt = $dest->prepare(
        "INSERT INTO synced_donations (remote_id, email, amount_naira, sync_status)
         VALUES (:remote_id, :email, :amount, :insert_status)
         ON DUPLICATE KEY UPDATE
             email        = VALUES(email),
             amount_naira = VALUES(amount_naira),
             sync_status  = :update_status,
             processed_at = CURRENT_TIMESTAMP"
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
    $stmt->execute();
}
