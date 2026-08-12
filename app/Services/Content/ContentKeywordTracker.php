<?php

namespace App\Services\Content;

use App\Models\ContentTopic;
use App\Models\ContentTrackedKeyword;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Adds / removes keywords in the Content Autopilot Keyword Tracker, enforcing the
 * per-website capacity (KeywordTrackerQuota). Used by the publish job (source=auto)
 * and the article detail / tracker UI (source=manual).
 */
class ContentKeywordTracker
{
    public function __construct(private KeywordTrackerQuota $quota) {}

    /**
     * Track a set of keywords for a website. Normalizes + dedupes against what's
     * already tracked, then inserts up to the remaining capacity. If the cap is
     * hit before every fresh keyword is added, `capped` is true (the UI shows the
     * delete-to-add banner).
     *
     * @param  string[]  $keywords  ordered; the first (or $primaryKeyword) is flagged is_primary
     * @return array{added:int, skipped:int, capped:bool}
     */
    public function track(
        Website $website,
        array $keywords,
        ?ContentTopic $topic = null,
        string $source = ContentTrackedKeyword::SOURCE_MANUAL,
        ?User $user = null,
        ?string $primaryKeyword = null,
        ?string $pageUrl = null,
    ): array {
        $primaryNorm = ContentTrackedKeyword::normalize(
            $primaryKeyword ?? ($topic?->target_keyword ?? '')
        );

        // Normalize + dedupe the input, keeping the first display form seen.
        $candidates = [];
        foreach ($keywords as $kw) {
            $display = trim((string) $kw);
            if ($display === '') {
                continue;
            }
            $norm = ContentTrackedKeyword::normalize($display);
            if ($norm === '' || isset($candidates[$norm])) {
                continue;
            }
            $candidates[$norm] = $display;
        }

        if ($candidates === []) {
            return ['added' => 0, 'skipped' => 0, 'capped' => false];
        }

        $existing = ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->whereIn('normalized_keyword', array_keys($candidates))
            ->pluck('normalized_keyword')
            ->all();
        $existing = array_flip($existing);

        $fresh = array_diff_key($candidates, $existing);
        $skipped = count($candidates) - count($fresh);

        $remaining = $this->quota->remaining($website);
        $capped = count($fresh) > $remaining;
        $toAdd = array_slice($fresh, 0, max(0, $remaining), true);

        $added = 0;
        foreach ($toAdd as $norm => $display) {
            try {
                ContentTrackedKeyword::create([
                    'website_id' => $website->id,
                    'topic_id' => $topic?->id,
                    'keyword' => mb_substr($display, 0, 200),
                    'normalized_keyword' => mb_substr($norm, 0, 200),
                    'page_url' => $pageUrl,
                    'is_primary' => $norm === $primaryNorm,
                    'source' => $source,
                    'added_by_user_id' => $user?->id,
                ]);
                $added++;
            } catch (QueryException $e) {
                // Unique race (another request tracked it first) — treat as skipped.
                Log::debug('content_tracker.duplicate', ['website_id' => $website->id, 'keyword' => $norm]);
            }
        }

        // Freshly tracked keywords get their live position right away instead
        // of waiting for the next daily run — the UI shows "Checking…" from
        // the moment of adding, so make that true. The job is unique per
        // website and only queries never-checked/stale rows, so double
        // dispatches (e.g. the publish job dispatches too) are harmless.
        if ($added > 0) {
            \App\Jobs\CheckTrackedKeywordSerpJob::dispatch($website->id);
        }

        return ['added' => $added, 'skipped' => $skipped, 'capped' => $capped];
    }

    /** Remove a tracked keyword by id, scoped to the website (delete-to-add). */
    public function untrack(Website $website, string $trackedKeywordId): bool
    {
        return (bool) ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->whereKey($trackedKeywordId)
            ->delete();
    }

    public function isTracked(Website $website, string $keyword): bool
    {
        return ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->where('normalized_keyword', ContentTrackedKeyword::normalize($keyword))
            ->exists();
    }

    /** The keywords a topic targets, primary first (target + secondaries). */
    public function keywordsFor(ContentTopic $topic): array
    {
        $out = [];
        $primary = trim((string) $topic->target_keyword);
        if ($primary !== '') {
            $out[] = $primary;
        }
        foreach ((array) ($topic->secondary_keywords ?? []) as $kw) {
            $kw = trim((string) $kw);
            if ($kw !== '') {
                $out[] = $kw;
            }
        }

        return $out;
    }
}
