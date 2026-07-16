<?php

namespace App\Services\Reports;

use App\Services\OpenPageRankClient;

/**
 * Trust Score / Citation Score — our own 0–100 authority metrics, computed
 * deterministically from data ALREADY present in a report payload (DataForSEO
 * summary + referring-domain ranks + Open PageRank). No provider calls, no
 * I/O: pure payload-in → payload-out, so scores can be backfilled onto any
 * previously cached snapshot at read time without a schema bump.
 *
 *  - Citation Score = link POPULARITY (PageRank-family blend).
 *  - Trust Score    = link QUALITY (spam, one-hop authority share, diversity,
 *                     trusted TLDs, curated seed list).
 *
 * Naming is deliberate: these are OUR metrics on OUR scale. Never label them
 * "Trust Flow"/"Citation Flow" anywhere (Majestic trademarks), and never
 * present them as third-party numbers.
 *
 * When the Common Crawl web-graph sidecar has been imported (see
 * CcDomainRanks + ebq:import-cc-webgraph), the wiring stashes the domain's
 * percentiles into payload['cc'] = [citation_pct, trust_pct] before calling
 * augment(); both formulas then include a genuine web-graph component. Absent
 * sidecar/domain → weights renormalize, nothing breaks.
 *
 * Formula changes MUST bump VERSION — augment() recomputes when the stored
 * version is older, and the public methodology page documents each version.
 * (Quarterly CC data refreshes do NOT bump the version — richer inputs, same
 * formula.)
 */
class AuthorityScoreCalculator
{
    public const VERSION = 3;

    /** Curated seed domains get at least this per-row Trust (editorially vetted). */
    private const SEED_ROW_TRUST_FLOOR = 85;

    /** Trust may plausibly exceed Citation only slightly (mirrors how quality
     *  rarely outruns popularity in link graphs). */
    private const TRUST_CEILING_MARGIN = 10;

    /** Trust cap when Citation is unknown entirely. */
    private const TRUST_CEILING_NO_CITATION = 55;

    /** Referring domain counts as "strong" at this DataForSEO rank (0–1000)… */
    private const STRONG_REF_RANK = 300;

    /** …or this Open PageRank score (0–10). */
    private const STRONG_REF_OPR = 4.0;

    /** @var list<string> */
    private array $seedDomains;

    /**
     * @param  list<string>|null  $seedDomains  override for tests; defaults to
     *                                          config/trusted_seed_domains.php
     */
    public function __construct(?array $seedDomains = null)
    {
        $this->seedDomains = $seedDomains ?? (array) config('trusted_seed_domains', []);
    }

    /**
     * Idempotently add `scores` + per-row `cs` to a payload. Recomputes when
     * absent or built by an older formula version; returns the payload
     * unchanged when already current (safe on every read).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /** Whether augment() would recompute (missing scores or older formula). */
    public function needsAugment(array $payload): bool
    {
        return (int) ($payload['scores']['version'] ?? 0) < self::VERSION;
    }

    public function augment(array $payload): array
    {
        if (! $this->needsAugment($payload)) {
            // TopicSignal is refreshed on EVERY read (cheap pure math): the
            // topical section fills in batch-by-batch AFTER the scores were
            // stamped, so it can't hide behind the version gate.
            return $this->withTopicalScore($payload);
        }

        $payload['scores'] = $this->scores($payload);

        foreach (['top_referring_domains', 'backlinks', 'competitors'] as $section) {
            if (! is_array($payload[$section] ?? null)) {
                continue;
            }
            foreach ($payload[$section] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                // Competitor rows carry OPR but no 0–1000 DataForSEO rank.
                $rank = $section === 'competitors' ? null : $this->int($row['rank'] ?? null);
                $payload[$section][$i]['cs'] = $this->rowCitation(
                    $this->float($row['opr_score'] ?? null),
                    $rank,
                    $this->float($row['cc_citation'] ?? null),
                );
                $payload[$section][$i]['ts'] = $this->rowTrust(
                    $this->float($row['cc_trust'] ?? null),
                    $this->rowDomain($row),
                );
            }
        }

        return $this->withTopicalScore($payload);
    }

