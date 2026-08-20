<?php

namespace App\Console\Commands;

use App\Models\Proxy;
use App\Services\Crawler\ProxyPool;
use Illuminate\Console\Command;

/**
 * Continuously health-checks every already-tracked proxy. HEALTH TRACKING
 * ONLY — it never deletes or deactivates a row (owner 2026-08-20): the pool
 * is paid residential proxies, and the old one-bad-ping-deletes-it semantics
 * (designed for disposable free-list proxies) wiped paid credentials on a
 * transient provider hiccup. A failing check bumps fail_count so the admin
 * screen shows the problem; a passing check resets it.
 */
class PruneDeadProxies extends Command
{
    protected $signature = 'ebq:proxy-pool-prune {--concurrency=25} {--timeout=6}';

    protected $description = 'Live-test every tracked proxy and record health (never deletes).';

    public function handle(ProxyPool $pool): int
    {
        $concurrency = max(1, (int) $this->option('concurrency'));
        $timeout = max(1, (int) $this->option('timeout'));

        $proxies = Proxy::all(['id', 'url']);
        if ($proxies->isEmpty()) {
            $this->info('No tracked proxies to check.');

            return self::SUCCESS;
        }

        $byUrl = $proxies->keyBy('url');
        $results = $pool->testBatch($proxies->pluck('url')->all(), $concurrency, $timeout);

        $healthy = $failing = 0;
        foreach ($results as $url => $ok) {
            $proxy = $byUrl->get($url);
            if (! $proxy) {
                continue;
            }
            if ($ok) {
                $healthy++;
                $proxy->update(['fail_count' => 0, 'success_count' => $proxy->success_count + 1, 'last_used_at' => now(), 'last_ok_at' => now()]);
            } else {
                $failing++;
                $proxy->increment('fail_count');
            }
        }

        $this->info("Done: {$healthy} healthy, {$failing} failing (kept — health tracking only).");

        return self::SUCCESS;
    }
}
