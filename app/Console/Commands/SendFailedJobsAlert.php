<?php

namespace App\Console\Commands;

use App\Mail\FailedJobsDigestMail;
use App\Models\ContentAeoBotHit;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentProductRun;
use App\Models\CrawlSite;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Aeo\AeoSignalReader;
use App\Services\Reports\DataForSeoSpendMeter;
use App\Support\ContentAutopilotConfig;
use App\Support\ContentImageHealth;
use App\Support\FailedJobAlertBuffer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Drains the shared-Redis failed-job buffer (fed in real time by
 * `Queue::failing()` on every box — see FailedJobAlertBuffer) and mails a
 * digest to platform admins. Also flags crawl_sites that have subscribers but
 * have sat `pending` (never crawled) for over a day — the other blind spot
 * from the 2026-07-06 incident: a job that dies BEFORE creating a CrawlRun is
 * invisible to the crawl supervisor.
 *
 * Runs on the WEB box only (scheduler lives there), so mail goes out through
 * the local Postal relay regardless of which box the failures happened on.
 * No cooldown needed: the drain empties the buffer, so a repeat mail only
 * happens when NEW failures land.
 */
class SendFailedJobsAlert extends Command
{
    protected $signature = 'ebq:failed-jobs-alert {--dry-run : Print the digest instead of mailing}';

    protected $description = 'Mail admins a digest of recently failed queue jobs + never-crawled stuck sites.';

