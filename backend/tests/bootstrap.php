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
