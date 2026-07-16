<?php

namespace App\Services\Reports;

/**
 * Deterministic backlink toxicity layer — turns the raw backlink profile into
 * risk interpretation (the "which links are hurting me" layer):
 *
 *  - per-row flags: `tox` = high | medium | null on backlinks /
 *    top_referring_domains / anchors rows, with human-readable `tox_why`
 *  - payload['link_risk'] summary: toxic domain list (feeds the disavow
 *    export), toxic-anchor examples, anchor over-optimization warning, level
 *
 * Signals (all from data already in the payload — no API calls, pure math,
 * recomputed on every read so old snapshots gain it instantly):
 *  1. Link-selling / hacked-site / gambling / pharma anchor patterns
 *     (e.g. "TELEGRAM @… SEO BACKLINKS", "ACCESS TO HACKED SITES").
 *  2. Link-network naming patterns: ≥3 sibling domains sharing a numbered
 *     stem (link-legion-23.xyz, link-legion-94.xyz, …) with no authority.
 *  3. Disposable-TLD + zero-authority combinations (supporting signal only).
 */
class BacklinkToxicityScorer
{
    public const VERSION = 1;

    /** Anchor-text patterns that indicate link selling / hacked sites / spam verticals. */
    private const TOXIC_ANCHOR_PATTERNS = [
        '/telegram|\bt\.me\//iu',
        '/(^|[\s|–—-])@[a-z0-9_]{4,}/iu',           // @handle being advertised
        '/hacked|black[\s-]?hat|black[\s-]?links?\b/iu',
        '/\bpbn\b|\bxrumer\b|\bgsa ser\b/iu',
        '/seo (back)?links|buy (back)?links|links? (order|selling|posting|for sale)|mass backlink|link indexing|crosslinks?/iu',
        '/casino|\bslots?\b|bookmaker|betting|порно|казино/iu',
        '/viagra|cialis|essay writ|заказать/iu',
        '/富\d|娱乐城|博彩|百家乐|카지노|バカラ/u',
    ];

    /** Disposable / spam-heavy TLDs (supporting signal, never sufficient alone). */
    private const RISKY_TLDS = ['xyz', 'top', 'icu', 'click', 'gq', 'tk', 'cf', 'ml',
        'buzz', 'cyou', 'sbs', 'monster', 'quest', 'rest', 'bond', 'lol'];

    /** Sibling count at which a numbered-stem group counts as a link network. */
    private const NETWORK_MIN_SIBLINGS = 3;