    public function handle(): int
    {
        // Dry-run must not consume the buffer — a peeked entry still gets
        // mailed by the next real run.
        $failures = $this->option('dry-run')
            ? FailedJobAlertBuffer::peek()
            : FailedJobAlertBuffer::drain();

        $stuckPending = CrawlSite::query()
            ->where('status', 'pending')
            ->where('subscriber_count', '>', 0)
            ->where('created_at', '<', now()->subDay())
            ->get(['id', 'normalized_domain', 'created_at']);

        // DataForSEO spend circuit-breaker warning (80% / 100% of the monthly
        // cap). Runs every 15 min, so a cache flag limits each threshold to
        // ONE digest line per day. Admin-only — clients never see spend state.
        $spendLine = null;
        $meter = app(DataForSeoSpendMeter::class);
        if ($meter->nearCap()) {
            $threshold = $meter->exhausted() ? '100' : '80';
            $flag = 'dfs-spend-warned:'.now()->utc()->format('Y-m-d').':'.$threshold;
            if (Cache::add($flag, true, now()->addDay())) {
                $spendLine = sprintf(
                    'DataForSEO spend: $%.2f of the $%.2f monthly cap%s',
                    $meter->spent(), $meter->cap(),
                    $meter->exhausted()
                        ? ' — CAP REACHED: lookups now serve free-signal partials, TTL refreshes paused, own-site first reports still generate. Raise DATAFORSEO_MONTHLY_CAP_USD to resume.'
                        : ' (80%+ warning).'
                );
            }
        }

        $imageLine = $this->imageHealthLine();
        $aeoLine = $this->aeoIngestLine();

        // Collapse repeats (2026-08-26: ONE broken Hindi article re-failed on
        // every 15-min dispatcher tick → six identical digest emails). Each
        // job+exception fingerprint that already triggered a mail stays muted
        // for REALERT_HOURS while its count accumulates in cache; it re-mails
        // with the cumulative total once the window lapses. Fresh fingerprints
        // always mail. A digest containing ONLY muted repeats sends nothing.
        $freshGroups = [];
        $mutedGroups = [];
        foreach (collect($failures)->groupBy(fn ($f) => $this->fingerprint($f)) as $fp => $rows) {
            $cached = Cache::get('failed-digest:fp:'.$fp);
            $muted = is_array($cached)
                && (now()->timestamp - (int) ($cached['mailed_at'] ?? 0)) < self::REALERT_HOURS * 3600;
            $group = [
                'fp' => (string) $fp,
                'rows' => $rows,
                'prior' => (int) ($cached['count'] ?? 0),
            ];
            $muted ? $mutedGroups[] = $group : $freshGroups[] = $group;
        }

        // Strict Product Mode: failed catalog runs on strict plans mean a
        // client is blocked on their progress screen — surface within a day.
        // One digest line per run (cache flag), so a stuck run doesn't repeat.
        $failedCatalogRuns = ContentProductRun::query()
            ->where('status', ContentProductRun::STATUS_FAILED)
            ->where('finished_at', '>', now()->subDay())
            ->latest('finished_at')
            ->get()
            // Only a site whose MOST RECENT scan failed still has a problem.
            // A fixed site kept being reported for another 24h: ergospace.ae
            // failed twice on 2026-09-25, was fixed, re-scanned successfully
            // minutes later, and the digest still told the owner to go and
            // check the shop (2026-09-26). Chasing a resolved alert is how an
            // alarm stops being believed.
            ->filter(function ($run) {
                $latestId = ContentProductRun::query()
                    ->where('website_id', $run->website_id)
                    ->max('id');

                return (string) $latestId === (string) $run->id;
            })
            ->filter(fn ($run) => $this->option('dry-run')
                ? ! Cache::has('failed-digest:catalog:'.$run->id)
                : Cache::add('failed-digest:catalog:'.$run->id, true, now()->addDays(3)));

        if ($freshGroups === [] && $stuckPending->isEmpty() && $spendLine === null
            && $failedCatalogRuns->isEmpty() && $imageLine === null && $aeoLine === null) {
            $this->rememberGroups($freshGroups, $mutedGroups);
            $this->info($mutedGroups === []
                ? 'Nothing to report.'
                : 'Only already-reported failures repeating — digest suppressed.');

            return self::SUCCESS;
        }

        $lines = [];
        if ($imageLine !== null) {
            $lines[] = $imageLine;
            $lines[] = '';
        }
        if ($aeoLine !== null) {
            $lines[] = $aeoLine;
            $lines[] = '';
        }
        if ($spendLine !== null) {
            $lines[] = $spendLine;
            $lines[] = '';
        }
        if ($freshGroups !== []) {
            $lines[] = collect($freshGroups)->sum(fn ($g) => $g['rows']->count()).' queue job(s) failed permanently since the last digest:';
            foreach ($freshGroups as $g) {
                $first = $g['rows']->first();
                $lines[] = sprintf(
                    '  %s ×%d%s  [queue=%s box=%s]',
                    $first['job'] ?? '?',
                    $g['rows']->count(),
                    $g['prior'] > 0 ? ' (+'.$g['prior'].' reported earlier)' : '',
                    $first['queue'] ?? '?', $first['box'] ?? '?'
                );
                $lines[] = '    latest: '.($first['exception'] ?? '');
            }
        }
        if ($mutedGroups !== []) {
            $lines[] = '';
            $lines[] = 'Still repeating (same exception already reported — muted for '.self::REALERT_HOURS.'h):';
            foreach ($mutedGroups as $g) {
                $first = $g['rows']->first();
                $lines[] = sprintf('  %s ×%d total  [queue=%s]',
                    $first['job'] ?? '?', $g['prior'] + $g['rows']->count(), $first['queue'] ?? '?');
            }
        }
        if ($freshGroups !== [] || $mutedGroups !== []) {
            $lines[] = '';
            $lines[] = 'Full stack traces: /horizon (Failed) or the failed_jobs table.';
        }

        if ($stuckPending->isNotEmpty()) {
            $lines[] = '';
            $lines[] = $stuckPending->count().' crawl site(s) with subscribers have NEVER been crawled (pending >24h):';
            foreach ($stuckPending as $site) {
                $lines[] = '  '.$site->normalized_domain.' (since '.$site->created_at->toDateString().')';
            }
            $lines[] = 'These never created a CrawlRun, so the crawl supervisor cannot see them.';
        }

        if ($failedCatalogRuns->isNotEmpty()) {
            $lines[] = '';
            $lines[] = $failedCatalogRuns->count().' product catalog run(s) failed in the last 24h:';
            foreach ($failedCatalogRuns as $run) {
                $domain = Website::query()->find($run->website_id)?->normalized_domain ?? $run->website_id;
                $lines[] = '  '.$domain.' — '.($run->error ?? '?').' (trigger='.$run->trigger.')';
            }
            $lines[] = 'Re-scan from /admin/clients (Product catalog card) after checking the shop.';
        }

        $body = implode("\n", $lines);

        if ($this->option('dry-run')) {
            $this->line($body);

            return self::SUCCESS;
        }

        $this->rememberGroups($freshGroups, $mutedGroups);

        $admins = User::query()->where('is_admin', true)->pluck('email')->filter()->values();
        if ($admins->isEmpty()) {
            $this->warn('No admin users to notify.');

            return self::SUCCESS;
        }

        Mail::to($admins->all())->send(new FailedJobsDigestMail(
            $body,
            count($failures),
            $stuckPending->count(),
        ));

        $this->info('Digest sent to '.$admins->implode(', '));

        return self::SUCCESS;
    }

