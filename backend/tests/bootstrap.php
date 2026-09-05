<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Bootstrap — Database Isolation
|--------------------------------------------------------------------------
|
| PHPUnit's <env> entries are not marked force="true", so real environment
| variables win over them. Inside the Docker stack the backend container exports
| DB_CONNECTION=pgsql and DB_DATABASE=alphamaster from its env_file, which means
| `php artisan test` resolved to the development database and RefreshDatabase ran
| migrate:fresh straight through it. Marking the entries force="true" is not an
| option: it would push the container onto SQLite and destroy the only PostgreSQL
| coverage the suite has (ADR 0027).
|
| So the redirection happens here, before Laravel reads the environment at all: any
| non-SQLite run is pointed at a dedicated database whose name ends in `_test`,
| which is created on demand. A run can no longer touch development data by
| accident, and if the name cannot be made safe the suite refuses to start rather
| than migrating something it should not.
|
*/

require __DIR__.'/../vendor/autoload.php';

/**
 * Read an environment value from every source Laravel will later consult.
 */
$readEnv = static function (string $key, ?string $default = null) use (&$readEnv): ?string {
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
};

/**
 * Write an environment value everywhere Laravel might read it from.
 */
$writeEnv = static function (string $key, string $value): void {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

/*
|--------------------------------------------------------------------------
| Redis Isolation
|--------------------------------------------------------------------------
|
| The same override applies to CACHE_STORE, QUEUE_CONNECTION and SESSION_DRIVER:
| the container exports all three as `redis`, so phpunit.xml's `array` loses and
| a test run shares the development Redis. That is not merely untidy. The cache
| store's flush() empties the whole logical database, so a single test calling
| Cache::flush() wiped every development cache entry; a dispatched job landed on
| the queue Horizon is watching, to be executed for real against development
| data.
|
| Running against a real Redis is deliberate (ADR 0027) — the rate limiter and
| the localization cache are only meaningfully covered there — so the fix is the
| same as for the database: keep the real service and point the run at its own
| logical databases. Redis provides sixteen; development uses 0 and 1.
|
| This runs before the SQLite check below, because a SQLite run inside the
| container still reaches Redis for its cache.
|
| The indexes belong to a run's configuration, not to a process: two suites
| running at the same time still share them, and because flush() empties the
| whole database one will empty the other's cache mid-test. The gate runs its
| suites in sequence, so this only bites someone running a second suite by hand
| alongside the first — which is what REDIS_TEST_DB and REDIS_TEST_CACHE_DB are
| for.
|
*/

$writeEnv('REDIS_DB', $readEnv('REDIS_TEST_DB', '10'));
$writeEnv('REDIS_CACHE_DB', $readEnv('REDIS_TEST_CACHE_DB', '11'));

$connection = $readEnv('DB_CONNECTION', 'sqlite');

// SQLite runs use the in-memory database configured in phpunit.xml and have nothing
// durable to protect.
if ($connection === 'sqlite') {
    return;
}

// A DSN would silently override the database name chosen below.
$writeEnv('DB_URL', '');

$database = $readEnv('DB_DATABASE', 'testing');

if (! str_ends_with($database, '_test')) {
    $database .= '_test';
}

$writeEnv('DB_DATABASE', $database);

// Belt and braces: if anything above failed to take effect, stop now rather than
// letting a destructive migration run against whatever this points at.
if (! str_ends_with((string) $readEnv('DB_DATABASE'), '_test')) {
    fwrite(STDERR, PHP_EOL.'Refusing to run the test suite: DB_DATABASE is not a dedicated test database.'.PHP_EOL);
    exit(1);
}

// Create the test database if it does not exist yet, so a fresh checkout or a fresh
// container needs no manual setup step.
if ($connection === 'pgsql') {
    $host = $readEnv('DB_HOST', '127.0.0.1');
    $port = $readEnv('DB_PORT', '5432');
    $username = $readEnv('DB_USERNAME', 'forge');
    $password = $readEnv('DB_PASSWORD', '');

    try {
        // Connect to the maintenance database; CREATE DATABASE cannot run from inside
        // the database being created.
        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname=postgres",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);

        if ($statement->fetchColumn() === false) {
            // The name is derived from DB_DATABASE and constrained to end in `_test`;
            // it is quoted as an identifier because CREATE DATABASE takes no bindings.
            $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
        }
    } catch (PDOException $e) {
        fwrite(STDERR, PHP_EOL.'Unable to prepare the test database ['.$database.']: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
