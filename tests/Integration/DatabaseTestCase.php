<?php

use PHPUnit\Framework\TestCase;

/**
 * Runs against a real MySQL server. Skipped unless SENTINEL_TEST_DB_HOST is set.
 *
 * Creates two throwaway databases from the real setup scripts, so the tests
 * exercise the same schema a user installs.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const SOURCE_DB = 'sentinel_test_source';
    protected const DEST_DB   = 'sentinel_test_dest';

    protected PDO $source;
    protected PDO $dest;
    protected string $logDir;

    private static bool $schemaBuilt = false;

    protected function setUp(): void
    {
        $host = getenv('SENTINEL_TEST_DB_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('Set SENTINEL_TEST_DB_HOST (and _PORT, _USER, _PASS) to run integration tests.');
        }
        $port = getenv('SENTINEL_TEST_DB_PORT') ?: '3306';
        $user = getenv('SENTINEL_TEST_DB_USER') ?: 'root';
        $pass = getenv('SENTINEL_TEST_DB_PASS') ?: '';

        if (!self::$schemaBuilt) {
            $server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            self::buildSchema($server, 'source_setup.sql', 'wp_source_db', self::SOURCE_DB);
            self::buildSchema($server, 'destination_setup.sql', 'accounting_dest_db', self::DEST_DB);
            self::$schemaBuilt = true;
        }

        $this->source = connect($host, $port, self::SOURCE_DB, $user, $pass);
        $this->dest   = connect($host, $port, self::DEST_DB, $user, $pass);

        $this->source->exec('DROP TRIGGER IF EXISTS test_reject_dlq');
        $this->dest->exec('DROP TRIGGER IF EXISTS test_reject_donation');
        foreach (['wp_donations', 'sync_dead_letter_queue'] as $table) {
            $this->source->exec("TRUNCATE TABLE $table");
        }
        foreach (['synced_donations', 'sync_cursor', 'sync_audit_log'] as $table) {
            $this->dest->exec("TRUNCATE TABLE $table");
        }

        $this->logDir = sys_get_temp_dir() . '/sentinel_test_logs_' . bin2hex(random_bytes(4));
        mkdir($this->logDir, 0750, true);

        ob_start(); // swallow log_msg output; closed in tearDown
    }

    protected function tearDown(): void
    {
        if (isset($this->logDir)) {
            ob_end_clean();
        }
        if (isset($this->logDir) && is_dir($this->logDir)) {
            array_map('unlink', glob($this->logDir . '/*') ?: []);
            rmdir($this->logDir);
        }
    }

    private static function buildSchema(PDO $server, string $file, string $from_db, string $to_db): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/sql/' . $file);
        $sql = str_replace($from_db, $to_db, $sql);
        $sql = (string)preg_replace('/--[^\n]*/', '', $sql);

        $server->exec("DROP DATABASE IF EXISTS `$to_db`");
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $server->exec($statement);
        }
    }

    protected function insertDonation(string $email, int|string $amount_kobo): int
    {
        $this->source->prepare('INSERT INTO wp_donations (donor_email, amount_kobo) VALUES (:e, :a)')
            ->execute([':e' => $email, ':a' => $amount_kobo]);
        return (int)$this->source->lastInsertId();
    }

    /** Make the destination reject one email with a non-transient error. */
    protected function rejectEmailAtDestination(string $email, string $sqlstate = '45000'): void
    {
        $this->dest->exec(
            "CREATE TRIGGER test_reject_donation BEFORE INSERT ON synced_donations FOR EACH ROW
             BEGIN
                 IF NEW.email = " . $this->dest->quote($email) . " THEN
                     SIGNAL SQLSTATE '$sqlstate' SET MESSAGE_TEXT = 'test rejection';
                 END IF;
             END"
        );
    }

    protected function breakDeadLetterQueue(): void
    {
        $this->source->exec(
            "CREATE TRIGGER test_reject_dlq BEFORE INSERT ON sync_dead_letter_queue FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DLQ unavailable'"
        );
    }

    protected function cursor(): int
    {
        return (int)$this->scalar($this->dest, "SELECT last_synced_id FROM sync_cursor WHERE engine = 'SyncEngine'");
    }

    protected function rowCount(PDO $pdo, string $table, string $where = '1=1'): int
    {
        return (int)$this->scalar($pdo, "SELECT COUNT(*) FROM $table WHERE $where");
    }

    protected function scalar(PDO $pdo, string $sql): mixed
    {
        return $this->query($pdo, $sql)->fetchColumn();
    }

    /** @return array<string, mixed> */
    protected function row(PDO $pdo, string $sql): array
    {
        $row = $this->query($pdo, $sql)->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "No row for: $sql");
        return $row;
    }

    /** @return list<mixed> */
    protected function column(PDO $pdo, string $sql): array
    {
        return array_values($this->query($pdo, $sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        return $stmt;
    }
}
