<?php

namespace App\Services\KeywordResearch;

/**
 * Heuristic search-intent classifier for keyword-research rows.
 *
 * Deterministic, free, and instant — every result row gets a badge without an
 * LLM round-trip. Pattern buckets follow the industry convention:
 *
 *   informational — questions / learning ("how to fix cls", "what is seo")
 *   commercial    — evaluation before buying ("best crm", "ahrefs vs semrush")
 *   transactional — ready to act ("buy running shoes", "seo agency pricing")
 *   navigational  — going somewhere specific ("hubspot login", "gmail app")
 *
 * Anything unmatched returns 'other' (rendered as a muted badge) rather than
 * guessing. English-centric by design; the AI cluster pass can refine later.
 */
class KeywordIntentClassifier
{
    public const INTENTS = ['informational', 'commercial', 'transactional', 'navigational', 'other'];

    private const QUESTION_WORDS = [
        'how', 'what', 'why', 'when', 'where', 'who', 'which', 'can', 'does', 'do', 'is', 'are', 'should',
    ];

    private const INFORMATIONAL = [
        'guide', 'tutorial', 'examples', 'example', 'ideas', 'tips', 'meaning', 'definition', 'learn',
        'checklist', 'template', 'templates',
    ];

    private const COMMERCIAL = [
        'best', 'top', 'review', 'reviews', 'compare', 'comparison', 'vs', 'versus', 'alternative',
        'alternatives', 'ranking', 'rated',
    ];

    private const TRANSACTIONAL = [
        'buy', 'price', 'prices', 'pricing', 'cost', 'cheap', 'cheapest', 'discount', 'coupon', 'deal',
        'deals', 'order', 'for sale', 'near me', 'hire', 'quote', 'free trial', 'subscription', 'purchase',
    ];

    private const NAVIGATIONAL = [
        'login', 'log in', 'sign in', 'signin', 'sign up', 'signup', 'download', 'app', 'website',
        'official', 'account', 'dashboard',
    ];

    public static function classify(string $keyword): string
    {
        $kw = ' '.mb_strtolower(trim($keyword)).' ';
        if (trim($kw) === '') {
            return 'other';
        }

        $has = fn (array $terms): bool => (bool) array_filter(
            $terms,
            fn (string $t) => str_contains($kw, ' '.$t.' ') || str_contains($kw, ' '.$t.'?'),
        );

        // Transactional wins over commercial ("best price for x" → transactional).
        if ($has(self::TRANSACTIONAL)) {
            return 'transactional';
        }
        if ($has(self::NAVIGATIONAL)) {
            return 'navigational';
        }
        if ($has(self::COMMERCIAL)) {
            return 'commercial';
        }

        $first = explode(' ', trim(mb_strtolower($keyword)))[0] ?? '';
        if (in_array($first, self::QUESTION_WORDS, true) || str_contains($kw, '?') || $has(self::INFORMATIONAL)) {
            return 'informational';
        }

        return 'other';
    }

    /** Whether the keyword reads as a question ("how", "what", … or "?"). */
    public static function isQuestion(string $keyword): bool
    {
        $kw = mb_strtolower(trim($keyword));
        if ($kw === '') {
            return false;
        }
        if (str_contains($kw, '?')) {
            return true;
        }

        return in_array(explode(' ', $kw)[0] ?? '', self::QUESTION_WORDS, true);
    }
}
