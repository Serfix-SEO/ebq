<?php

namespace App\Jobs\Ops;

use App\Support\CodeFingerprint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Answers "is the worker that drains THIS queue actually running the code I
 * just deployed?" — from inside the worker process, which is the only place
 * that can answer it honestly.
 *
 * Files on disk prove nothing here. A `queue:work` process holds its classes
 * for its whole lifetime, and box B's containers bind-mount their host dir, so
 * a deploy that skips the restart leaves workers on old code indefinitely. That
 * exact gap ran for two days (2026-08-14→16): box B kept a cached config
 * pointing at 127.0.0.1 Redis, every crawl/sync job died, and 10 client sites
 * sat uncrawled while queue depth passed 250.
 *
 * Writes its findings to the shared cache under a caller-supplied token; the
 * dispatching command collects them. Deliberately does nothing else — no DB
 * writes, no external calls — so it is safe to run against production at any
 * time.
 */
class DeployVerifyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(
        public string $token,
        public string $queueName,
        public string $expectedFingerprint,
    ) {
        $this->onQueue($queueName);
    }

    public function handle(): void
    {
        $actual = CodeFingerprint::current();

        Cache::put(
            self::cacheKey($this->token, $this->queueName),
            [
                'host' => gethostname(),
                'app_env' => (string) config('app.env'),
                'redis_host' => (string) config('database.redis.default.host'),
                'db_host' => (string) config('database.connections.mysql.host'),
                'fingerprint' => $actual,
                'matches' => hash_equals($this->expectedFingerprint, $actual),
                'ran_at' => now()->toDateTimeString(),
            ],
            600,
        );
    }

    public static function cacheKey(string $token, string $queue): string
    {
        return 'ops:deploy-verify:'.$token.':'.$queue;
    }
}
