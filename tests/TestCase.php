<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * HARD SAFETY GUARD — never let the test suite touch a real database.
     *
     * Incident (2026-06-07): a `php artisan test` run WIPED the production
     * `ebq` MySQL database. `phpunit.xml` pins the connection to
     * sqlite :memory:, but a *cached* config (`php artisan optimize` writes
     * bootstrap/cache/config.php) overrode those env values, so the suite
     * resolved `database.default = mysql` and `RefreshDatabase` executed
     * `migrate:fresh` against production. There was no backup; data was lost.
     *
     * Laravel boots the app in refreshApplication() and only THEN calls
     * setUpTraits() (where RefreshDatabase migrates). Overriding setUpTraits()
     * and checking the resolved connection here is the last point at which we
     * can abort before a single table is dropped. If the connection is not an
     * in-memory sqlite (or a clearly-named *test* database), we throw and the
     * whole run stops — regardless of cached config.
     */
    /**
     * Once per phpunit process: wipe the DEDICATED test Redis databases
     * (phpunit.xml pins REDIS_DB=13 / REDIS_CACHE_DB=14 — see the ops
     * incident where tests hit prod Redis). Unlike sqlite :memory:, those
     * DBs persist across runs, so fixed-window counters leak between runs:
     * found 2026-07-23 when MozSpendMeter's monthly row counter reached its
     * 40-row cap purely from accumulated test runs and two Moz tests started
     * failing with silent stale-nulls. Guarded hard: flush ONLY dbs 13/14 —
     * anything else aborts the run (same philosophy as the DB guard below).
     */
    private static bool $testRedisFlushed = false;

    protected function setUpTraits()
    {
        $this->guardAgainstNonTestDatabase();
        $this->flushDedicatedTestRedisOnce();

        return parent::setUpTraits();
    }

    private function flushDedicatedTestRedisOnce(): void
    {
        if (self::$testRedisFlushed) {
            return;
        }
        self::$testRedisFlushed = true;

        $default = (int) config('database.redis.default.database', -1);
        $cache = (int) config('database.redis.cache.database', -1);
        if ($default !== 13 || $cache !== 14) {
            throw new \RuntimeException(
                "REFUSING TO FLUSH REDIS: test run resolved redis dbs [default={$default}, cache={$cache}] "
                ."instead of the dedicated test dbs [13, 14] pinned in phpunit.xml. "
                ."This almost always means config is CACHED — run: php artisan config:clear"
            );
        }

        try {
            \Illuminate\Support\Facades\Redis::connection()->flushdb();
            \Illuminate\Support\Facades\Redis::connection('cache')->flushdb();
        } catch (\Throwable) {
            // Redis down: tests that need it will fail on their own terms.
        }
    }

    private function guardAgainstNonTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        $isMemorySqlite = $driver === 'sqlite' && $database === ':memory:';
        $looksLikeTestDb = $database !== '' && str_contains(strtolower($database), 'test');

        if ($isMemorySqlite || $looksLikeTestDb) {
            return;
        }

        throw new \RuntimeException(
            "REFUSING TO RUN TESTS against a non-test database "
            ."[connection={$connection}, driver={$driver}, database={$database}].\n"
            ."Tests must use sqlite :memory: (see phpunit.xml) or a database whose name contains 'test'.\n"
            ."This almost always means config is CACHED to production. Fix it first:\n"
            ."    php artisan config:clear\n"
            ."This guard exists because a cached-config test run once ran migrate:fresh on production and destroyed data."
        );
    }
}