    /**
     * Idempotent: flags rows + writes payload['link_risk']. Cheap enough to
     * run on every read (mirrors the TopicSignal refresh pattern).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function analyze(array $payload): array
    {
        $referring = is_array($payload['top_referring_domains'] ?? null) ? $payload['top_referring_domains'] : [];
        $backlinks = is_array($payload['backlinks'] ?? null) ? $payload['backlinks'] : [];
        $anchors = is_array($payload['anchors'] ?? null) ? $payload['anchors'] : [];

        $networkDomains = $this->detectNetworks($referring);
        $toxicDomains = $networkDomains;   // domain => reason
        $suspiciousDomains = [];

        // Referring-domain rows: network membership / disposable+dead combo.
        foreach ($referring as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            if (isset($networkDomains[$domain])) {
                $referring[$i]['tox'] = 'high';
                $referring[$i]['tox_why'] = $networkDomains[$domain];
            } elseif ($this->isDisposableDead($domain, $row)) {
                $referring[$i]['tox'] = 'medium';
                $referring[$i]['tox_why'] = 'Disposable TLD with no measurable authority';
                $suspiciousDomains[$domain] = true;
            }
        }

        // Backlink rows: toxic anchors (+ inherit domain verdicts).
        foreach ($backlinks as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $domain = strtolower((string) parse_url((string) ($row['url_from'] ?? ''), PHP_URL_HOST));
            $anchorReason = $this->toxicAnchorReason((string) ($row['anchor'] ?? ''));
            if ($anchorReason !== null) {
                $backlinks[$i]['tox'] = 'high';
                $backlinks[$i]['tox_why'] = $anchorReason;
                if ($domain !== '') {
                    $toxicDomains[$domain] ??= $anchorReason;
                }
            } elseif (isset($toxicDomains[$domain])) {
                $backlinks[$i]['tox'] = 'high';
                $backlinks[$i]['tox_why'] = $toxicDomains[$domain];
            } elseif (isset($suspiciousDomains[$domain])) {
                $backlinks[$i]['tox'] = 'medium';
                $backlinks[$i]['tox_why'] = 'Disposable TLD with no measurable authority';
            }
        }

        // Anchor aggregate rows (where sold-link campaigns are loudest).
        $toxicAnchorBacklinks = 0;
        $examples = [];
        foreach ($anchors as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $reason = $this->toxicAnchorReason((string) ($row['anchor'] ?? ''));
            if ($reason !== null) {
                $anchors[$i]['tox'] = 'high';
                $anchors[$i]['tox_why'] = $reason;
                $toxicAnchorBacklinks += (int) ($row['backlinks'] ?? 0);
                if (count($examples) < 3) {
                    $examples[] = [
                        'anchor' => mb_substr((string) $row['anchor'], 0, 90),
                        'backlinks' => (int) ($row['backlinks'] ?? 0),
                        'referring_domains' => (int) ($row['referring_domains'] ?? 0),
                    ];
                }
            }
        }

        // Anchor over-optimization (penalty-risk signal on its own).
        $exactPct = (int) ($payload['anchor_types']['exact'] ?? 0);
        $overOptimized = $exactPct >= 40;

        $level = null;
        if (count($toxicDomains) >= self::NETWORK_MIN_SIBLINGS || $toxicAnchorBacklinks >= 25) {
            $level = 'high';
        } elseif ($toxicDomains !== [] || $overOptimized || count($suspiciousDomains) >= 5) {
            $level = 'medium';
        }

        $payload['top_referring_domains'] = $referring;
        $payload['backlinks'] = $backlinks;
        $payload['anchors'] = $anchors;
        $payload['link_risk'] = [
            'version' => self::VERSION,
            'level' => $level,
            'toxic_domains' => array_slice(array_keys($toxicDomains), 0, 500),
            'toxic_domain_count' => count($toxicDomains),
            'suspicious_domain_count' => count($suspiciousDomains),
            'toxic_anchor_backlinks' => $toxicAnchorBacklinks,
            'toxic_anchor_examples' => $examples,
            'exact_pct' => $exactPct,
            'over_optimized' => $overOptimized,
        ];

        return $payload;
    }

    private function toxicAnchorReason(string $anchor): ?string
    {
        if (trim($anchor) === '') {
            return null;
        }
        foreach (self::TOXIC_ANCHOR_PATTERNS as $i => $pattern) {
            if (preg_match($pattern, $anchor)) {
                return match (true) {
                    $i <= 1 => 'Anchor advertises a link-selling service',
                    $i === 2 => 'Anchor references hacked sites / black-hat links',
                    $i <= 4 => 'Anchor advertises bulk link schemes',
                    default => 'Spam-vertical anchor (gambling / pharma / adult)',
                };
            }
        }

        return null;
    }

    /**
     * Numbered-stem sibling groups (foo-1.xyz, foo-23.xyz, …) with no
     * authority → link network. Returns domain => reason for every member.
     *
     * @param  list<array<string, mixed>>  $referring
     * @return array<string, string>
     */
    private function detectNetworks(array $referring): array
    {
        $groups = [];
        foreach ($referring as $row) {
            if (! is_array($row)) {
                continue;
            }
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            if ($domain === '' || ! preg_match('/^(.+?)[-.]?\d{1,4}\.([a-z.]{2,10})$/', $domain, $m)) {
                continue;
            }
            // Only weightless domains join a network candidate group — keeps
            // legit numbered brands (4chan, 9gag-style) out.
            $weightless = ((int) ($row['rank'] ?? 0)) <= 50
                && (float) ($row['opr_score'] ?? 0) < 1.0
                && ((int) ($row['cs'] ?? 0)) <= 5;
            if (! $weightless) {
                continue;
            }
            $groups[$m[1].'.'.$m[2]][] = $domain;
        }

        $out = [];
        foreach ($groups as $stem => $members) {
            if (count($members) >= self::NETWORK_MIN_SIBLINGS) {
                $why = 'Part of a link network ('.count($members).' sibling domains, same naming pattern, no authority)';
                foreach ($members as $d) {
                    $out[$d] = $why;
                }
            }
        }

        return $out;
    }

    private function isDisposableDead(string $domain, array $row): bool
    {
        $tld = strtolower((string) substr(strrchr($domain, '.') ?: '', 1));

        return in_array($tld, self::RISKY_TLDS, true)
            && ((int) ($row['rank'] ?? 0)) <= 50
            && (float) ($row['opr_score'] ?? 0) < 1.0
            && (($row['ts'] ?? null) === null || (int) $row['ts'] <= 5)
            && ((int) ($row['cs'] ?? 0)) <= 5;
    }
}
