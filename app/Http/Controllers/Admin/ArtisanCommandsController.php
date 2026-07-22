<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Admin-only Artisan commands reference. Documents every `ebq:*` console
 * command shipped in `app/Console/Commands/` — name, signature, args,
 * options, schedule, category, destructiveness, and operator notes.
 *
 * The canonical signature/description comes from the framework
 * (Artisan::all() → Symfony Command objects) so the page can't drift
 * from what `php artisan list` shows. Everything else is curated in the
 * static $catalog below — when a new command lands in
 * app/Console/Commands/, add a row here so it appears with full notes.
 */
class ArtisanCommandsController extends Controller
{
    /**
     * Curated metadata keyed by Artisan command name. Categories drive
     * the section grouping in the view; `schedule` reflects what's wired
     * in routes/console.php at the time of writing — keep in sync if you
     * touch the scheduler.
     *
     * @var array<string, array{
     *     category: string,
     *     schedule: ?string,
     *     destructive: bool,
     *     notes: string,
     *     examples: list<string>
     * }>
     */
    private const CATALOG = [
        // ─── Daily sync + reporting ────────────────────────────────
        'ebq:sync-daily-data' => [
            'category' => 'Daily sync',
            'schedule' => 'Daily (auto)',
            'destructive' => false,
            'notes' => 'Refresh GA4 + Search Console data for every connected website. Idempotent — re-running fetches any missing days without re-billing for already-stored ones. Safe to invoke manually if a sync job missed.',
            'examples' => ['php artisan ebq:sync-daily-data'],
        ],
        'ebq:detect-traffic-drops' => [
            'category' => 'Daily sync',
            'schedule' => 'Daily at 07:30',
            'destructive' => false,
            'notes' => 'Dispatches the TrafficAnomalyDetector job per website. Looks at the last fully-synced GSC day vs. the rolling baseline; writes anomaly rows that drive the dashboard insight cards + growth-report email.',
            'examples' => ['php artisan ebq:detect-traffic-drops'],
        ],
        'ebq:send-reports' => [
            'category' => 'Daily sync',
            'schedule' => 'Daily at 08:00',
            'destructive' => false,
            'notes' => 'Queues one growth-report email per website recipient, snapped to the most recent fully-synced GSC day. Per-recipient toggles live on the Settings → Report Recipients screen.',
            'examples' => ['php artisan ebq:send-reports'],
        ],

        // ─── Rank tracker + keyword metrics ────────────────────────
        'ebq:track-rankings' => [
            'category' => 'Rank tracker',
            'schedule' => 'Hourly',
            'destructive' => false,
            'notes' => 'Dispatches a TrackKeywordRankJob for every active rank_tracking_keywords row whose next_check_at has elapsed. `--force` ignores the schedule and re-checks every active keyword (expensive — burns Serper credits).',
            'examples' => [
                'php artisan ebq:track-rankings',
                'php artisan ebq:track-rankings --force',
            ],
        ],
        'ebq:fetch-keyword-metrics' => [
            'category' => 'Rank tracker',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Manual: queue Keywords Everywhere lookups for GSC queries above the impression threshold. Used to backfill volume/CPC/competition for newly imported keywords.',
            'examples' => ['php artisan ebq:fetch-keyword-metrics'],
        ],

        // ─── Backlinks + audits ────────────────────────────────────
        'ebq:fetch-competitor-backlinks' => [
            'category' => 'Backlinks',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'On-demand: fetch or refresh competitor backlinks for one audit row, one website, or specific domains. Hits Keywords Everywhere\'s domain-backlinks endpoint — every call costs credits, so use the freshness gate (default) rather than --force unless you know why.',
            'examples' => ['php artisan ebq:fetch-competitor-backlinks'],
        ],
        'ebq:auto-discover-prospects' => [
            'category' => 'Backlinks',
            'schedule' => 'Daily at 03:30',
            'destructive' => false,
            'notes' => 'Walks each website\'s recent page audits for competitor domains and seeds the AI Backlink Prospects list. Freshness-gated, so re-runs are KE-safe. `--days=` overrides the audit look-back window (default 30).',
            'examples' => [
                'php artisan ebq:auto-discover-prospects',
                'php artisan ebq:auto-discover-prospects --days=14',
            ],
        ],

        // ─── GSC backfill / cleanup ────────────────────────────────
        'ebq:import-historical' => [
            'category' => 'GSC backfill',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Pulls the full historical window from GA4 + Search Console for the specified website(s). Long-running; safe to interrupt — picks up where it left off on next run.',
            'examples' => ['php artisan ebq:import-historical'],
        ],
        'ebq:resync-gsc' => [
            'category' => 'GSC backfill',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Queues SyncSearchConsoleData jobs with an extended look-back to backfill the country + device dimensions. Use when GSC adds a property or a permission change unlocks data the daily sync skipped.',
            'examples' => ['php artisan ebq:resync-gsc'],
        ],
        'ebq:purge-empty-country-gsc' => [
            'category' => 'GSC backfill',
            'schedule' => null,
            'destructive' => true,
            'notes' => 'DESTRUCTIVE. Removes legacy country=\'\' (empty-string) rows from search_console_data older than the active resync window. One-time cleanup after the country-dimension migration; do not run on a fresh DB.',
            'examples' => ['php artisan ebq:purge-empty-country-gsc'],
        ],
        'ebq:purge-sync-data' => [
            'category' => 'GSC backfill',
            'schedule' => null,
            'destructive' => true,
            'notes' => 'DESTRUCTIVE. Bulk-deletes sync data for one or more websites. Used to recover from a corrupted import — confirm the target website ID before running.',
            'examples' => ['php artisan ebq:purge-sync-data'],
        ],

        // ─── Websites ──────────────────────────────────────────────
        'ebq:delete-website-data' => [
            'category' => 'Websites',
            'schedule' => null,
            'destructive' => true,
            'notes' => 'DESTRUCTIVE. Deletes a website row plus every related artefact (GSC + GA4 data, audits, tracked keywords, snapshots, niches, research, …). `--all` wipes every website — only ever use on a scratch DB.',
            'examples' => [
                'php artisan ebq:delete-website-data 123',
                'php artisan ebq:delete-website-data --all',
            ],
        ],

        // ─── WordPress plugin ──────────────────────────────────────
        'ebq:package-plugin' => [
            'category' => 'WordPress plugin',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Zips ebq-wordpress-plugin/ into public/downloads/ebq-seo.zip for public download. Run after every plugin release before the marketing download link reflects the new build.',
            'examples' => [
                'php artisan ebq:package-plugin',
                'php artisan ebq:package-plugin --output=storage/app/ebq-seo-3.0.zip',
            ],
        ],
        'ebq:apply-plugin-version' => [
            'category' => 'WordPress plugin',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Rewrites the Version: header + EBQ_SEO_VERSION constant in ebq-wordpress-plugin/ebq-seo.php. Requires write access to the plugin source — run as the deploy user.',
            'examples' => ['php artisan ebq:apply-plugin-version 3.1.0'],
        ],
        'ebq:publish-scheduled-plugin-releases' => [
            'category' => 'WordPress plugin',
            'schedule' => 'Every minute',
            'destructive' => false,
            'notes' => 'Promotes plugin_releases rows whose publish_at has elapsed from scheduled → published. Hot path — keep cheap. Idempotent; safe to re-run any time.',
            'examples' => ['php artisan ebq:publish-scheduled-plugin-releases'],
        ],

        // ─── Research engine (Phase-2 pipelines) ───────────────────
        'ebq:research-enrich-new-keywords' => [
            'category' => 'Research engine',
            'schedule' => 'Daily at 02:00',
            'destructive' => false,
            'notes' => 'Enqueues EnrichKeywordJob for keywords with no intelligence row, or whose metrics are stale. Drives the volume/CPC/competition signal that powers research scoring.',
            'examples' => ['php artisan ebq:research-enrich-new-keywords'],
        ],
        'ebq:research-cluster-refresh' => [
            'category' => 'Research engine',
            'schedule' => 'Weekly (Sun 03:00)',
            'destructive' => false,
            'notes' => 'Re-clusters recently-snapshotted keywords using top-10 SERP overlap (Jaccard). Drives the topic-cluster UI; safe to re-run on demand if a cluster looks stale.',
            'examples' => ['php artisan ebq:research-cluster-refresh'],
        ],
        'ebq:niche-aggregates-recompute' => [
            'category' => 'Research engine',
            'schedule' => 'Daily at 04:30',
            'destructive' => false,
            'notes' => 'Rebuilds the anonymised niche_aggregates table (n≥3 sample floor for privacy). `--sync` runs in-process instead of dispatching, useful for debugging.',
            'examples' => [
                'php artisan ebq:niche-aggregates-recompute',
                'php artisan ebq:niche-aggregates-recompute --sync',
            ],
        ],
        'ebq:reclassify-niches' => [
            'category' => 'Research engine',
            'schedule' => 'Monthly (1st 04:00)',
            'destructive' => false,
            'notes' => 'Dispatches ClassifyWebsiteNichesJob for every website. Slow but cheap — only LLM calls if niche classification has changed for that site.',
            'examples' => ['php artisan ebq:reclassify-niches'],
        ],
        'ebq:discover-emerging-niches' => [
            'category' => 'Research engine',
            'schedule' => 'Weekly (Mon 05:00)',
            'destructive' => false,
            'notes' => 'Phase-2 stub: dispatches DiscoverEmergingNichesJob. Persistence lands in Phase-3 — until then, output is observability-only.',
            'examples' => ['php artisan ebq:discover-emerging-niches'],
        ],
        'ebq:research-volatility-scan' => [
            'category' => 'Research engine',
            'schedule' => 'Daily at 06:00',
            'destructive' => false,
            'notes' => 'Scores SERP volatility per keyword using top-10 Jaccard between consecutive snapshots. Feeds the volatility column in the keyword UI + the SERP volatility alert.',
            'examples' => ['php artisan ebq:research-volatility-scan'],
        ],
        'ebq:detect-research-signals' => [
            'category' => 'Research engine',
            'schedule' => 'Daily at 07:45',
            'destructive' => false,
            'notes' => 'Emits ranking_drop / serp_change / volatility_spike / new_opportunity rows into keyword_alerts. Drives the research-signals inbox on the admin dashboard.',
            'examples' => ['php artisan ebq:detect-research-signals'],
        ],
        'ebq:research-scan-next' => [
            'category' => 'Research engine',
            'schedule' => 'Every 15 min',
            'destructive' => false,
            'notes' => 'Continuous research engine tick: picks the next queued research_targets row(s) and dispatches a competitor scrape. Throttle (per-tick count) is configurable via env.',
            'examples' => ['php artisan ebq:research-scan-next'],
        ],
        'ebq:research-bootstrap-websites' => [
            'category' => 'Research engine',
            'schedule' => 'Daily at 05:30',
            'destructive' => false,
            'notes' => 'Dispatches DiscoverCompetitorsForWebsiteJob for websites that have GSC data but no SERP-derived competitor research yet. Idempotent — skips sites that are already bootstrapped.',
            'examples' => ['php artisan ebq:research-bootstrap-websites'],
        ],
        'ebq:research-backfill' => [
            'category' => 'Research engine',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'One-off backfill: populates Research keywords + website_pages from existing GSC data, seeds the niche taxonomy. Run after enabling Research on a previously-synced website.',
            'examples' => ['php artisan ebq:research-backfill'],
        ],
        'ebq:research-rollout' => [
            'category' => 'Research engine',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Inspect-only: prints the current rollout gate (mode, allowlist) and checks whether a specific website ID would have Research enabled. Use to debug "why isn\'t Research on for client X?".',
            'examples' => ['php artisan ebq:research-rollout', 'php artisan ebq:research-rollout --website=42'],
        ],
        'ebq:competitor-scrape' => [
            'category' => 'Research engine',
            'schedule' => null,
            'destructive' => false,
            'notes' => 'Manual: queue a single competitor scrape. The canonical path is the admin UI (Research → Competitor scans); this is the CLI escape hatch for one-offs.',
            'examples' => ['php artisan ebq:competitor-scrape'],
        ],
    ];

