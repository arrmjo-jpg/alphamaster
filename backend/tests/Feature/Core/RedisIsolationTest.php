<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Redis Isolation Assertions
|--------------------------------------------------------------------------
|
| tests/bootstrap.php points a test run at its own Redis logical databases,
| because the container exports CACHE_STORE, QUEUE_CONNECTION and SESSION_DRIVER
| as `redis` and phpunit.xml cannot override a real environment variable
| (ADR 0029 item 21).
|
| Without it, the cache store's flush() — which empties the entire logical
| database, not just this run's keys — destroyed the development cache on every
| `beforeEach` in the suite, and a dispatched job landed on the queue Horizon is
| watching.
|
| These assert the redirection took effect, rather than trusting that the
| bootstrap ran.
|
*/

/** The logical database a Redis connection is pointed at. */
function redisDatabaseIndex(string $connection): int
{
    return (int) config('database.redis.'.$connection.'.database');
}

test('the run does not share a redis database with development', function (): void {
    if (config('cache.default') !== 'redis') {
        $this->markTestSkipped('This run is not on the Redis cache store; there is nothing to share.');
    }

    // 0 and 1 are the development indexes, from the shipped .env.example.
    expect(redisDatabaseIndex('default'))->not->toBeIn([0, 1])
        ->and(redisDatabaseIndex('cache'))->not->toBeIn([0, 1])
        // ...and the two must not collide with each other either, or the cache
        // store's flush() would empty the queue.
        ->and(redisDatabaseIndex('cache'))->not->toBe(redisDatabaseIndex('default'));
});

test('flushing the cache does not reach another logical database', function (): void {
    if (config('cache.default') !== 'redis') {
        $this->markTestSkipped('This run is not on the Redis cache store.');
    }

    // A neighbour standing in for development: written directly, on a connection
    // this run's cache store does not use, and cleaned up below. The point is
    // that flush() is bounded by the database it is connected to.
    $neighbour = Redis::connection('default');
    $neighbour->set('isolation_probe', 'intact');

    Cache::put('isolation_probe', 'transient', 60);
    expect(Cache::get('isolation_probe'))->toBe('transient');

    Cache::flush();

    expect(Cache::get('isolation_probe'))->toBeNull()
        ->and($neighbour->get('isolation_probe'))->toBe('intact');

    $neighbour->del('isolation_probe');
});

test('the queue this run would dispatch to is not the development queue', function (): void {
    if (config('queue.default') !== 'redis') {
        $this->markTestSkipped('This run does not dispatch to Redis.');
    }

    $connection = (string) config('queue.connections.redis.connection', 'default');

    expect(redisDatabaseIndex($connection))->not->toBeIn([0, 1]);
});
