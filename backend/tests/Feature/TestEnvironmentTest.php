<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Test Environment Assertions
|--------------------------------------------------------------------------
|
| PHPUnit's <env> entries in phpunit.xml are not force="true", so a real
| environment variable wins over them and their absence loses to them. That is
| deliberate (ADR 0027, and tests/bootstrap.php explains why), but it means a run
| launched without database environment quietly falls back to SQLite in memory —
| and passes.
|
| Locally that is harmless, because the developer knows which command they typed.
| In CI it is not: a job named "PostgreSQL" that never reached PostgreSQL would
| report the same green as one that did, and the whole point of moving the gate
| onto CI is that its green means something specific.
|
| So a caller who intends a particular engine says so with EXPECTED_DB_DRIVER, and
| the mismatch fails here rather than passing silently.
|
*/

/**
 * Read a value from every source, since tests/bootstrap.php writes with putenv()
 * and Laravel's env() is not guaranteed to see it after config caching.
 */
function environmentValue(string $key): ?string
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

    return ($value === false || $value === null || $value === '') ? null : (string) $value;
}

test('the suite runs on the database engine the caller expected', function (): void {
    $expected = environmentValue('EXPECTED_DB_DRIVER');

    if ($expected === null) {
        $this->markTestSkipped(
            'EXPECTED_DB_DRIVER is not set, so no engine was claimed. CI always sets it.'
        );
    }

    expect(DB::connection()->getDriverName())->toBe($expected);
});

test('a non-sqlite run is pointed at a dedicated test database', function (): void {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite runs use the in-memory database and have nothing durable to protect.');
    }

    // tests/bootstrap.php appends _test and refuses to start otherwise. This asserts
    // the guard actually took effect rather than trusting that it ran, because the
    // cost of it silently not running is a dropped development database.
    expect(DB::connection()->getDatabaseName())->toEndWith('_test');
});