    public function index(): View
    {
        $byName = [];
        foreach (Artisan::all() as $name => $cmd) {
            if (! $cmd instanceof SymfonyCommand) {
                continue;
            }
            // Only document commands shipped in app/Console/Commands/. Skip
            // framework, Cashier, vendor commands — the page is for
            // operator-facing custom commands.
            $class = get_class($cmd);
            if (! str_starts_with($class, 'App\\Console\\Commands\\')) {
                continue;
            }
            $definition = $cmd->getDefinition();
            $args = [];
            foreach ($definition->getArguments() as $arg) {
                $args[] = [
                    'name' => $arg->getName(),
                    'required' => $arg->isRequired(),
                    'array' => $arg->isArray(),
                    'description' => $arg->getDescription(),
                    'default' => $arg->getDefault(),
                ];
            }
            $opts = [];
            foreach ($definition->getOptions() as $opt) {
                $opts[] = [
                    'name' => $opt->getName(),
                    'shortcut' => $opt->getShortcut(),
                    'accept_value' => $opt->acceptValue(),
                    'is_value_required' => $opt->isValueRequired(),
                    'description' => $opt->getDescription(),
                    'default' => $opt->getDefault(),
                ];
            }
            $catalog = self::CATALOG[$name] ?? [
                'category' => 'Uncategorised',
                'schedule' => null,
                'destructive' => false,
                'notes' => '',
                'examples' => ['php artisan '.$name],
            ];
            $byName[$name] = [
                'name'        => $name,
                'class'       => $class,
                'description' => $cmd->getDescription(),
                'synopsis'    => $cmd->getSynopsis(),
                'arguments'   => $args,
                'options'     => $opts,
                'hidden'      => $cmd->isHidden(),
            ] + $catalog;
        }

        // Group by category, preserving the order seeds appeared in
        // CATALOG (so "Daily sync" comes before "Research engine", etc.).
        $categoryOrder = array_values(array_unique(array_map(
            fn ($row) => $row['category'],
            self::CATALOG,
        )));
        $categoryOrder[] = 'Uncategorised';

        $groups = [];
        foreach ($categoryOrder as $cat) {
            $groups[$cat] = [];
        }
        foreach ($byName as $row) {
            if ($row['hidden']) {
                continue;
            }
            $groups[$row['category']][] = $row;
        }
        foreach ($groups as $cat => $rows) {
            usort($groups[$cat], fn ($a, $b) => strcmp($a['name'], $b['name']));
            if (empty($groups[$cat])) {
                unset($groups[$cat]);
            }
        }

        return view('admin.commands.index', [
            'groups' => $groups,
            'total'  => count($byName),
        ]);
    }
}
