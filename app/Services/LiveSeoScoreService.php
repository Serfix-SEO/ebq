<?php

namespace App\Services;

use App\Jobs\RunCustomPageAudit;
use App\Jobs\SyncOwnBacklinksFromKeywordsEverywhere;
use App\Models\Backlink;
use App\Models\CustomPageAudit;
use App\Models\PageAuditReport;
use App\Models\PageIndexingStatus;
use App\Models\RankTrackingKeyword;
use App\Models\SearchConsoleData;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * "Live" SEO score — what Google actually thinks of the post, derived from
 * the full data we already have:
 *
 *   GSC                → rank, CTR vs expected curve, coverage, cannibalization
 *   PageIndexingStatus → Google verdict / coverage state (can the page even rank?)
 *   PageAuditReport    → Core Web Vitals, Lighthouse perf, on-page SEO,
 *                        technical health, content quality (the audit blob is
 *                        already huge — we just project useful slices into score factors)
 *   Backlink           → referring-domain authority signal
 *   RankTrackingKeyword → small "tracked = +confidence" nudge
 *
 * Audit gating
 * ────────────
 * If we've never audited this URL we enqueue a `CustomPageAudit` synchronously
 * via the existing background queue and return `audit.status = "queued"`. The
 * client polls until the audit completes; once a `PageAuditReport` exists we
 * don't re-audit (no content-hash check yet) — the user can manually re-audit
 * from HQ if they want fresh CWV/perf data.
 *
 * GSC gating (the "fresh URL" case)
 * ─────────────────────────────────
 * Every freshly-published URL spends the first ~1–7 days with zero GSC
 * impressions. Rather than blanking the entire score in that window, we
 * mark the four GSC-only factors as `pending` and let the rest of the
 * factor stack carry the weight. `partial: true` is set on the response
 * so the UI can render an advisory banner. Factor availability map:
 *
 *   GSC-only (pending without GSC):
 *     rank, ctr, coverage, cannibalization
 *
 *   Available without GSC:
 *     indexing (PageIndexingStatus, null-safe)
 *     backlinks (Backlink table + KE sync)
 *     core_web_vitals, page_performance, on_page_seo, technical_health,
 *     content_quality, keyword_alignment, recommendations (audit blob)
 *     tracked-keyword nudge
 *
 * → 9 of 13 factors compose the score on a brand-new URL.
 *
 * URL matching uses `PluginInsightResolver::__publicPageVariants()` so we
 * match GSC rows regardless of:
 *   - trailing slash drift  (`/abc/xyz` vs `/abc/xyz/`)
 *   - www vs apex            (`www.x.com` vs `x.com`)
 *   - scheme                 (`http://` vs `https://`)
 *   - case                   (lowercased URLs in storage)
 *
 * Pure data composition, no LLM call.
 *
 * @return array{
 *   score: int,
 *   label: string,
 *   available: bool,
 *   audit: array{status: string, message: string, audited_at?: string|null, queued_at?: string|null},
 *   factors: list<array<string, mixed>>,
 *   explanation: string,
 * }
 */
class LiveSeoScoreService
{
    public function __construct(
        private readonly PluginInsightResolver $resolver,
    ) {}