    /** Hours a mailed fingerprint stays muted before it may re-alert. */
    private const REALERT_HOURS = 6;

    /**
     * "Image generation is down" — the alarm that did not exist for either of
     * the two silent blackouts (2026-08-17, 91 articles; 2026-09-11, ~250).
     *
     * Two independent detectors, because the two blackouts failed in different
     * places and neither one would have caught the other:
     *  - the PROVIDER refusing us (401/403, or no key) never reaches the
     *    articles table until the damage is done, so ContentImageHealth
     *    reports it the first time it happens;
     *  - the spend meter returns BEFORE the client is ever called, so no
     *    provider signal exists at all — only the articles themselves show it.
     *    Hence the count of finished articles that ended up with no images.
     *
     * One line per day per condition (cache flag), like the spend warning: a
     * five-day outage should nag daily, not every fifteen minutes.
     */
    /**
     * AI Visibility ingest health: a site that WAS reporting AI-crawler hits
     * and has gone quiet.
     *
     * Almost always a dead WordPress cron or an uninstalled plugin, and the
     * failure is invisible by construction — no job fails, no exception is
     * thrown, the page simply stops gaining data and starts implying the AI
     * crawlers lost interest. That is exactly the shape of the two multi-day
     * blackouts this digest already guards against.
     */
    private function aeoIngestLine(): ?string
    {
        $cutoff = now()->subDays(AeoSignalReader::STALE_AFTER_DAYS)->startOfDay();

        $silent = ContentAeoBotHit::query()
            ->select('website_id', DB::raw('MAX(hit_on) as last_report'))
            ->groupBy('website_id')
            ->havingRaw('MAX(hit_on) < ?', [$cutoff->toDateString()])
            ->get();

        if ($silent->isEmpty()) {
            return null;
        }

        // One line per site per week — a site that stays uninstalled must not
        // re-report every fifteen minutes.
        $fresh = $silent->filter(fn ($row) => $this->option('dry-run')
            ? ! Cache::has('failed-digest:aeo:'.$row->website_id)
            : Cache::add('failed-digest:aeo:'.$row->website_id, true, now()->addWeek()));

        if ($fresh->isEmpty()) {
            return null;
        }

        $names = Website::query()
            ->whereIn('id', $fresh->pluck('website_id'))
            ->pluck('domain', 'id');

        $detail = $fresh->take(5)
            ->map(fn ($row) => ($names[$row->website_id] ?? $row->website_id).' (last '.$row->last_report.')')
            ->implode(', ');

        return 'AI VISIBILITY: '.$fresh->count().' site(s) stopped reporting AI-crawler hits — '.$detail
            .'. Usually a dead WP cron or a removed plugin; the AI Visibility page will look like the crawlers left.';
    }

    private function imageHealthLine(): ?string
    {
        if (! ContentAutopilotConfig::imagesEnabled()) {
            return null;   // deliberately off — not a fault
        }

        $auth = ContentImageHealth::authBlocked();

        // Articles finished in the last day that have no image at all. Plans
        // that turned images off are excluded — that is a client's choice, not
        // a fault. Cheap: one indexed range scan plus a NOT EXISTS.
        $imageless = ContentArticle::query()
            ->where('content_articles.created_at', '>=', now()->subDay())
            ->where('is_current', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('content_images')
                ->whereColumn('content_images.article_id', 'content_articles.id')
                ->where('content_images.status', ContentImage::STATUS_GENERATED))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('content_topics')
                ->whereColumn('content_topics.id', 'content_articles.topic_id')
                ->whereNotExists(fn ($p) => $p->select(DB::raw(1))->from('content_plans')
                    ->whereColumn('content_plans.id', 'content_topics.plan_id')
                    ->where('content_plans.images_enabled', false)))
            ->count();

