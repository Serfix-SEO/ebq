<?php

namespace App\Console\Commands;

use App\Jobs\Ops\DeployVerifyJob;
use App\Support\CodeFingerprint;
use App\Support\Queues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Post-deploy smoke test for the queue fleet: dispatch a no-op probe to every
 * queue and report which worker picked it up, on which box, with which code.
 *
 * Run this after EVERY deploy that touches worker code. The failure it exists
 * to catch is silent: `queue:work` processes hold their classes for life and
 * box B's containers bind-mount their own host directory, so "rsynced the
 * files" and "the workers run them" are different claims. On 2026-08-14→16 the
 * gap between those two lasted two days.
 *
 *   php artisan ebq:deploy-verify
 *   php artisan ebq:deploy-verify --wait=60
 */
class DeployVerify extends Command
{
    protected $signature = 'ebq:deploy-verify
        {--wait=45 : Seconds to wait for the probes to come back}
        {--queues= : Comma-separated queue list (default: every queue we run)}';

    protected $description = 'Prove every queue worker is running the currently deployed code';

    public function handle(): int
    {
        $queues = $this->option('queues')
            ? array_map('trim', explode(',', (string) $this->option('queues')))
            : [
                Queues::INTERACTIVE, Queues::DEFAULT, Queues::SYNC,
                Queues::CRAWL, Queues::CRAWL_FINALIZE, Queues::CONTENT,
                Queues::LINK_CRAWL,
            ];

        $token = (string) Str::ulid();
        $expected = CodeFingerprint::current();
        $this->line('Dispatcher fingerprint: <info>'.$expected.'</info> (this box: '.gethostname().')');

        foreach ($queues as $queue) {
            DeployVerifyJob::dispatch($token, $queue, $expected);
        }

        $deadline = time() + max(5, (int) $this->option('wait'));
        $results = [];
        $bar = $this->output->createProgressBar(count($queues));

        while (time() < $deadline && count($results) < count($queues)) {
            foreach ($queues as $queue) {
                if (isset($results[$queue])) {
                    continue;
                }
                if ($hit = Cache::get(DeployVerifyJob::cacheKey($token, $queue))) {
                    $results[$queue] = $hit;
                    $bar->advance();
                }
            }
            if (count($results) < count($queues)) {
                usleep(500_000);
            }
        }
        $bar->finish();
        $this->newLine(2);

        $rows = [];
        $bad = 0;
        foreach ($queues as $queue) {
            $r = $results[$queue] ?? null;
            if ($r === null) {
                $rows[] = [$queue, '—', '—', '—', '<error>NO WORKER</error>'];
                $bad++;

                continue;
            }
            $ok = (bool) ($r['matches'] ?? false);
            $bad += $ok ? 0 : 1;
            $rows[] = [
                $queue,
                $r['host'].' ('.$r['app_env'].')',
                'redis '.$r['redis_host'],
                $r['fingerprint'],
                $ok ? '<info>current</info>' : '<error>STALE CODE</error>',
            ];
        }

        $this->table(['queue', 'worker', 'wiring', 'fingerprint', 'verdict'], $rows);

        if ($bad > 0) {
            $this->error($bad.' queue(s) failed. A stale worker means restart it; "NO WORKER" means nothing drains that queue.');

            return self::FAILURE;
        }

        $this->info('All '.count($queues).' queues answered on the deployed code.');

        return self::SUCCESS;
    }
}