    public function score(Website $website, string $canonicalUrl, ?string $focusKeyword = null, ?\DateTimeInterface $postModifiedAt = null, string $postStatus = ''): array
    {
        // Normalize to Illuminate's Carbon so downstream comparisons (e.g.
        // `->greaterThan(...)`) work regardless of whether the caller passed
        // base `Carbon\Carbon`, Laravel's `Illuminate\Support\Carbon`, or
        // a plain `DateTime`. The variance trap (Illuminate\Support\Carbon
        // extends Carbon\Carbon, not the other way around) is otherwise
        // brittle across Laravel versions.
        $postModifiedAt = $postModifiedAt instanceof \DateTimeInterface
            ? Carbon::instance($postModifiedAt)
            : null;
        $url = trim($canonicalUrl);
        if ($url === '' || ! $website->isAuditUrlForThisSite($url)) {
            return $this->unavailable('url_not_for_website', ['url' => $url, 'domain' => $website->domain]);
        }

        $variants = $this->resolver->__publicPageVariants($url);
        $variantHashes = array_map(static fn (string $v) => hash('sha256', $v), $variants);

        $tz = config('app.timezone');
        $end = Carbon::yesterday($tz);
        $start30 = $end->copy()->subDays(29);

        // ── GSC totals (last 30 days) ───────────────────────────
        $gsc = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereDate('date', '>=', $start30->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->tap(fn ($q) => $this->resolver->__publicApplyPageMatch($q, $url))
            ->selectRaw('SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position, AVG(ctr) AS ctr')
            ->first();

        $impressions = (int) ($gsc->impressions ?? 0);
        $avgPos = $gsc && $gsc->position !== null ? (float) $gsc->position : null;
        $avgCtr = $gsc && $gsc->ctr !== null ? (float) $gsc->ctr : null;

        // GSC sample sufficiency. A handful of impressions on long-tail
        // queries gives a mathematically real but practically meaningless
        // "average position 69 across 2 queries" — scoring on that lies
        // to the user. Require a non-trivial sample before rank/CTR/
        // coverage/cannibalization are treated as real signal.
        //
        // Threshold: 10 impressions over the 30-day window. Below that
        // we treat GSC the same as "no data yet" — the four factors go
        // pending and the partial-state banner explains why.
        $MIN_GSC_IMPRESSIONS = 10;
        $gscHasData = $impressions >= $MIN_GSC_IMPRESSIONS;
        $gscIsSparse = $impressions > 0 && $impressions < $MIN_GSC_IMPRESSIONS;

        // ── Focus-keyword rank ──────────────────────────────────
        $kwRank = null;
        if ($gscHasData && $focusKeyword !== null && $focusKeyword !== '') {
            $kwRow = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereDate('date', '>=', $start30->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->tap(fn ($q) => $this->resolver->__publicApplyPageMatch($q, $url))
                ->whereRaw('LOWER(`query`) = ?', [mb_strtolower($focusKeyword)])
                ->selectRaw('AVG(position) AS position, SUM(impressions) AS impressions')
                ->first();
            if ($kwRow && $kwRow->position !== null && (int) $kwRow->impressions > 0) {
                $kwRank = (float) $kwRow->position;
            }
        }

        // ── Coverage breadth ─────────────────────────────────────
        $coverage = 0;
        if ($gscHasData) {
            $coverage = (int) SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereDate('date', '>=', $start30->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->tap(fn ($q) => $this->resolver->__publicApplyPageMatch($q, $url))
                ->where('query', '!=', '')
                ->where('position', '<=', 100)
                ->distinct('query')
                ->count('query');
        }

        // ── Cannibalization ──────────────────────────────────────
        $cannibalized = false;
        if ($gscHasData && $focusKeyword !== null && $focusKeyword !== '') {
            $competingPages = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereDate('date', '>=', $start30->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->whereRaw('LOWER(`query`) = ?', [mb_strtolower($focusKeyword)])
                ->whereNotIn('page', $variants)
                ->where('clicks', '>', 0)
                ->distinct('page')
                ->count('page');
            $cannibalized = $competingPages > 0;
        }

        // ── Latest audit + queue gating ──────────────────────────
        $latestAudit = PageAuditReport::query()
            ->where('website_id', $website->id)
            ->whereIn('page_hash', $variantHashes)
            ->latest('audited_at')
            ->first();

        $auditReady = $latestAudit
            && $latestAudit->status === 'completed'
            && is_array($latestAudit->result);

        // Stale = the post was edited in WP after the last audit ran.
        // We still show the existing audit's data (better than blank) but
        // queue a refresh so the breakdown updates once it's done.
        //
        // 60s tolerance absorbs clock skew between the WP host and the EBQ
        // host. Without it, a sub-minute drift can pin the score on
        // "refreshing" forever — every new audit completes a few seconds
        // BEFORE the WP clock thinks the post was modified, so it always
        // reads as stale and immediately re-queues.
        $auditStale = false;
        if ($auditReady && $postModifiedAt !== null && $latestAudit->audited_at !== null) {
            $diffSeconds = $postModifiedAt->getTimestamp() - $latestAudit->audited_at->getTimestamp();
            $auditStale = $diffSeconds > 60;
        }

        $auditState = $this->resolveAuditState($website, $url, $focusKeyword, $latestAudit, $auditReady, $auditStale, $postStatus);

        // When the audit is currently blocked (non-public post), the only
        // audit data we could possibly have is from when the post WAS
        // public — or worse, from an old fetch that hit a 404 / login
        // page (e.g. "Primary keyword missing from <title>" surfaced even
        // though the editor's title is fine). Suppress the stale audit
        // factors entirely while blocked; pending placeholders render
        // instead with a clear "publish to enable" message.
        if (($auditState['status'] ?? '') === 'blocked') {
            $auditReady = false;
        }

        // Also detect "audit completed but the fetch must have failed".
        // The audit result lives at:
        //   metadata.title              ← <title> tag text
        //   metadata.meta_description   ← <meta name=description content>
        //   content.headings[*]         ← {level, text} array
        //
        // If `<title>` is empty, the auditor didn't actually fetch a real
        // page (every rendered HTML has a title). That's a 404 / login
        // wall / placeholder-URL fetch — the keyword-placement factor
        // would then surface phantom "Missing from: SEO title, meta
        // description" recommendations even when the editor side is set
        // correctly. Treat as not-ready so the user gets a clear failed
        // message instead.
        if ($auditReady && $latestAudit && is_array($latestAudit->result)) {
            $auditTitle = trim((string) data_get($latestAudit->result, 'metadata.title', ''));
            if ($auditTitle === '') {
                Log::info('LiveSeoScoreService: audit completed but <title> is empty — likely a failed fetch', [
                    'website_id' => $website->id,
                    'url' => $url,
                    'audit_id' => $latestAudit->id,
                ]);
                $auditReady = false;
                if (($auditState['status'] ?? '') !== 'blocked') {
                    $auditState = [
                        'status' => 'failed',
                        'message' => 'The previous audit fetched a page with no <title> tag — most likely a 404, login wall, or placeholder URL. Re-trigger the audit from HQ → Page Audits to retry.',
                        'audited_at' => $latestAudit->audited_at?->toIso8601String(),
                    ];
                }
            }
        }

        // ── Indexing ─────────────────────────────────────────────
        $indexing = PageIndexingStatus::query()
            ->where('website_id', $website->id)
            ->tap(fn ($q) => $this->resolver->__publicApplyPageMatch($q, $url))
            ->orderByDesc('last_google_status_checked_at')
            ->first();

        // ── Trigger a background sync of the website's own backlinks from
        //    Keywords Everywhere. The job is unique-per-website and the
        //    service itself only hits KE once every 30 days — safe to fire
        //    on every score request. Once the job lands, the count below
        //    picks up the new rows on the next score call.
        SyncOwnBacklinksFromKeywordsEverywhere::dispatch(
            $website->id,
            $website->user_id ?? null,
        );

        // ── Backlinks (referring domain count for any URL variant) ──
        $referringDomains = $this->countReferringDomains($website->id, $variants);

        // ── Tracked-keyword nudge ────────────────────────────────
        $tracked = false;
        if ($focusKeyword !== null && $focusKeyword !== '') {
            $tracked = RankTrackingKeyword::query()
                ->where('website_id', $website->id)
                ->where('keyword_hash', RankTrackingKeyword::hashKeyword($focusKeyword))
                ->where('is_active', true)
                ->exists();
        }

        // ── Build factors ────────────────────────────────────────
        $factors = [];
        $rankBasis = $kwRank ?? $avgPos ?? 100.0;

        // GSC-derived. When GSC has no impressions for the URL yet
        // (every freshly-published post for the first ~1–7 days), these
        // four factors are pending — score still composes from the
        // audit-derived factors below + indexing + backlinks. The
        // existing weighted composite skips pending entries.
        if ($gscHasData) {
            // Rank takes the audit so it can discount when SERP features
            // dominate the query (an organic #5 sometimes hides below an
            // answer box + PAA + image pack — that needs to read as worse
            // than rank alone).
            $factors[] = $this->factorRank($kwRank, $avgPos, $focusKeyword, $rankBasis, $auditReady ? $latestAudit : null);
            $factors[] = $this->factorCtr($avgCtr ?? 0.0, $rankBasis);
            $factors[] = $this->factorCoverage($coverage);
            $factors[] = $this->factorCannibalization($cannibalized);
        } else {
            $gscMsg = $gscIsSparse
                ? sprintf(
                    'Only %d impression%s recorded so far — too sparse to score meaningfully. The rank, CTR, and coverage factors will turn live once at least %d impressions accumulate (typically within a week of indexing).',
                    $impressions,
                    $impressions === 1 ? '' : 's',
                    $MIN_GSC_IMPRESSIONS,
                )
                : 'Google Search Console has no impressions for this URL yet. Fills in within a few days of publishing once Google starts surfacing the page.';
            $factors[] = $this->pendingFactor('rank', 'Average rank', $gscMsg);
            $factors[] = $this->pendingFactor('ctr', 'Click-through rate', $gscMsg);
            $factors[] = $this->pendingFactor('coverage', 'Query coverage', $gscMsg);
            $factors[] = $this->pendingFactor('cannibalization', 'Cannibalization risk', $gscMsg);
        }

        // Indexing (always available — even null state has a recommendation)
        $factors[] = $this->factorIndexing($indexing);

        // Backlinks (always available)
        $factors[] = $this->factorBacklinks($referringDomains);

        // Audit-derived (pending if no audit yet). When `refreshing` we still
        // show the prior audit's data — the client will swap to fresh data
        // via polling once the queued re-audit completes.
        if ($auditReady) {
            $factors[] = $this->factorCoreWebVitals($latestAudit);
            $factors[] = $this->factorPagePerformance($latestAudit);
            $factors[] = $this->factorOnPageSeo($latestAudit);
            $factors[] = $this->factorTechnicalHealth($latestAudit);
            $factors[] = $this->factorContentQuality($latestAudit);
            $factors[] = $this->factorKeywordAlignment($latestAudit, $focusKeyword);
            $factors[] = $this->factorRecommendations($latestAudit);
        } else {
            $msg = match ($auditState['status']) {
                'running'    => 'Audit running — refreshes when complete.',
                'queued'     => 'Audit queued — refreshes when complete.',
                'refreshing' => 'Re-auditing in the background — factor refreshes when the run finishes.',
                'blocked'    => 'Publish the post (or change visibility from Private to Public) so EBQ\'s external auditor can fetch it. Lighthouse / Core Web Vitals require a publicly reachable URL.',
                default      => 'No audit on file. Trigger one from HQ → Page Audits.',
            };
            $factors[] = $this->pendingFactor('core_web_vitals', 'Core Web Vitals', $msg);
            $factors[] = $this->pendingFactor('page_performance', 'Page performance', $msg);
            $factors[] = $this->pendingFactor('on_page_seo', 'On-page SEO', $msg);
            $factors[] = $this->pendingFactor('technical_health', 'Technical health', $msg);
            $factors[] = $this->pendingFactor('content_quality', 'Content quality', $msg);
            $factors[] = $this->pendingFactor('keyword_alignment', 'Keyword placement', $msg);
            $factors[] = $this->pendingFactor('recommendations', 'Top fixes', $msg);
        }

        // Tracked nudge
        $factors[] = $this->factorTracked($tracked, $focusKeyword);

        // ── Weighted composite (skip pending) ────────────────────
        $real = array_values(array_filter($factors, static fn (array $f) => empty($f['pending'])));
        $weightSum = array_sum(array_column($real, 'weight'));
        $weighted = 0.0;
        foreach ($real as $f) {
            $weighted += $f['score'] * ($f['weight'] / max(1, $weightSum));
        }
        $score = max(0, min(100, (int) round($weighted)));

        $label = $score >= 65 ? 'Good' : ($score >= 45 ? 'Needs work' : 'Bad');
        $explanation = $this->buildExplanation($score, $rankBasis, $cannibalized, $coverage, $kwRank !== null, $auditState['status']);

        $partial = ! $gscHasData || ! $auditReady;
        $partialReason = $this->buildPartialReason($gscHasData, $gscIsSparse, $impressions, $auditReady, $auditState['status']);

        // Suppress the composite score number when there isn't enough
        // signal to compose one honestly. With both the audit blocked
        // (non-public post) AND GSC empty, the score collapses to just
        // indexing + backlinks + tracked-keyword — too thin to grade
        // a page on. The breakdown still renders so the user can act on
        // whatever individual factors ARE live; the chip just shows "—"
        // instead of a misleading number.
        $auditBlocked = ($auditState['status'] ?? '') === 'blocked';
        $suppressScore = $auditBlocked && ! $gscHasData;
        if ($suppressScore) {
            $score = null;
            $label = $auditBlocked ? 'Awaiting publish' : 'Awaiting data';
        }

        return [
            'score' => $score,
            'label' => $label,
            'available' => true,
            'partial' => $partial,
            'partial_reason' => $partialReason,
            'audited_url' => $url, // echo back the canonical URL so the UI can show it
            'audit' => $auditState,
            'factors' => $factors,
            'explanation' => $explanation,
        ];
    }

    /**
     * Human-readable advisory shown above the score when one of the two
     * primary signal sources (GSC, on-page audit) is not yet available.
     * `null` when both are present and the score is fully grounded.
     */
    private function buildPartialReason(bool $gscHasData, bool $gscIsSparse, int $impressions, bool $auditReady, string $auditStatus): ?string
    {
        if ($gscHasData && $auditReady) {
            return null;
        }

        // Non-public post (draft / private / pending / scheduled). The
        // audit can't run AT ALL until the URL is reachable. Treat this
        // as the dominant reason regardless of GSC state — fixing post
        // visibility unblocks both halves once the post is also indexed.
        if ($auditStatus === 'blocked') {
            return 'Provisional score — this post isn\'t publicly accessible yet, so EBQ\'s external auditor can\'t run Lighthouse / Core Web Vitals on it. Publish (or change visibility from Private to Public) to enable the full audit. The on-page placement, links, and content checks below run from the editor content directly and stay live.';
        }

        if (! $gscHasData && ! $auditReady) {
            $auditPart = in_array($auditStatus, ['queued', 'running', 'refreshing'], true)
                ? 'on-page audit running'
                : 'on-page audit pending';
            $gscPart = $gscIsSparse
                ? sprintf('GSC data sparse (only %d impression%s)', $impressions, $impressions === 1 ? '' : 's')
                : 'GSC data pending';
            return "Provisional score — {$auditPart}, {$gscPart}. Both fill in shortly after the post is published.";
        }

        if (! $gscHasData) {
            if ($gscIsSparse) {
                return sprintf(
                    'Provisional score — only %d Search Console impression%s for this URL so far. Rank, CTR, coverage, and cannibalization will turn live once at least 10 impressions accumulate (usually within a week of indexing).',
                    $impressions,
                    $impressions === 1 ? '' : 's',
                );
            }
            return 'Provisional score — based on the on-page audit only. Rank, CTR, coverage, and cannibalization fill in once Google starts surfacing this URL in search results.';
        }

        // GSC ready, audit not — already happens today on stale audit;
        // existing behavior is to show prior data while refreshing.
        return 'Provisional score — on-page audit running. Audit-derived factors will refresh when complete.';
    }

    /* ──────────────────────────────────────────────────────────────
     *                  Audit queue gating
     * ──────────────────────────────────────────────────────────── */

    /**
     * Returns the audit state block returned to the client. If no audit
     * exists and none is queued, enqueue one via the existing
     * `CustomPageAudit` + `RunCustomPageAudit` queue. We never re-audit
     * once a completed report exists — the user must manually re-audit.
     *
     * @return array{status: string, message: string, audited_at?: string|null, queued_at?: string|null}
     */
    private function resolveAuditState(Website $website, string $url, ?string $focusKeyword, ?PageAuditReport $latest, bool $auditReady, bool $auditStale = false, string $postStatus = ''): array
    {
        if ($auditReady && $latest && ! $auditStale) {
            return [
                'status' => 'ready',
                'message' => 'Audit data is current.',
                'audited_at' => $latest->audited_at?->toIso8601String(),
            ];
        }

        // Non-public WordPress statuses (`draft`, `pending`, `private`,
        // `future`, `auto-draft`, `inherit`) mean the URL returns 404 /
        // login redirect to our external auditor. Don't queue a job
        // that's guaranteed to fail — return a clean "blocked" state
        // with copy that tells the user exactly how to unblock it.
        $nonPublicStatuses = ['draft', 'pending', 'private', 'future', 'auto-draft', 'inherit', 'trash'];
        if ($postStatus !== '' && in_array($postStatus, $nonPublicStatuses, true)) {
            $human = match ($postStatus) {
                'draft', 'auto-draft' => 'draft',
                'pending'             => 'pending review',
                'private'             => 'private (visibility)',
                'future'              => 'scheduled',
                'trash'               => 'trashed',
                default               => $postStatus,
            };
            return [
                'status' => 'blocked',
                'message' => "Audit needs the page to be publicly accessible. This post is {$human}, so EBQ's external auditor can't fetch it for Lighthouse / Core Web Vitals checks. Once you publish (or change visibility from Private to Public), the audit auto-runs and the score sharpens.",
                'audited_at' => $auditReady && $latest ? $latest->audited_at?->toIso8601String() : null,
                'post_status' => $postStatus,
            ];
        }

        // Don't queue duplicates — return whatever's already in flight.
        // Cap in-flight age at 15 minutes: if a CustomPageAudit has been
        // queued/running longer than that, the worker most likely died
        // (process killed, OOM, deploy mid-job). Without this cap a zombie
        // row pins the score on "refreshing" forever because the in-flight
        // check keeps finding it. Skipping zombies lets us queue a fresh
        // audit and recover automatically.
        $pageHash = hash('sha256', $url);
        $maxInFlightAge = Carbon::now()->subMinutes(15);
        $inFlight = CustomPageAudit::query()
            ->where('website_id', $website->id)
            ->where('page_url_hash', $pageHash)
            ->whereIn('status', [CustomPageAudit::STATUS_QUEUED, CustomPageAudit::STATUS_RUNNING])
            ->where('queued_at', '>=', $maxInFlightAge)
            ->latest('queued_at')
            ->first();

        if ($inFlight) {
            // Re-running over a prior good audit (post was updated) →
            // surface as `refreshing` so the client knows the visible
            // factor data is from the last audit but a refresh is on the way.
            $status = $auditStale && $auditReady ? 'refreshing' : $inFlight->status;
            $message = $status === 'refreshing'
                ? 'Post was updated since the last audit — EBQ is re-auditing now. Factor data will refresh automatically.'
                : ($inFlight->status === CustomPageAudit::STATUS_RUNNING
                    ? 'EBQ is auditing this page right now. Usually takes 30–90s.'
                    : 'Audit queued. EBQ will run it in the background — score will refresh automatically.');

            return [
                'status' => $status,
                'message' => $message,
                'queued_at' => $inFlight->queued_at?->toIso8601String(),
                'previous_audited_at' => $auditStale && $latest ? $latest->audited_at?->toIso8601String() : null,
            ];
        }

        // Latest audit failed and there's nothing in flight — show the failure
        // but don't auto-requeue (avoids burning Serper/Lighthouse credits on
        // a URL that's permanently broken).
        if ($latest && $latest->status === 'failed') {
            return [
                'status' => 'failed',
                'message' => $latest->error_message
                    ? 'Last audit failed: ' . $latest->error_message
                    : 'Last audit failed. Re-trigger it from HQ → Page Audits.',
                'audited_at' => $latest->audited_at?->toIso8601String(),
            ];
        }

        // No audit, no in-flight — queue one now.
        $ownerUserId = (int) ($website->user_id ?? 0);
        if (($ownerUserId === null || $ownerUserId === '')) {
            return [
                'status' => 'unavailable',
                'message' => 'Cannot enqueue audit: website has no owner user.',
            ];
        }

        try {
            $queued = CustomPageAudit::queue(
                websiteId: $website->id,
                userId: $ownerUserId,
                pageUrl: $url,
                targetKeyword: $focusKeyword ?? '',
                serpSampleGl: null,
                source: CustomPageAudit::SOURCE_LIVE_SCORE,
            );
            RunCustomPageAudit::dispatch($queued->id);

            // Stale-and-no-prior-in-flight: surface as `refreshing` so the
            // client keeps showing the prior audit's factor data while the
            // new run completes.
            if ($auditStale && $auditReady) {
                return [
                    'status' => 'refreshing',
                    'message' => 'Post was updated since the last audit — EBQ is re-auditing now. Factor data will refresh automatically.',
                    'queued_at' => $queued->queued_at?->toIso8601String(),
                    'previous_audited_at' => $latest?->audited_at?->toIso8601String(),
                ];
            }

            return [
                'status' => 'queued',
                'message' => 'EBQ is auditing this page for the first time. Usually takes 30–90s — your score will refresh automatically.',
                'queued_at' => $queued->queued_at?->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('LiveSeoScoreService: failed to enqueue audit', [
                'url' => $url,
                'website_id' => $website->id,
                'exception' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Could not enqueue audit: ' . $e->getMessage(),
            ];
        }
    }

    /* ──────────────────────────────────────────────────────────────
     *                          Factors
     * ──────────────────────────────────────────────────────────── */

    private function factorRank(?float $kwRank, ?float $avgPos, ?string $focusKeyword, float $rankBasis, ?PageAuditReport $audit = null): array
    {
        $score = $this->positionScore($rankBasis);

        // SERP-features discount: an organic #5 can be invisible below the
        // fold once Google adds an answer box + PAA + image pack + sitelinks.
        // The audit benchmark already records `your_serp.organic_sample_size`
        // — when that's well below 10 it means the SERP page is dominated
        // by feature blocks that pushed organic results down. Score reads
        // as worse than the raw position implies.
        $serpDiscount = 0;
        $serpDiscountReason = null;
        if ($audit && is_array($audit->result)) {
            $yourSerp = data_get($audit->result, 'benchmark.your_serp', null);
            if (is_array($yourSerp) && isset($yourSerp['organic_sample_size']) && is_numeric($yourSerp['organic_sample_size'])) {
                $organicCount = (int) $yourSerp['organic_sample_size'];
                if ($organicCount > 0 && $organicCount < 8) {
                    // 7 organic = -5, 6 = -10, 5 = -15, 4 = -20, etc.
                    $serpDiscount = min(25, (8 - $organicCount) * 5);
                    $serpDiscountReason = sprintf(
                        'SERP features dominate this query (only %d organic results visible above the fold) — your rank reads worse than the raw position.',
                        $organicCount
                    );
                }
            }
        }
        $score = max(0, $score - $serpDiscount);

        $detail = $kwRank !== null
            ? sprintf('Position %.1f for "%s"', $kwRank, $focusKeyword)
            : sprintf('Avg position %.1f across all queries', $rankBasis);
        if ($serpDiscountReason !== null) {
            $detail .= ' · features visible: ' . abs($serpDiscount) . '% penalty';
        }

        return [
            'key' => 'rank',
            'label' => $kwRank !== null ? 'Focus-keyword rank' : 'Average page rank',
            'score' => $score,
            'weight' => 18,
            'detail' => $detail,
            'recommendation' => $score < 65
                ? trim(($serpDiscountReason !== null ? $serpDiscountReason . ' ' : '') .
                    ($kwRank !== null
                        ? sprintf(
                            'Add internal links pointing to this page using "%s" as anchor text and place the keyphrase in an H2/H3. Strengthen on-page topical depth to climb out of position %.0f.',
                            $focusKeyword !== null && $focusKeyword !== '' ? $focusKeyword : 'your focus keyphrase',
                            $rankBasis
                        )
                        : 'Set a focus keyphrase so the page can be scored on a specific query, then strengthen on-page placement and internal links for it.'))
                : null,
        ];
    }

    private function factorCtr(float $actualCtr, float $rankBasis): array
    {
        $expected = $this->expectedCtrForPosition($rankBasis);
        $score = $this->ctrScore($actualCtr, $expected);

        return [
            'key' => 'ctr',
            'label' => 'Click-through rate',
            'score' => $score,
            'weight' => 6,
            'detail' => sprintf('CTR %.2f%% (expected %.2f%% for rank %.0f)', $actualCtr * 100, $expected * 100, $rankBasis),
            'recommendation' => $score < 65
                ? 'Rewrite the SEO title and meta description so the snippet earns more clicks: lead with the keyphrase, add a year or specific number, and answer the searcher\'s intent in plain language.'
                : null,
        ];
    }

    private function factorCoverage(int $coverage): array
    {
        $score = $this->coverageScore($coverage);

        return [
            'key' => 'coverage',
            'label' => 'Topical coverage',
            'score' => $score,
            'weight' => 6,
            'detail' => sprintf('Ranks for %d distinct queries in the top 100', $coverage),
            'recommendation' => $score < 65
                ? 'Add 1–2 sections covering related sub-questions and long-tail variants. Use the "Topical gaps vs. top SERP" panel for specific subtopic ideas competitors cover.'
                : null,
        ];
    }

    private function factorCannibalization(bool $cannibalized): array
    {
        return [
            'key' => 'cannibalization',
            'label' => 'No cannibalization',
            'score' => $cannibalized ? 30 : 100,
            'weight' => 4,
            'detail' => $cannibalized
                ? 'Another URL on this site is also ranking for the focus keyword.'
                : 'No competing pages on the site for this query.',
            'recommendation' => $cannibalized
                ? 'Find the competing URL in HQ → Insights → Cannibalization and either 301-redirect it into this page or shift its targeting to a different keyphrase.'
                : null,
        ];
    }

    private function factorIndexing(?PageIndexingStatus $indexing): array
    {
        $verdict = $indexing?->google_verdict;
        $coverage = $indexing?->google_coverage_state;

        if ($indexing === null) {
            return [
                'key' => 'indexing',
                'label' => 'Google index status',
                'score' => 50,
                'weight' => 12,
                'detail' => 'No URL Inspection data yet for this page.',
                'recommendation' => 'Open Search Console → URL Inspection on this URL so EBQ can check whether Google is indexing it. Indexing health is a hard precondition for any traffic.',
            ];
        }

        $score = match (strtoupper((string) $verdict)) {
            'PASS' => 100,
            'PARTIAL' => 60,
            'NEUTRAL' => 70,
            'FAIL' => 10,
            default => 50,
        };

        $detail = sprintf(
            'Verdict: %s · %s',
            $verdict ?? 'unknown',
            $coverage !== null && $coverage !== '' ? $coverage : 'no coverage_state',
        );

        $recommendation = null;
        if ($score < 65) {
            $recommendation = $verdict && strtoupper((string) $verdict) === 'FAIL'
                ? sprintf('Google reports the page as not indexed (%s). Open Search Console → URL Inspection, fix the underlying issue (canonical, robots, redirect chain), then click Request Indexing.', $coverage ?: 'unknown reason')
                : 'Indexing is partial or unclear. Open URL Inspection in Search Console — click Request Indexing if the page is intended to be live.';
        }

        return [
            'key' => 'indexing',
            'label' => 'Google index status',
            'score' => $score,
            'weight' => 12,
            'detail' => $detail,
            'recommendation' => $recommendation,
        ];
    }

    private function factorBacklinks(int $referringDomains): array
    {
        $score = $this->backlinksScore($referringDomains);

        return [
            'key' => 'backlinks',
            'label' => 'Backlinks',
            'score' => $score,
            'weight' => 4,
            'detail' => sprintf('%d referring domain%s', $referringDomains, $referringDomains === 1 ? '' : 's'),
            'recommendation' => $score < 65
                ? 'Run prospecting from HQ → Backlinks. Find sites linking to top-ranking competitors but not to you and pitch a relevant resource on this page.'
                : null,
        ];
    }

    private function factorCoreWebVitals(PageAuditReport $audit): array
    {
        $cwv = $audit->result['core_web_vitals'] ?? [];
        $mobile = is_array($cwv['mobile'] ?? null) ? $cwv['mobile'] : [];
        $lcp = isset($mobile['lcp_ms']) && is_numeric($mobile['lcp_ms']) ? (float) $mobile['lcp_ms'] : null;
        $cls = isset($mobile['cls']) && is_numeric($mobile['cls']) ? (float) $mobile['cls'] : null;

        // Google's official "good / needs improvement / poor" buckets.
        $lcpScore = $lcp === null ? null : ($lcp <= 2500 ? 100 : ($lcp <= 4000 ? 60 : 20));
        $clsScore = $cls === null ? null : ($cls <= 0.1 ? 100 : ($cls <= 0.25 ? 60 : 20));
        $parts = array_filter([$lcpScore, $clsScore], static fn ($v) => $v !== null);
        $score = $parts === [] ? 60 : (int) round(array_sum($parts) / count($parts));

        $detailParts = [];
        if ($lcp !== null) {
            $detailParts[] = sprintf('LCP %sms', number_format($lcp));
        }
        if ($cls !== null) {
            $detailParts[] = sprintf('CLS %.3f', $cls);
        }
        $detail = $detailParts === [] ? 'No CWV measurements in latest audit.' : implode(' · ', $detailParts) . ' (mobile)';

        $recs = [];
        if ($lcpScore !== null && $lcpScore < 65) {
            $recs[] = 'Reduce LCP: serve hero images from a CDN, use modern formats (WebP/AVIF), preload the LCP image, and trim render-blocking JS.';
        }
        if ($clsScore !== null && $clsScore < 65) {
            $recs[] = 'Reduce CLS: declare width/height on every image, reserve space for ads/embeds, and avoid late-injected DOM above the fold.';
        }

        return [
            'key' => 'core_web_vitals',
            'label' => 'Core Web Vitals',
            'score' => $score,
            'weight' => 10,
            'detail' => $detail,
            'recommendation' => $recs === [] ? null : implode(' ', $recs),
        ];
    }

    private function factorPagePerformance(PageAuditReport $audit): array
    {
        $cwv = $audit->result['core_web_vitals'] ?? [];
        $mobile = is_array($cwv['mobile'] ?? null) ? $cwv['mobile'] : [];
        $desktop = is_array($cwv['desktop'] ?? null) ? $cwv['desktop'] : [];
        $mPerf = isset($mobile['performance_score']) && is_numeric($mobile['performance_score']) ? (int) $mobile['performance_score'] : null;
        $dPerf = isset($desktop['performance_score']) && is_numeric($desktop['performance_score']) ? (int) $desktop['performance_score'] : null;

        $parts = array_filter([$mPerf, $dPerf], static fn ($v) => $v !== null);
        $score = $parts === [] ? 60 : (int) round(array_sum($parts) / count($parts));

        $detail = $parts === []
            ? 'No Lighthouse performance data in latest audit.'
            : sprintf('Mobile %d/100, Desktop %d/100', $mPerf ?? 0, $dPerf ?? 0);

        return [
            'key' => 'page_performance',
            'label' => 'Page performance',
            'score' => $score,
            'weight' => 6,
            'detail' => $detail,
            'recommendation' => $score < 65
                ? 'Compress and lazy-load images, defer third-party JS (analytics, chat widgets), and inline critical CSS. Re-run the audit from HQ → Page Audits to verify.'
                : null,
        ];
    }

    private function factorOnPageSeo(PageAuditReport $audit): array
    {
        $meta = is_array($audit->result['metadata'] ?? null) ? $audit->result['metadata'] : [];
        $content = is_array($audit->result['content'] ?? null) ? $audit->result['content'] : [];
        $advanced = is_array($audit->result['advanced'] ?? null) ? $audit->result['advanced'] : [];

        $titleLen = (int) ($meta['title_length'] ?? 0);
        $descLen = (int) ($meta['meta_description_length'] ?? 0);
        $canonicalOk = (bool) ($meta['canonical_matches'] ?? false);
        $ogCount = (int) ($meta['og_tag_count'] ?? 0);
        $h1Count = (int) ($content['h1_count'] ?? 0);
        $headingOrderOk = (bool) ($content['heading_order_ok'] ?? false);
        $schemaBlocks = (int) ($advanced['schema_blocks'] ?? 0);

        $checks = [
            'title' => $titleLen >= 30 && $titleLen <= 70 ? 100 : ($titleLen > 0 ? 60 : 0),
            'meta_description' => $descLen >= 120 && $descLen <= 170 ? 100 : ($descLen > 0 ? 60 : 0),
            'canonical' => $canonicalOk ? 100 : 50,
            'open_graph' => $ogCount >= 4 ? 100 : ($ogCount > 0 ? 60 : 30),
            'h1' => $h1Count === 1 ? 100 : ($h1Count > 1 ? 50 : 20),
            'heading_order' => $headingOrderOk ? 100 : 60,
            'schema' => $schemaBlocks >= 1 ? 100 : 40,
        ];
        $score = (int) round(array_sum($checks) / count($checks));

        $issues = [];
        if ($titleLen === 0)                            $issues[] = 'no SEO title';
        elseif ($titleLen < 30 || $titleLen > 70)      $issues[] = sprintf('title length %d (target 30–70)', $titleLen);
        if ($descLen === 0)                             $issues[] = 'no meta description';
        elseif ($descLen < 120 || $descLen > 170)      $issues[] = sprintf('meta description length %d (target 130–155)', $descLen);
        if (! $canonicalOk)                             $issues[] = 'canonical missing/mismatched';
        if ($h1Count !== 1)                             $issues[] = sprintf('%d H1 tags (need exactly 1)', $h1Count);
        if (! $headingOrderOk)                          $issues[] = 'heading hierarchy out of order';
        if ($schemaBlocks === 0)                        $issues[] = 'no JSON-LD schema';
        if ($ogCount === 0)                             $issues[] = 'no Open Graph tags';

        $detail = sprintf('Title %d, meta %d, %d H1, %d schema blocks', $titleLen, $descLen, $h1Count, $schemaBlocks);

        return [
            'key' => 'on_page_seo',
            'label' => 'On-page SEO',
            'score' => $score,
            'weight' => 6,
            'detail' => $detail,
            'recommendation' => $score < 65
                ? 'Fix the on-page basics: ' . ($issues === [] ? 'tighten title, meta, H1, and schema.' : implode('; ', $issues) . '.')
                : null,
        ];
    }

    private function factorTechnicalHealth(PageAuditReport $audit): array
    {
        $tech = is_array($audit->result['technical'] ?? null) ? $audit->result['technical'] : [];
        $links = is_array($audit->result['links'] ?? null) ? $audit->result['links'] : [];

        $httpStatus = isset($tech['http_status']) && is_numeric($tech['http_status']) ? (int) $tech['http_status'] : null;
        $isHttps = (bool) ($tech['is_https'] ?? false);
        $ttfb = isset($tech['ttfb_ms']) && is_numeric($tech['ttfb_ms']) ? (int) $tech['ttfb_ms'] : null;
        $brokenCount = is_array($links['broken'] ?? null) ? count($links['broken']) : 0;

        $statusScore = match (true) {
            $httpStatus === null => 50,
            $httpStatus >= 200 && $httpStatus < 300 => 100,
            $httpStatus >= 300 && $httpStatus < 400 => 60,
            default => 10,
        };
        $httpsScore = $isHttps ? 100 : 30;
        $ttfbScore = $ttfb === null ? 60 : ($ttfb <= 500 ? 100 : ($ttfb <= 1500 ? 70 : ($ttfb <= 3000 ? 40 : 20)));
        $brokenScore = $brokenCount === 0 ? 100 : ($brokenCount <= 2 ? 70 : ($brokenCount <= 5 ? 40 : 15));

        $score = (int) round(($statusScore * 0.40) + ($httpsScore * 0.20) + ($ttfbScore * 0.20) + ($brokenScore * 0.20));

        $bits = [];
        if ($httpStatus !== null)  $bits[] = sprintf('HTTP %d', $httpStatus);
        $bits[] = $isHttps ? 'HTTPS' : 'no HTTPS';
        if ($ttfb !== null)        $bits[] = sprintf('%dms TTFB', $ttfb);
        $bits[] = sprintf('%d broken link%s', $brokenCount, $brokenCount === 1 ? '' : 's');

        $recs = [];
        if ($httpStatus !== null && $httpStatus >= 400)   $recs[] = sprintf('Page returns HTTP %d. Investigate before any other SEO work.', $httpStatus);
        if (! $isHttps)                                    $recs[] = 'Serve over HTTPS — required for ranking and modern browser features.';
        if ($ttfb !== null && $ttfb > 1500)               $recs[] = sprintf('Server TTFB is %dms (target ≤500ms). Add caching, upgrade hosting, or move static assets to a CDN.', $ttfb);
        if ($brokenCount > 0)                              $recs[] = sprintf('Fix the %d broken link%s on this page (see audit report for the list).', $brokenCount, $brokenCount === 1 ? '' : 's');

        return [
            'key' => 'technical_health',
            'label' => 'Technical health',
            'score' => $score,
            'weight' => 6,
            'detail' => implode(' · ', $bits),
            'recommendation' => $recs === [] ? null : implode(' ', $recs),
        ];
    }

    private function factorContentQuality(PageAuditReport $audit): array
    {
        $content = is_array($audit->result['content'] ?? null) ? $audit->result['content'] : [];
        $advanced = is_array($audit->result['advanced'] ?? null) ? $audit->result['advanced'] : [];
        $images = is_array($audit->result['images'] ?? null) ? $audit->result['images'] : [];

        $wordCount = (int) ($content['word_count'] ?? 0);
        $flesch = isset($advanced['readability']['flesch']) && is_numeric($advanced['readability']['flesch'])
            ? (float) $advanced['readability']['flesch']
            : null;
        $imgTotal = (int) ($images['total'] ?? 0);
        $imgMissingAlt = (int) ($images['missing_alt_count'] ?? 0);

        $wordScore = match (true) {
            $wordCount >= 1200 => 100,
            $wordCount >= 700 => 85,
            $wordCount >= 400 => 70,
            $wordCount >= 200 => 45,
            default => 20,
        };

        // Flesch sweet-spot for general web content is ~60–80.
        $fleschScore = $flesch === null ? 60 : match (true) {
            $flesch >= 60 && $flesch <= 80 => 100,
            $flesch >= 50 && $flesch < 60 => 80,
            $flesch > 80 && $flesch <= 90 => 80,
            $flesch >= 30 && $flesch < 50 => 60,
            default => 40,
        };

        // Image coverage — 0 images is a soft penalty, lots of images with
        // missing alt text is a hard one.
        $imgScore = $imgTotal === 0
            ? 50
            : (int) round(100 * (1 - ($imgMissingAlt / max(1, $imgTotal))));

        $score = (int) round(($wordScore * 0.50) + ($fleschScore * 0.30) + ($imgScore * 0.20));

        $bits = [sprintf('%d words', $wordCount)];
        if ($flesch !== null) $bits[] = sprintf('Flesch %.0f', $flesch);
        $bits[] = $imgTotal > 0
            ? sprintf('%d/%d images with alt', $imgTotal - $imgMissingAlt, $imgTotal)
            : 'no images';

        $recs = [];
        if ($wordCount < 700)              $recs[] = sprintf('Expand the article — %d words is below the depth top-ranking pages typically have. Aim for 700+ for content posts.', $wordCount);
        if ($flesch !== null && $flesch < 50) $recs[] = 'Tighten readability: shorter sentences, simpler words, more paragraph breaks. Aim for a Flesch score of 60–80.';
        if ($imgMissingAlt > 0)            $recs[] = sprintf('Add alt text to %d image%s — alt text is required for accessibility and helps Google understand visual context.', $imgMissingAlt, $imgMissingAlt === 1 ? '' : 's');

        return [
            'key' => 'content_quality',
            'label' => 'Content quality',
            'score' => $score,
            'weight' => 5,
            'detail' => implode(' · ', $bits),
            'recommendation' => $recs === [] ? null : implode(' ', $recs),
        ];
    }

    /**
     * "Does Google see your focus keyword where it matters?" — split out
     * from on_page_seo so the user gets a direct read on placement strategy.
     * Driven by `KeywordStrategyAnalyzer.power_placement` (title / H1 / meta)
     * which the audit already computes per page.
     *
     * MOAT note: power_placement is computed server-side against the live
     * fetched HTML, then cross-referenced with GSC's confirmed primary
     * query for the URL. The plugin can't reproduce the GSC half offline.
     */
    private function factorKeywordAlignment(PageAuditReport $audit, ?string $focusKeyword): array
    {
        $kw = is_array($audit->result['keywords'] ?? null) ? $audit->result['keywords'] : [];
        $available = (bool) ($kw['available'] ?? false);

        if (! $available) {
            $reason = is_string($kw['reason'] ?? null) ? $kw['reason'] : 'Not enough Search Console data yet to align placement.';
            return [
                'key' => 'keyword_alignment',
                'label' => 'Keyword placement',
                'score' => 50,
                'weight' => 7,
                'detail' => $reason,
                'recommendation' => 'Once Google starts surfacing impressions for this URL, EBQ will check whether your focus keyphrase appears in the title, H1, and meta description — the three slots that move the needle most.',
            ];
        }

        $power = is_array($kw['power_placement'] ?? null) ? $kw['power_placement'] : [];
        $coverage = is_array($kw['coverage'] ?? null) ? $kw['coverage'] : [];
        $accidental = is_array($kw['accidental'] ?? null) ? $kw['accidental'] : [];

        $inTitle = (bool) ($power['in_title'] ?? false);
        $inH1 = (bool) ($power['in_h1'] ?? false);
        $inMeta = (bool) ($power['in_meta_description'] ?? false);

        // Title (40%) + H1 (30%) + Meta (15%) — the three power slots.
        // Body presence (15%) — penalty when keyword is absent from prose,
        // bonus baseline when present (since coverage analyzer would flag it).
        $titlePts = $inTitle ? 100 : 0;
        $h1Pts = $inH1 ? 100 : 0;
        $metaPts = $inMeta ? 100 : 0;
        // `coverage` returns a `body_hit` map by query — true when the
        // primary keyword is found in the rendered body text.
        $bodyHit = (bool) data_get($coverage, 'body_hit_primary', true);
        $bodyPts = $bodyHit ? 100 : 30;

        $score = (int) round($titlePts * 0.40 + $h1Pts * 0.30 + $metaPts * 0.15 + $bodyPts * 0.15);

        // Watch for accidental over-indexing: density on a non-target term
        // crowding out the focus keyword. KE returns a `runner_up` we can
        // surface to the user as a "you're accidentally ranking for this
        // instead" signal.
        $runnerUpQuery = is_string($accidental['runner_up_query'] ?? null) ? $accidental['runner_up_query'] : null;

        $missing = [];
        if (! $inTitle) $missing[] = 'SEO title';
        if (! $inH1)    $missing[] = 'H1';
        if (! $inMeta)  $missing[] = 'meta description';
        if (! $bodyHit) $missing[] = 'body copy';

        $detail = $missing === []
            ? 'Focus keyphrase appears in title, H1, meta, and body.'
            : sprintf('Missing from: %s.', implode(', ', $missing));
        if ($runnerUpQuery !== null && $runnerUpQuery !== '') {
            $detail .= sprintf(' Runner-up term in this content: "%s".', $runnerUpQuery);
        }

        // Surface what the audit ACTUALLY saw in the rendered HTML, but
        // ONLY when there's a discrepancy to diagnose. When everything
        // passes ($missing === []), the cards are noise. When the user
        // is told something is missing, the audit-saw items help them
        // reconcile (e.g. another SEO plugin winning the wp_head race).
        // Items must match CheckCard's shape: {severity, title, why?, fix?}.
        $items = [];
        if ($missing !== []) {
            $auditTitle    = trim((string) data_get($audit->result, 'metadata.title', ''));
            $auditMeta     = trim((string) data_get($audit->result, 'metadata.meta_description', ''));
            $auditH1Texts  = array_values(array_filter(array_map(
                static fn ($h) => is_array($h) && (int) ($h['level'] ?? 0) === 1 ? trim((string) ($h['text'] ?? '')) : '',
                (array) data_get($audit->result, 'content.headings', []),
            )));
            $firstH1 = $auditH1Texts[0] ?? '';

            if (! $inTitle) {
                $items[] = [
                    'severity' => 'info',
                    'title'    => '<title>',
                    'why'      => $auditTitle !== ''
                        ? sprintf('Audit saw: "%s"', mb_substr($auditTitle, 0, 140))
                        : 'Audit saw an empty <title> tag.',
                ];
            }
            if (! $inH1) {
                $items[] = [
                    'severity' => 'info',
                    'title'    => '<h1>',
                    'why'      => $firstH1 !== ''
                        ? sprintf('Audit saw: "%s"', mb_substr($firstH1, 0, 140))
                        : 'Audit found no <h1> on the page.',
                ];
            }
            if (! $inMeta) {
                $items[] = [
                    'severity' => 'info',
                    'title'    => '<meta name="description">',
                    'why'      => $auditMeta !== ''
                        ? sprintf('Audit saw: "%s"', mb_substr($auditMeta, 0, 200))
                        : 'Audit saw an empty meta description tag.',
                ];
            }
        }

        $recommendation = null;
        if ($score < 90 && $missing !== []) {
            $kwText = $focusKeyword !== null && $focusKeyword !== '' ? '"' . $focusKeyword . '"' : 'the focus keyphrase';
            $auditTitle = trim((string) data_get($audit->result, 'metadata.title', ''));
            $reconcileHint = ($auditTitle !== '' && ! $inTitle && $focusKeyword !== null && $focusKeyword !== '')
                ? sprintf(' Heads-up: the audit fetched the live page and parsed the <title> tag as "%s". If that doesn\'t match what you set in the EBQ SEO field, another plugin or theme may be overriding the title — check the page\'s rendered HTML.', mb_substr($auditTitle, 0, 140))
                : '';
            $recommendation = sprintf(
                'Place %s in the missing slot%s: %s. Title and H1 carry the most weight; meta description influences CTR (which loops back into rank).%s',
                $kwText,
                count($missing) === 1 ? '' : 's',
                implode(' + ', $missing),
                $reconcileHint,
            );
        }

        return [
            'key' => 'keyword_alignment',
            'label' => 'Keyword placement',
            'score' => $score,
            'weight' => 7,
            'detail' => $detail,
            'recommendation' => $recommendation,
            'items' => $items === [] ? null : $items,
        ];
    }

    /**
     * Surfaces the top items from `RecommendationEngine` directly as
     * actionable cards. Each entry has title + why + fix already authored
     * by the engine — no LLM call, just smart prioritization.
     *
     * The factor's score blends the severity mix: lots of `critical` items
     * crater the score, mostly `info`/`good` keeps it high. Items list (top
     * 5) gets rendered as a bulleted breakdown in the popover so the user
     * has a concrete punch list right next to the score.
     *
     * MOAT note: the recommendation rules sit in PHP on EBQ — competitors
     * see only the rendered list, not the heuristics. Same for severity
     * weighting and ordering.
     */
    private function factorRecommendations(PageAuditReport $audit): array
    {
        $recs = is_array($audit->result['recommendations'] ?? null) ? $audit->result['recommendations'] : [];

        // Drop recommendations the dedicated `keyword_alignment` factor
        // already surfaces with full audit-saw transparency — otherwise
        // the same "Primary keyword missing from <title>" message shows
        // up twice (once in keyword_alignment with context, once in Top
        // fixes without context). The duplicate has no audit-saw
        // diagnostic, so it reads as a hard-fact accusation when the
        // user might have set the title correctly in their SEO field
        // but another plugin is winning the wp_head race.
        $duplicateIds = ['kw.placement.title', 'kw.placement.h1', 'kw.placement.desc'];
        $recs = array_values(array_filter($recs, static function ($r) use ($duplicateIds) {
            if (! is_array($r)) return false;
            $id = (string) ($r['id'] ?? '');
            return ! in_array($id, $duplicateIds, true);
        }));

        $counts = ['critical' => 0, 'warning' => 0, 'serp_gap' => 0, 'info' => 0, 'good' => 0];
        foreach ($recs as $r) {
            $sev = is_array($r) && isset($r['severity']) ? (string) $r['severity'] : 'info';
            if (isset($counts[$sev])) $counts[$sev]++;
        }

        // 100 baseline; each critical −15, warning −5, serp_gap −3, info −1.
        // `good` never penalizes (it's a positive ack from the engine).
        $score = 100
            - ($counts['critical'] * 15)
            - ($counts['warning']  * 5)
            - ($counts['serp_gap'] * 3)
            - ($counts['info']     * 1);
        $score = max(0, min(100, $score));

        // Take the top 5 actionable items (RecommendationEngine already
        // sorts critical → warning → serp_gap → info → good). Skip `good`
        // — those are positive acks, not "do this" items.
        $items = [];
        foreach ($recs as $r) {
            if (! is_array($r)) continue;
            $sev = (string) ($r['severity'] ?? 'info');
            if ($sev === 'good') continue;
            $items[] = [
                'severity' => $sev,
                'section' => (string) ($r['section'] ?? ''),
                'title' => (string) ($r['title'] ?? ''),
                'why' => (string) ($r['why'] ?? ''),
                'fix' => (string) ($r['fix'] ?? ''),
            ];
            if (count($items) >= 5) break;
        }

        $detail = $items === []
            ? 'No actionable recommendations — the audit engine is happy with this page.'
            : sprintf(
                '%d critical, %d warning, %d minor — top %d shown.',
                $counts['critical'],
                $counts['warning'],
                $counts['serp_gap'] + $counts['info'],
                count($items),
            );

        return [
            'key' => 'recommendations',
            'label' => 'Top fixes',
            'score' => $score,
            'weight' => 8,
            'detail' => $detail,
            'recommendation' => null,
            'items' => $items,
        ];
    }

    private function factorTracked(bool $tracked, ?string $focusKeyword): array
    {
        return [
            'key' => 'tracked',
            'label' => 'Tracked in Rank Tracker',
            'score' => $tracked ? 100 : 50,
            'weight' => 0, // ZERO-weight: surfaces the prompt + action, doesn't move the score
            'detail' => $tracked
                ? 'Focus keyword is in your Rank Tracker.'
                : 'Focus keyword is not yet tracked.',
            'recommendation' => $tracked
                ? null
                : 'Add this keyphrase to Rank Tracker so EBQ can monitor weekly position, SERP features, and competitor changes.',
            'action' => $tracked || $focusKeyword === null || $focusKeyword === ''
                ? null
                : [
                    'kind' => 'track-keyword',
                    'label' => 'Add to Rank Tracker',
                    'keyword' => $focusKeyword,
                ],
        ];
    }

    /**
     * Pending placeholder for an audit-derived factor when no audit exists yet.
     * `pending: true` keeps it out of the weighted average but still shows in
     * the breakdown so the user knows what's coming.
     */
    private function pendingFactor(string $key, string $label, string $message): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'score' => 0,
            'weight' => 0,
            'pending' => true,
            'detail' => $message,
            'recommendation' => null,
        ];
    }

    /* ──────────────────────────────────────────────────────────────
     *                      Data lookups
     * ──────────────────────────────────────────────────────────── */

    /**
     * Distinct referring DOMAINS (not URLs) for any of the URL variants.
     * Counts in PHP rather than SQL so we don't have to write a portable
     * "extract host from URL" expression — the row volumes here are tiny
     * (one website, one page).
     */
    private function countReferringDomains(string $websiteId, array $variants): int
    {
        $refUrls = Backlink::query()
            ->where('website_id', $websiteId)
            ->whereIn('target_page_url', $variants)
            ->pluck('referring_page_url')
            ->all();

        $domains = [];
        foreach ($refUrls as $u) {
            $host = parse_url((string) $u, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domains[strtolower(preg_replace('/^www\./', '', $host) ?: $host)] = true;
            }
        }

        return count($domains);
    }

    /* ──────────────────────────────────────────────────────────────
     *                     Score curves + helpers
     * ──────────────────────────────────────────────────────────── */

    /**
     * Position 1 = 100, position 100 = 0, decaying logarithmically so the
     * 1→3 jump is worth more than 30→50.
     */
    private function positionScore(float $pos): int
    {
        if ($pos <= 1) return 100;
        if ($pos >= 100) return 0;
        $score = 100 - (log10($pos) / log10(100)) * 100;

        return (int) round(max(0, min(100, $score)));
    }

    /**
     * Approximate AWR / Sistrix CTR-by-rank curve. Position 1 ≈ 30%,
     * 5 ≈ 6%, 10 ≈ 2.5%, 20+ ≈ <1%. Used as the "expected" baseline.
     */
    private function expectedCtrForPosition(float $pos): float
    {
        if ($pos <= 1) return 0.30;
        if ($pos <= 2) return 0.20;
        if ($pos <= 3) return 0.13;
        if ($pos <= 4) return 0.10;
        if ($pos <= 5) return 0.07;
        if ($pos <= 10) return 0.025;
        if ($pos <= 20) return 0.010;

        return 0.005;
    }

    /**
     * 100 = at expected, 130%+ = 100 (capped), under expected scales linearly.
     */
    private function ctrScore(float $actual, float $expected): int
    {
        if ($expected <= 0) return 50;
        $ratio = $actual / $expected;
        if ($ratio >= 1.0) return 100;

        return (int) round(max(0, min(100, $ratio * 100)));
    }

    /**
     * 0 queries = 0, 5 ≈ 50, 20+ ≈ 100. Plateau so a 200-query post
     * doesn't dwarf a focused 30-query post.
     */
    private function coverageScore(int $count): int
    {
        if ($count <= 0) return 0;
        if ($count >= 20) return 100;

        return (int) round(($count / 20) * 100);
    }

    /**
     * Backlink referring-domain curve. 0 = 0, 5 ≈ 50, 50+ = 100, log scale
     * so the 5→20 jump matters more than 100→200.
     */
    private function backlinksScore(int $domains): int
    {
        if ($domains <= 0) return 0;
        if ($domains >= 50) return 100;
        // log10 maps 1..50 onto roughly 0..1.7
        return (int) round(min(100, (log10(1 + $domains) / log10(51)) * 100));
    }

    private function buildExplanation(int $score, float $rankBasis, bool $cannibalized, int $coverage, bool $kwKnown, string $auditStatus): string
    {
        if (in_array($auditStatus, ['queued', 'running'], true)) {
            return 'EBQ is auditing this page in the background. The score above uses GSC + indexing + backlinks while the on-page audit completes — it will refresh automatically with Core Web Vitals, performance, on-page SEO, technical health, and content-quality factors once the audit finishes.';
        }
        if ($auditStatus === 'refreshing') {
            return 'Post was edited since the last audit — EBQ is re-auditing now. The audit-derived factors below show the previous run\'s data; they\'ll refresh automatically when the new audit completes.';
        }
        if ($score >= 65) {
            return sprintf('Live performance is strong. Average rank %.0f across %d queries. Keep adding internal links and tracking competitor moves to defend the position.', $rankBasis, $coverage);
        }
        $reasons = [];
        if ($rankBasis > 20) $reasons[] = 'rank is below page 2';
        if ($cannibalized)   $reasons[] = 'another URL competes for the same keyword';
        if ($coverage < 5)   $reasons[] = 'the page only ranks for a handful of queries';
        if (! $kwKnown)      $reasons[] = "we don't have a confirmed focus-keyword rank yet";
        if (empty($reasons)) $reasons[] = 'multiple soft signals are below benchmark';

        return 'Why low: ' . implode(', ', $reasons) . '.';
    }

    /* ──────────────────────────────────────────────────────────────
     *                   Diagnostics + unavailable
     * ──────────────────────────────────────────────────────────── */

    /**
     * When GSC returns nothing for the URL, attach diagnostic counters
     * to the unavailable response so we can quickly see WHY.
     */
    private function buildDiagnostics(Website $website, string $url, array $variants): array
    {
        $totalRows = (int) SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->count();
        $latestSync = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->max('date');

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $hostNoWww = preg_replace('/^www\./', '', $host) ?: $host;

        $similar = [];
        if ($path !== '/' && $path !== '') {
            $tail = '%' . addcslashes(rtrim($path, '/'), '\\%_') . '%';
            $similar = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where('page', 'LIKE', $tail)
                ->select('page')->distinct()->limit(5)
                ->pluck('page')->all();
        } elseif ($hostNoWww !== '') {
            $h = addcslashes($hostNoWww, '\\%_');
            $similar = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where(function ($q) use ($h) {
                    $q->where('page', 'LIKE', '%://' . $h)
                      ->orWhere('page', 'LIKE', '%://' . $h . '/')
                      ->orWhere('page', 'LIKE', '%://www.' . $h)
                      ->orWhere('page', 'LIKE', '%://www.' . $h . '/')
                      ->orWhere('page', 'LIKE', '%://' . $h . '?%')
                      ->orWhere('page', 'LIKE', '%://' . $h . '/?%')
                      ->orWhere('page', 'LIKE', '%://www.' . $h . '?%')
                      ->orWhere('page', 'LIKE', '%://www.' . $h . '/?%');
                })
                ->select('page')->distinct()->limit(5)
                ->pluck('page')->all();
        }

        return [
            'queried_url'             => $url,
            'queried_path'            => $path,
            'tried_variants'          => $variants,
            'gsc_rows_total_all_time' => $totalRows,
            'gsc_last_sync_date'      => $latestSync ? (string) $latestSync : null,
            'similar_urls_in_gsc'     => $similar,
        ];
    }

    private function unavailable(string $reason, array $debug = []): array
    {
        return [
            'score' => 0,
            'label' => 'Unavailable',
            'available' => false,
            'audit' => [
                'status' => 'unavailable',
                'message' => 'No live score yet — see explanation.',
            ],
            'factors' => [],
            'explanation' => match ($reason) {
                'url_not_for_website' => 'This URL doesn\'t belong to your connected website.',
                'no_gsc_data_for_url' => 'No Google Search Console data for this URL yet — give Google a few days to crawl + impressions to accrue.',
                default => 'Live score is not available for this post.',
            },
            'reason' => $reason,
            'debug'  => $debug,
        ];
    }
}
