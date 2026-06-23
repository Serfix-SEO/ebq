<?php

namespace App\Support\Crawler;

/**
 * Minimal robots.txt parser: groups User-agent records, picks the most
 * specific group for our crawler (falls back to '*'), and resolves
 * Allow/Disallow precedence the way Google documents it — the longest
 * matching pattern wins; ties favor Allow. Supports '*' wildcards and a
 * trailing '$' end-anchor (the de-facto extensions every major crawler
 * already implements beyond the bare RFC).
 */
class RobotsTxtParser
{
    /**
     * True when $path is blocked for $userAgentTokens (lowercase product
     * tokens, e.g. ['googlebot']) under the given robots.txt text.
     */
    public static function isBlocked(string $robotsTxt, string $path, array $userAgentTokens = ['googlebot']): bool
    {
        $records = self::parseRecords($robotsTxt);
        $rules = self::rulesForAgent($records, $userAgentTokens);
        if ($rules === []) {
            return false;
        }

        $best = null; // ['type' => 'allow'|'disallow', 'len' => int]
        foreach ($rules as $rule) {
            if ($rule['path'] === '' || ! self::patternMatches($rule['path'], $path)) {
                continue;
            }
            $len = strlen($rule['path']);
            if ($best === null || $len > $best['len'] || ($len === $best['len'] && $rule['type'] === 'allow')) {
                $best = ['type' => $rule['type'], 'len' => $len];
            }
        }

        return $best !== null && $best['type'] === 'disallow';
    }

    /**
     * @return list<array{agents: list<string>, rules: list<array{type: string, path: string}>}>
     */
    private static function parseRecords(string $robotsTxt): array
    {
        $records = [];
        $idx = -1;
        $collectingAgents = true;

        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'user-agent') {
                if ($idx === -1 || ! $collectingAgents) {
                    $records[] = ['agents' => [], 'rules' => []];
                    $idx++;
                }
                $records[$idx]['agents'][] = strtolower($value);
                $collectingAgents = true;
            } elseif (in_array($key, ['disallow', 'allow'], true)) {
                if ($idx === -1) {
                    continue;
                }
                $records[$idx]['rules'][] = ['type' => $key, 'path' => $value];
                $collectingAgents = false;
            } else {
                $collectingAgents = false;
            }
        }

        return $records;
    }

    /** @return list<array{type: string, path: string}> */
    private static function rulesForAgent(array $records, array $userAgentTokens): array
    {
        foreach ($records as $record) {
            foreach ($record['agents'] as $agent) {
                if ($agent !== '*' && in_array($agent, $userAgentTokens, true)) {
                    return $record['rules'];
                }
            }
        }
        foreach ($records as $record) {
            if (in_array('*', $record['agents'], true)) {
                return $record['rules'];
            }
        }

        return [];
    }

    private static function patternMatches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $core = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^'.implode('.*', array_map(
            fn (string $p): string => preg_quote($p, '#'),
            explode('*', $core)
        )).($anchored ? '$' : '').'#';

        return (bool) preg_match($regex, $path);
    }
}
