<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    /** @return array<string, array{0:int, 1:string}> */
    public static function koboProvider(): array
    {
        return [
            'one kobo'         => [1, '0.01'],
            'ten kobo'         => [10, '0.10'],
            'whole naira'      => [5000, '50.00'],
            'mixed'            => [12345, '123.45'],
            'not exact in binary' => [1015, '10.15'], // 1015 / 100 is 10.15000000000000035527 as a float
            'unsigned ceiling' => [MAX_AMOUNT_KOBO, '42949672.95'],
        ];
    }

    #[DataProvider('koboProvider')]
    public function testKoboToNairaIsExact(int $kobo, string $expected): void
    {
        $this->assertSame($expected, kobo_to_naira($kobo));
    }

    public function testValidRowPasses(): void
    {
        $this->assertNull(validate_row(['id' => 7, 'amount_kobo' => 5000, 'donor_email' => 'a@example.com']));
        $this->assertNull(validate_row(['id' => '7', 'amount_kobo' => '5000', 'donor_email' => 'a@example.com']));
    }

    /** @return array<string, array{0:array<string, mixed>}> */
    public static function invalidRowProvider(): array
    {
        $ok = ['id' => 1, 'amount_kobo' => 100, 'donor_email' => 'a@example.com'];
        return [
            'missing id'         => [array_diff_key($ok, ['id' => 0])],
            'zero id'            => [['id' => 0] + $ok],
            'fractional amount'  => [['amount_kobo' => '1.5'] + $ok],
            'exponent amount'    => [['amount_kobo' => '1e3'] + $ok],
            'negative amount'    => [['amount_kobo' => -100] + $ok],
            'amount over column' => [['amount_kobo' => MAX_AMOUNT_KOBO + 1] + $ok],
            'bad email'          => [['donor_email' => 'not-an-email'] + $ok],
            'email not a string' => [['donor_email' => ['a@example.com']] + $ok],
            'email too long'     => [['donor_email' => str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.com'] + $ok],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidRowProvider')]
    public function testInvalidRowsAreRejected(array $row): void
    {
        $this->assertNotNull(validate_row($row));
    }

    /** @return array<string, array{0:string, 1:int, 2:bool}> */
    public static function dbErrorProvider(): array
    {
        return [
            'server gone away'  => ['HY000', 2006, true],
            'server lost'       => ['HY000', 2013, true],
            'deadlock'          => ['40001', 1213, true],
            'lock wait timeout' => ['HY000', 1205, true],
            'connection class'  => ['08S01', 0, true],
            'duplicate key'     => ['23000', 1062, false],
            'data too long'     => ['22001', 1406, false],
            'user signal'       => ['45000', 1644, false],
        ];
    }

    #[DataProvider('dbErrorProvider')]
    public function testTransientErrorClassification(string $sqlstate, int $driver, bool $transient): void
    {
        $e = new PDOException('test');
        $e->errorInfo = [$sqlstate, $driver, 'test'];
        $this->assertSame($transient, is_transient_db_error($e));
    }

    public function testLockIsExclusiveAndFileSurvivesRelease(): void
    {
        $path = sys_get_temp_dir() . '/sentinel_test_' . bin2hex(random_bytes(4)) . '.lock';

        $first = acquire_lock($path);
        $this->assertNotNull($first);
        $this->assertNull(acquire_lock($path), 'second lock on a held file must fail');

        flock($first, LOCK_UN);
        fclose($first);
        $this->assertFileExists($path, 'lock file must never be unlinked');

        $again = acquire_lock($path);
        $this->assertNotNull($again);
        fclose($again);
        unlink($path);
    }
}
