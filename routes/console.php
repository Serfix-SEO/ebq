<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ebq:sync-daily-data')->daily();
Schedule::command('ebq:detect-traffic-drops')->dailyAt('07:30');
Schedule::command('ebq:send-reports')->dailyAt('08:00');
Schedule::command('ebq:track-rankings')->hourly();
// Nightly auto-discovery of backlink prospects from each website's recent
// page audits. Idempotent + freshness-gated, so re-runs are KE-safe.
Schedule::command('ebq:auto-discover-prospects')->dailyAt('03:30');
Schedule::command('ebq:publish-scheduled-plugin-releases')->everyMinute();

// Trial expiry: countdown emails (expiry/48h/24h/12h) + data deletion 3 days
// after the Trial plan's trial_days. Hourly — the 12h stage needs sub-daily
// resolution. Disabled entirely while trial_days = 0.
Schedule::command('ebq:trial-cleanup')->hourly()->withoutOverlapping();

// One-shot 30%-discount promo to ACTIVE trial users (day 2+ of trial, once
// per user ever — users.trial_discount_email_sent_at). Expired users get the
// same offer via the h24 countdown email above instead.
Schedule::command('ebq:send-trial-discount-emails')->dailyAt('09:15')->withoutOverlapping();

// Failed-job visibility (2026-07-06 incident: crawl jobs died on the worker for
// 3 days, seen only in failed_jobs). Queue::failing() on every box buffers into
// shared Redis; this drains + mails admins a digest. Empty buffer = no mail.
Schedule::command('ebq:failed-jobs-alert')->everyFifteenMinutes()->withoutOverlapping();

// Content-hash re-audit gate (2026-07-06): independently re-fetches a bounded
// batch of the oldest completed audits and queues a re-audit only if the
// page's actual content changed — catches WordPress not bumping `modified`
// correctly, which the live-score path's timestamp-only staleness check
// otherwise misses. Batched + rate-limited by --limit, safe to run often.
Schedule::command('ebq:recheck-audit-content --limit=200')->hourly()->withoutOverlapping();

// Site crawler. Weekly full recrawl (conditional-GET + content-hash keep it
// cheap — every URL is re-verified, unchanged pages cost a 304/no re-parse). A
// daily sitemap-delta check crawls brand-new sitemap URLs within a day instead
// of waiting for the weekly pass. One-off backfill of existing never-crawled
// sites is run manually after deploy: `php artisan ebq:crawl-websites --backfill`.
Schedule::command('ebq:crawl-websites')->weeklyOn(1, '02:00');
Schedule::command('ebq:crawl-websites --sitemap-deltas')->dailyAt('04:30');
// Watchdog: resume/finalize crawl runs whose multi-pass chain died (worker recycle
// dropped the Bus::batch callback). withoutOverlapping so a slow tick can't stack.
Schedule::command('ebq:crawl-supervisor')->everyFiveMinutes()->withoutOverlapping();

// Crawl-worker fleet autoscaler — scale boxes up/down to match crawl backlog (no-op
// until autoscaler.enabled). + a 5-min Hetzner health refresh for the fleet.
Schedule::command('ebq:fleet-autoscale')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('ebq:check-worker-nodes')->everyFiveMinutes()->withoutOverlapping();

// Keep the self-hosted keyword API fleet's health/queue snapshot warm so the
// load balancer routes to live, least-busy servers.
Schedule::command('ebq:check-keyword-servers')->everyFiveMinutes();

// Terminal backstop for keyword requests whose result webhook never arrived
// (node crash / lost delivery): fail them so they don't sit `running` forever.
Schedule::command('ebq:reap-stuck-keyword-requests')->everyTenMinutes()->withoutOverlapping();

// Smart per-domain crawl-rate controller (AIMD): ramp each crawling domain up while it's
// healthy, back it off the moment latency climbs or it blocks. See DomainRateLimiter.
Schedule::command('ebq:ramp-crawl-rates')->everyMinute()->withoutOverlapping();

// Horizon metrics snapshot — powers the throughput/runtime graphs on /horizon.
// Runs on the web box's scheduler; metrics live in the shared Redis so they cover
// every box's supervisors.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Keep the crawl-worker snapshot in sync with the deployed code: rebuild it when git
// HEAD drifts, then point the autoscaler at it. Background (a build is ~15 min) +
// withoutOverlapping + an internal lock so it never double-builds or blocks the
// scheduler. No-op unless the autoscaler's `auto_snapshot` kill-switch is on.
Schedule::command('ebq:refresh-worker-snapshot')->hourly()->withoutOverlapping(30)->runInBackground();

// Import NEW candidate proxies from free public lists (iplocate + proxifly).
// OFF by default (CRAWLER_PROXY_AUTO_IMPORT) — the command can always be run
// manually via artisan or the admin /admin/proxies "Import now" button.
Schedule::command('ebq:proxy-list-refresh')->everyThirtyMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('crawler.proxy.auto_import'));

// Auto-prune disabled — run manually via: php artisan ebq:proxy-pool-prune
// Schedule::command('ebq:proxy-pool-prune')->everyFifteenMinutes()->withoutOverlapping();
