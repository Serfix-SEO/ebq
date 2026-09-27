<?php

namespace App\Support;

use App\Models\Website;
use App\Models\WebsitePluginInstall;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Remembers WordPress installs whose API calls keep coming back 401.
 *
 * The problem this exists for: a dead token is the quietest failure the
 * platform has. The plugin keeps rendering, feature flags fail open, no
 * exception is thrown and no job fails — the site simply stops exchanging data
 * with us. pubgnamegenerator.net did exactly that for two months
 * (2026-07-18 → 2026-09-26) and we only found out while testing something else.
 *
 * Attribution is the awkward part: a 401 has no authenticated website, so there
 * is nothing to key on. The plugin's own User-Agent carries its home URL
 * ("Serfix-SEO-WP/2.1.0; https://example.com"), which is enough to find the
 * Website — and we only ever match sites we already know, so a forged header
 * cannot invent one.
 */
class PluginAuthHealth
{
    /** Below this, a failure is just a reconnect in progress. */
    public const ALARM_AFTER_HOURS = 24;

    /** One write per site per hour is plenty for an hourly heartbeat. */
    private const THROTTLE_SECONDS = 3600;

    /**
     * Record that a request from this plugin install failed to authenticate.
     *
     * Deliberately cheap and silent: throttled to one DB write per site per
     * hour, matches only existing websites, and never throws — this runs on a
     * rejected request and must not turn a 401 into a 500.
     */
    public static function recordFailure(?string $userAgent): void
    {
        $host = self::hostFromUserAgent($userAgent);
        if ($host === null) {
            return;
        }
        if (! Cache::add('plugin-auth-fail:'.$host, true, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            $website = Website::query()
                ->where('normalized_domain', $host)
                ->orWhere('domain', $host)
                ->first();
            if ($website === null) {
                return;
            }

            $install = WebsitePluginInstall::query()->firstOrNew(['website_id' => $website->id]);
            $install->last_auth_failure_at = now();
            // Only the FIRST failure of a run sets the start, so the digest can
            // say how long this has been broken rather than "since a minute ago"
            // on every hourly retry.
            $install->auth_failing_since ??= now();
            $install->site_url ??= 'https://'.$host;
            $install->save();

            Log::info('plugin.auth_failing', [
                'website_id' => $website->id,
                'host' => $host,
                'since' => $install->auth_failing_since?->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('plugin.auth_health_write_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }
    }

    /**
     * A request authenticated, so whatever was wrong is fixed.
     *
     * Guarded by its own hourly cache key so the success path — which every
     * healthy install hits constantly — does not pay for a query per request.
     */
    public static function recordSuccess(string $websiteId): void
    {
        if (! Cache::add('plugin-auth-ok:'.$websiteId, true, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            WebsitePluginInstall::query()
                ->where('website_id', $websiteId)
                ->whereNotNull('auth_failing_since')
                ->update(['auth_failing_since' => null, 'last_auth_failure_at' => null]);
        } catch (\Throwable $e) {
            Log::debug('plugin.auth_health_clear_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }
    }

    /**
     * Installs that have been failing long enough to be a real fault rather
     * than a reconnect, and that are still trying.
     *
     * "Still trying" matters: a site that stopped calling altogether (plugin
     * removed, site gone) is not something to chase, so the last failure must
     * be recent.
     *
     * @return Collection<int, WebsitePluginInstall>
     */
    public static function failingInstalls(): Collection
    {
        return WebsitePluginInstall::query()
            ->with('website:id,domain,user_id')
            ->whereNotNull('auth_failing_since')
            ->where('auth_failing_since', '<', now()->subHours(self::ALARM_AFTER_HOURS))
            ->where('last_auth_failure_at', '>', now()->subDay())
            ->orderBy('auth_failing_since')
            ->get();
    }

    /**
     * The host a Serfix plugin request came from, or null when the caller is
     * not our plugin.
     *
     * The UA is set by EBQ_Api_Client::request():
     *   "Serfix-SEO-WP/2.1.0; https://example.com"
     * Older builds used the EBQ name, which still exists in the wild.
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
}
