<?php

namespace App\Support;

use App\Models\PluginAuthFailure;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Remembers WordPress installs whose API calls keep being rejected.
 *
 * The problem this exists for: a dead token is the quietest failure the
 * platform has. The plugin degrades gracefully — feature flags fail open,
 * pages still render — so the client sees a working plugin while every hourly
 * call is turned away into an access log nobody reads.
 *
 * Two shapes, both seen on production (2026-09-27 scan):
 *
 *   - **token_rejected (401)** — no such token. Usually because the website was
 *     deleted on our side and its tokens cascaded away. gbwhatsapp.app has been
 *     posting every half hour on plugin v1.0.5 against an account that no
 *     longer exists; the logs only go back 14 days and it fails in all of them.
 *   - **website_missing (403)** — the token row survived but its website did
 *     not, so Sanctum resolves a tokenable of null. simcardairportbali.com did
 *     this for six days before the client gave up and removed the plugin.
 *
 * Keyed by HOST, never by website: the installs worth chasing are precisely the
 * ones we can no longer attribute to a customer. The host comes from the
 * plugin's own User-Agent, which is the only identifier a rejected request
 * carries, and it is never trusted for anything but this bookkeeping.
 */
class PluginAuthHealth
{
    /** Below this, a failure is just a reconnect in progress. */
    public const ALARM_AFTER_HOURS = 24;

    /** One write per host per hour is plenty for an hourly heartbeat. */
    private const THROTTLE_SECONDS = 3600;

    /**
     * Ceiling on actively-failing hosts we will track.
     *
     * The host comes from a header, so anyone can claim one. The throttle caps
     * a single host to one row an hour, but not the number of distinct hosts a
     * script could invent. This bounds the table; real life is a handful of
     * installs, so hitting it means someone is playing, not that we are missing
     * customers.
     */
    private const MAX_TRACKED_HOSTS = 500;

    /** Rows quiet this long are history — the digest already ignores them. */
    private const PRUNE_AFTER_DAYS = 30;

    /**
     * Record that a request from one of our plugin installs was rejected.
     *
     * Deliberately cheap and silent: throttled, and never throws — this runs on
     * an already-failing request and must not turn a 401 into a 500.
     */
    public static function recordFailure(?string $userAgent, string $reason = PluginAuthFailure::REASON_TOKEN_REJECTED): void
    {
        $host = self::hostFromUserAgent($userAgent);
        if ($host === null) {
            return;
        }
        if (! Cache::add('plugin-auth-fail:'.$host, true, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            // A website is a bonus, not a requirement. When it is missing, the
            // row still gets written — that is the case worth alarming on.
            $website = Website::query()
                ->where('normalized_domain', $host)
                ->orWhere('domain', $host)
                ->first();

            $row = PluginAuthFailure::query()->firstOrNew(['host' => $host]);
            if (! $row->exists && self::tracked() >= self::MAX_TRACKED_HOSTS) {
                Log::warning('plugin.auth_health_capped', ['host' => $host, 'tracked' => self::MAX_TRACKED_HOSTS]);

                return;
            }
            $row->website_id = $website?->id;
            $row->site_url ??= 'https://'.$host;
            $row->plugin_version = self::versionFromUserAgent($userAgent) ?? $row->plugin_version;
            $row->reason = $reason;
            // Set once per run of failures, never bumped: "failing for 63 days"
            // is the number that makes the problem obvious, and a per-request
            // reset would make a months-old outage look a minute old.
            $row->first_seen_at ??= now();
            $row->last_seen_at = now();
            $row->failures = (int) $row->failures + 1;
            $row->save();

            Log::info('plugin.auth_failing', [
                'host' => $host,
                'reason' => $reason,
                'website_id' => $website?->id,
                'since' => $row->first_seen_at?->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('plugin.auth_health_write_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }
    }

    /**
     * A request authenticated, so whatever was wrong is fixed.
     *
     * Guarded by its own hourly key so the success path — which every healthy
     * install hits constantly — does not pay for a query per request.
     */
    public static function recordSuccess(Website $website): void
    {
        if (! Cache::add('plugin-auth-ok:'.$website->id, true, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            $host = strtolower((string) ($website->normalized_domain ?: $website->domain));
            PluginAuthFailure::query()
                ->where('website_id', $website->id)
                ->orWhere('host', $host)
                ->delete();
        } catch (\Throwable $e) {
            Log::debug('plugin.auth_health_clear_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }
    }

    private static function tracked(): int
    {
        return PluginAuthFailure::query()->where('last_seen_at', '>', now()->subDays(self::PRUNE_AFTER_DAYS))->count();
    }

    /**
     * Forget installs that stopped calling a month ago — they were uninstalled,
     * and keeping them would slowly turn this into a graveyard.
     */
    public static function prune(): int
    {
        try {
            return PluginAuthFailure::query()->where('last_seen_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS))->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Installs failing long enough to be a real fault rather than a reconnect,
     * and still calling.
     *
     * "Still calling" matters: an install that stopped altogether has been
     * removed, and chasing it wastes the reader's attention — which is how an
     * alarm stops being believed.
     *
     * @return Collection<int, PluginAuthFailure>
     */
    public static function failingInstalls(): Collection
    {
        return PluginAuthFailure::query()
            ->with('website:id,domain,user_id')
            ->where('first_seen_at', '<', now()->subHours(self::ALARM_AFTER_HOURS))
            ->where('last_seen_at', '>', now()->subDay())
            ->orderBy('first_seen_at')
            ->get();
    }

    /**
     * The host a Serfix plugin request came from, or null when the caller is
     * not our plugin.
     *
     * The UA is set by EBQ_Api_Client::request():
     *   "Serfix-SEO-WP/2.1.0; https://example.com"
     * Older builds used the EBQ name and are still in the wild — gbwhatsapp.app
     * is running 1.0.5 — so both are matched.
     */
    public static function hostFromUserAgent(?string $userAgent): ?string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '' || ! preg_match('#^(?:Serfix|EBQ)-SEO-WP/#i', $ua)) {
            return null;
        }
        if (! preg_match('#https?://[^\s;]+#i', $ua, $m)) {
            return null;
        }

        $host = strtolower((string) parse_url($m[0], PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host !== '' ? $host : null;
    }

    /** The plugin build that is calling, so the digest can say "still on 1.0.5". */
    public static function versionFromUserAgent(?string $userAgent): ?string
    {
        if (preg_match('#^(?:Serfix|EBQ)-SEO-WP/([0-9][0-9A-Za-z.\-]*)#i', trim((string) $userAgent), $m) !== 1) {
            return null;
        }

        return mb_substr($m[1], 0, 40);
    }
}