    /**
     * TopicSignal (TT): trust earned from topically-RELEVANT links only —
     * TrustSignal scaled by the relevant share of classified referring
     * domains: TT = TS · (0.4 + 0.6 · relevant_pct/100). Deterministic: the
     * topical inputs live in the payload (classified once, cached forever),
     * so the same snapshot always yields the same TT. Null until both a
     * TrustSignal and a topical sample exist.
     */
    private function withTopicalScore(array $payload): array
    {
        if (! isset($payload['scores']) || ! is_array($payload['scores'])) {
            return $payload;
        }

        $trust = $this->int($payload['scores']['trust'] ?? null);
        $sample = $this->int($payload['topical_trust']['sample'] ?? null);
        $relevantPct = $this->int($payload['topical_trust']['relevant_pct'] ?? null);

        $payload['scores']['topical'] = ($trust !== null && $sample !== null && $sample > 0 && $relevantPct !== null)
            ? (int) round($this->clamp($trust * (0.4 + 0.6 * $relevantPct / 100)))
            : null;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{citation: ?int, trust: ?int, version: int}
     */
    public function scores(array $payload): array
    {
        $citation = $this->citation($payload);
        $trust = $this->trust($payload, $citation);

        return ['citation' => $citation, 'trust' => $trust, 'version' => self::VERSION];
    }

    /**
     * Citation Score for a table row (referring domain / backlink source /
     * competitor). Weights renormalize when a component is missing.
     *
     * @param  float|null  $opr  Open PageRank score 0–10
     * @param  int|null  $rank  DataForSEO domain rank 0–1000
     * @param  float|null  $ccCitationPct  CC PageRank percentile 0–100 (sidecar)
     */
    public function rowCitation(?float $opr, ?int $rank, ?float $ccCitationPct = null): ?int
    {
        return $this->weighted([
            [$opr !== null ? $this->clamp($opr * 10) : null, 0.40],
            [$ccCitationPct !== null ? $this->clamp($ccCitationPct) : null, 0.35],
            [$rank !== null ? $this->clamp($rank / 10) : null, 0.25],
        ], minComponents: 1);
    }

    /**
     * Trust Score for a table row: the domain's harmonic-centrality percentile
     * from the CC web graph (graph-distance trust, hard to game), with a floor
     * for curated seed domains. Null (renders "—") when neither is known —
     * per-row spam/diversity signals don't exist, so no fake precision.
     */
    public function rowTrust(?float $ccTrustPct, ?string $domain): ?int
    {
        $trust = $ccTrustPct !== null ? $this->clamp($ccTrustPct) : null;

        if ($domain !== null && $domain !== '' && $this->isSeed($domain)) {
            $trust = max($trust ?? 0, self::SEED_ROW_TRUST_FLOOR);
        }

        return $trust !== null ? (int) round($trust) : null;
    }

    /** @deprecated use rowCitation() — kept for back-compat call sites. */
    public function rowScore(?float $opr, ?int $rank): ?int
    {
        return $this->rowCitation($opr, $rank, null);
    }

    /** The domain a table row refers to (referring/competitor rows carry it; backlink rows carry a source URL). */
    private function rowDomain(array $row): ?string
    {
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        if ($domain !== '') {
            return $domain;
        }
        $host = strtolower((string) parse_url((string) ($row['url_from'] ?? ''), PHP_URL_HOST));

        return $host !== '' ? $host : null;
    }

    private function isSeed(string $domain): bool
    {
        $registrable = OpenPageRankClient::registrable($domain);
        foreach ($this->seedDomains as $seed) {
            if ($registrable === $seed || $domain === $seed || str_ends_with($domain, '.'.$seed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Main-domain Citation Score: OPR (primary) + Common Crawl PageRank
     * percentile + DataForSEO rank + log-scaled referring-domain count.
     */
    private function citation(array $payload): ?int
    {
        $opr = $this->float($payload['popularity']['score'] ?? null);
        // gauges.authority_score is already DataForSEO rank / 10 (0–100).
        $rankPts = $this->float($payload['gauges']['authority_score'] ?? null);
        $rd = $this->int($payload['totals']['referring_domains'] ?? null);
        $ccCitation = $this->float($payload['cc']['citation_pct'] ?? null);

        return $this->weighted([
            [$opr !== null ? $this->clamp($opr * 10) : null, 0.35],
            [$ccCitation !== null ? $this->clamp($ccCitation) : null, 0.30],
            [$rankPts !== null ? $this->clamp($rankPts) : null, 0.20],
            [$rd !== null && $rd >= 0 ? $this->clamp(100 * log10(1 + $rd) / 6) : null, 0.15],
        ], minComponents: 1);
    }

    /**
     * Trust Score: weighted mean of up to six quality signals, renormalized
     * over whichever are present; null when fewer than two exist (partial
     * snapshots typically). Capped relative to Citation so the pair always
     * looks plausible.
     */
    private function trust(array $payload, ?int $citation): ?int
    {
        $spam = $this->int($payload['gauges']['spam_score'] ?? null);
        $dofollow = $this->int($payload['ratios']['dofollow_pct'] ?? null);
        // Harmonic-centrality percentile from the Common Crawl web graph —
        // a genuine graph-distance trust signal (spam farms rank poorly).
        $harmonic = $this->float($payload['cc']['trust_pct'] ?? null);

        $raw = $this->weighted([
            [$spam !== null ? $this->clamp(100 - $spam) : null, 0.25],
            [$harmonic !== null ? $this->clamp($harmonic) : null, 0.15],
            [$this->strongReferrerShare($payload), 0.10],
            [$dofollow !== null ? $this->clamp($dofollow * 1.25) : null, 0.15],
            [$this->ipDiversity($payload), 0.15],
            [$this->trustedTldShare($payload), 0.10],
            [$this->seedMatches($payload), 0.10],
        ], minComponents: 2);

        if ($raw === null) {
            return null;
        }

        $ceiling = $citation !== null
            ? $citation + self::TRUST_CEILING_MARGIN
            : self::TRUST_CEILING_NO_CITATION;

        return (int) min($raw, $ceiling);
    }

    /**
     * One-hop TrustRank-lite: what share of the sampled referring domains are
     * themselves strong? Needs a minimally meaningful sample (≥3 rows).
     */
    private function strongReferrerShare(array $payload): ?float
    {
        $rows = array_values(array_filter(
            is_array($payload['top_referring_domains'] ?? null) ? $payload['top_referring_domains'] : [],
            'is_array',
        ));
        if (count($rows) < 3) {
            return null;
        }

        $strong = 0;
        foreach ($rows as $row) {
            $rank = $this->int($row['rank'] ?? null);
            $opr = $this->float($row['opr_score'] ?? null);
            if (($rank !== null && $rank >= self::STRONG_REF_RANK)
                || ($opr !== null && $opr >= self::STRONG_REF_OPR)) {
                $strong++;
            }
        }

        return $this->clamp(250 * $strong / count($rows));
    }

    /**
     * Link-farm signal: farms concentrate many "domains" on few IPs/subnets.
     * A healthy profile has IP and subnet counts near its referring-domain
     * count; ≥0.8 of it scores full marks.
     */
    private function ipDiversity(array $payload): ?float
    {
        $rd = $this->int($payload['totals']['referring_domains'] ?? null);
        $ips = $this->int($payload['totals']['referring_ips'] ?? null);
        $subnets = $this->int($payload['totals']['referring_subnets'] ?? null);
        if ($rd === null || $rd <= 0 || ($ips === null && $subnets === null)) {
            return null;
        }

        $ratios = [];
        if ($ips !== null) {
            $ratios[] = min(1.0, $ips / $rd);
        }
        if ($subnets !== null) {
            $ratios[] = min(1.0, $subnets / $rd);
        }

        return $this->clamp(100 * min(1.0, (array_sum($ratios) / count($ratios)) / 0.8));
    }

    /**
     * Share of gov/edu/mil links among the listed TLD distribution (top-10
     * TLDs only — that's all the payload stores, which is fine: if gov/edu
     * links exist in any meaningful share they make that list).
     */
    private function trustedTldShare(array $payload): ?float
    {
        $tlds = $payload['profile_details']['tlds'] ?? null;
        if (! is_array($tlds) || $tlds === []) {
            return null;
        }

        $total = 0;
        $trusted = 0;
        foreach ($tlds as $row) {
            if (! is_array($row) || ! is_numeric($row['count'] ?? null)) {
                continue;
            }
            $count = (int) $row['count'];
            $total += $count;
            $label = strtolower(trim((string) ($row['label'] ?? ''), '.'));
            if (in_array($label, ['gov', 'edu', 'mil'], true) || str_ends_with($label, '.gov') || str_ends_with($label, '.edu')) {
                $trusted += $count;
            }
        }
        if ($total <= 0) {
            return null;
        }

        return $this->clamp(1000 * $trusted / $total);
    }

    /**
     * Curated seed list: each distinct referring domain whose registrable
     * domain is on the trusted list is worth 20 points (capped at 100).
     * Returns null (component skipped) when there are no referring rows at
     * all — zero rows says "no data", not "no trusted links".
     */
    private function seedMatches(array $payload): ?float
    {
        $rows = is_array($payload['top_referring_domains'] ?? null) ? $payload['top_referring_domains'] : [];
        if ($rows === [] || $this->seedDomains === []) {
            return null;
        }

        $matched = [];
        foreach ($rows as $row) {
            $domain = strtolower((string) (is_array($row) ? ($row['domain'] ?? '') : ''));
            if ($domain === '') {
                continue;
            }
            $registrable = OpenPageRankClient::registrable($domain);
            foreach ($this->seedDomains as $seed) {
                if ($registrable === $seed || $domain === $seed || str_ends_with($domain, '.'.$seed)) {
                    $matched[$registrable] = true;
                    break;
                }
            }
        }

        return $this->clamp(20 * count($matched));
    }

    /**
     * Weighted mean over present components, weights renormalized. Null when
     * fewer than $minComponents values are present.
     *
     * @param  list<array{0: float|null, 1: float}>  $components
     */
    private function weighted(array $components, int $minComponents): ?int
    {
        $sum = 0.0;
        $weightSum = 0.0;
        $present = 0;
        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }
            $sum += $value * $weight;
            $weightSum += $weight;
            $present++;
        }
        if ($present < $minComponents || $weightSum <= 0) {
            return null;
        }

        return (int) round($this->clamp($sum / $weightSum));
    }

    private function clamp(float $v): float
    {
        return max(0.0, min(100.0, $v));
    }

    private function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private function float(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
