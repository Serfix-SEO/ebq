<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Platform defaults for rank tracking (SERP depth, re-check interval).
 * Interval is configurable in Admin → Rank Tracker; depth is fixed at 100.
 */
class RankTrackerConfig
{
    public const SETTING_CHECK_INTERVAL = 'rank_tracker.default_check_interval_hours';

    public const DEFAULT_CHECK_INTERVAL_HOURS = 72;

    public const DEFAULT_DEPTH = 100;

    public static function checkIntervalHours(): int
    {
        $stored = Setting::get(self::SETTING_CHECK_INTERVAL, self::DEFAULT_CHECK_INTERVAL_HOURS);
        $hours = (int) ($stored ?? self::DEFAULT_CHECK_INTERVAL_HOURS);

        return max(1, min(168, $hours > 0 ? $hours : self::DEFAULT_CHECK_INTERVAL_HOURS));
    }

    /**
     * Build a full HTTPS URL from the connected domain and a path fragment.
     */
    /**
     * Parse a single "target" input (bare domain, domain/path, or full URL)
     * into its host and, when a real path is present, the full https target
     * URL. `url` is null for a domain-only input (match any URL on the host).
     *
     * @return array{host: string, url: ?string}
     */
    public static function parseTarget(string $input): array
    {
        $raw = trim($input);
        if ($raw === '') {
            return ['host' => '', 'url' => null];
        }

        $parseable = preg_match('#^https?://#i', $raw) ? $raw : 'https://'.$raw;
        $parts = parse_url($parseable);
        $host = self::normalizeHost((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return ['host' => '', 'url' => null];
        }

        $path = (string) ($parts['path'] ?? '');
        if (! empty($parts['query'])) {
            $path .= '?'.$parts['query'];
        }
        if (! empty($parts['fragment'])) {
            $path .= '#'.$parts['fragment'];
        }
        $path = ($path === '' || $path === '/') ? '' : $path;

        return ['host' => $host, 'url' => $path === '' ? null : 'https://'.$host.$path];
    }

    public static function normalizeTargetUrl(string $domain, ?string $pathOrUrl): ?string
    {
        $pathOrUrl = trim((string) $pathOrUrl);
        if ($pathOrUrl === '') {
            return null;
        }

        $host = self::normalizeHost($domain);
        if ($host === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $pathOrUrl)) {
            $parsed = parse_url($pathOrUrl);
            $urlHost = isset($parsed['host']) ? self::normalizeHost((string) $parsed['host']) : '';
            if ($urlHost !== $host) {
                return null;
            }
            $path = $parsed['path'] ?? '/';
            if (! empty($parsed['query'])) {
                $path .= '?'.$parsed['query'];
            }
            if (! empty($parsed['fragment'])) {
                $path .= '#'.$parsed['fragment'];
            }
        } else {
            $path = str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/'.$pathOrUrl;
        }

        return 'https://'.$host.$path;
    }

    /**
     * Path (+ query) portion for form prefill from a stored full URL.
     */
    public static function targetUrlPath(string $domain, ?string $fullUrl): string
    {
        $fullUrl = trim((string) $fullUrl);
        if ($fullUrl === '') {
            return '';
        }

        $host = self::normalizeHost($domain);
        $parsed = parse_url($fullUrl);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return '';
        }

        if (self::normalizeHost((string) $parsed['host']) !== $host) {
            return '';
        }

        $path = $parsed['path'] ?? '/';
        if (! empty($parsed['query'])) {
            $path .= '?'.$parsed['query'];
        }
        if (! empty($parsed['fragment'])) {
            $path .= '#'.$parsed['fragment'];
        }

        return $path === '/' ? '' : $path;
    }

    public static function targetUrlPrefix(string $domain): string
    {
        $host = self::normalizeHost($domain);

        return $host !== '' ? 'https://'.$host : '';
    }

    private static function normalizeHost(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        return strtolower($domain);
    }
}