        // A trickle is normal (a rejected render, a plan with none left in the
        // budget); a wall of them is an outage.
        $outage = $auth !== null || $imageless >= self::IMAGELESS_ALERT_THRESHOLD;
        if (! $outage) {
            return null;
        }

        $condition = $auth !== null ? 'auth' : 'imageless';
        $flag = 'image-health-warned:'.now()->utc()->format('Y-m-d').':'.$condition;
        if ($this->option('dry-run')
            ? Cache::has($flag)
            : ! Cache::add($flag, true, now()->addDay())) {
            return null;   // already reported today
        }

        if ($auth !== null) {
            $since = Carbon::parse($auth['since']);

            return sprintf(
                'IMAGE GENERATION IS DOWN — the image provider has rejected our credentials (HTTP %d) since %s (%s, %d attempts). '
                .'Every article written since then ships with NO images and nothing fails, so this is the only warning you get. '
                .'Fix: put a valid IDEOGRAM_API_KEY in the env on the app box, then `php artisan config:cache`. '
                .'The alarm clears itself on the first successful image. '
                .'Articles already written can be repaired with `php artisan ebq:backfill-article-images --since="%s"` '
                .'(reports first; add --force to spend). %d article(s) in the last 24h have no images.',
                (int) $auth['status'],
                $since->diffForHumans(),
                $since->toDateTimeString(),
                (int) $auth['count'],
                $since->toDateTimeString(),
                $imageless,
            );
        }

        $last = ContentImageHealth::lastFailure();

        return sprintf(
            'IMAGE GENERATION LOOKS DOWN — %d article(s) finished in the last 24h with no images, on plans that want them. '
            .'Nothing fails when this happens, so check the monthly image spend cap first (a tripped meter returns before the '
            .'provider is ever called), then the provider itself.%s '
            .'Repair with `php artisan ebq:backfill-article-images` (reports first; add --force to spend).',
            $imageless,
            $last !== null ? ' Last provider error: '.$last['error'].' at '.$last['at'].'.' : '',
        );
    }

    /**
     * Imageless articles in 24h that mean "outage" rather than "a few renders
     * were rejected". Well under a normal day's output (40–70 articles), well
     * above the usual trickle of one-offs.
     */
    private const IMAGELESS_ALERT_THRESHOLD = 5;

    /**
     * Same-failure identity: job class + exception first line with ids and
     * numbers neutralized, so per-run ULIDs/timestamps don't defeat the
     * collapse.
     */
    private function fingerprint(array $failure): string
    {
        $normalized = preg_replace('/[0-9a-hjkmnp-tv-z]{20,}|\d+/i', '#', (string) ($failure['exception'] ?? ''));

        return md5(($failure['job'] ?? '?').'|'.$normalized);
    }

    /**
     * Persist the mute state: fresh groups start a new mute window from now;
     * muted groups keep their original window but accumulate the count.
     * Called on every non-dry-run path — including the suppressed-mail one —
     * so counts stay truthful.
     */
    private function rememberGroups(array $freshGroups, array $mutedGroups): void
    {
        foreach ($freshGroups as $g) {
            Cache::put('failed-digest:fp:'.$g['fp'], [
                'mailed_at' => now()->timestamp,
                'count' => $g['prior'] + $g['rows']->count(),
            ], now()->addDay());
        }
        foreach ($mutedGroups as $g) {
            $cached = Cache::get('failed-digest:fp:'.$g['fp']) ?? ['mailed_at' => now()->timestamp];
            Cache::put('failed-digest:fp:'.$g['fp'], [
                'mailed_at' => (int) ($cached['mailed_at'] ?? now()->timestamp),
                'count' => $g['prior'] + $g['rows']->count(),
            ], now()->addDay());
        }
    }
}
