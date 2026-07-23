<?php

namespace App\Support;

/**
 * Site-type taxonomy for Content Autopilot — the ONE place that maps a
 * client's kind of website to how the whole pipeline should behave: which
 * search intents matter, what shapes buyer queries take, the default
 * competitor-guard posture, CTA framing and writing voice.
 *
 * Pure config, no I/O. A null/unknown type must always behave exactly like
 * today's pipeline (the 'other' profile), so classification failures and
 * pre-migration plans degrade to current behavior, never to an error.
 */
class ContentSiteTypeProfiles
{
    public const BLOG = 'blog';
    public const AFFILIATE = 'affiliate';
    public const BRAND = 'brand';
    public const RESELLER = 'ecommerce_reseller';
    public const LOCAL_SERVICE = 'local_service';
    public const SAAS = 'saas';
    public const TOOL = 'tool';
    public const CREATOR = 'creator';
    public const MARKETPLACE = 'marketplace';
    public const EDUCATION = 'education';
    public const B2B_SERVICES = 'b2b_services';
    public const NONPROFIT = 'nonprofit';
    public const OTHER = 'other';

    /** Guard postures (consumed by CompetitorMentionGuard policy modes). */
    public const GUARD_PROTECT = 'protect';
    public const GUARD_OFF = 'off';
    public const GUARD_BRANDS_REQUIRED = 'brands_required';
    public const GUARD_STOCKED_ONLY = 'stocked_only';

    public const TYPES = [
        self::BLOG, self::AFFILIATE, self::BRAND, self::RESELLER,
        self::LOCAL_SERVICE, self::SAAS, self::TOOL, self::CREATOR,
        self::MARKETPLACE, self::EDUCATION, self::B2B_SERVICES,
        self::NONPROFIT, self::OTHER,
    ];

    public static function isValid(?string $type): bool
    {
        return $type !== null && in_array($type, self::TYPES, true);
    }

    /** Client-facing label (English base string — pass through __() at render). */
    public static function label(string $type): string
    {
        return match ($type) {
            self::BLOG => 'Blog / publisher',
            self::AFFILIATE => 'Review / affiliate site',
            self::BRAND => 'Brand selling its own products',
            self::RESELLER => 'Online shop (resells brands)',
            self::LOCAL_SERVICE => 'Local service business',
            self::SAAS => 'Software / SaaS',
            self::TOOL => 'Free tool / generator',
            self::CREATOR => 'Creator / personal brand',
            self::MARKETPLACE => 'Marketplace / directory',
            self::EDUCATION => 'Courses / education',
            self::B2B_SERVICES => 'B2B / professional services',
            self::NONPROFIT => 'Nonprofit / charity',
            default => 'Something else',
        };
    }

    /** One-line chip sublabel (English base string — pass through __()). */
    public static function description(string $type): string
    {
        return match ($type) {
            self::BLOG => 'Articles are the product — readers and reach',
            self::AFFILIATE => 'Reviews and comparisons of other brands',
            self::BRAND => 'Physical or digital products under your own name',
            self::RESELLER => 'A store carrying third-party brands',
            self::LOCAL_SERVICE => 'Customers book or call you locally',
            self::SAAS => 'A tool people sign up for',
            self::TOOL => 'A free tool people use right in the browser',
            self::CREATOR => 'You are the brand — courses, newsletter, coaching',
            self::MARKETPLACE => 'A platform connecting buyers with many sellers or listings',
            self::EDUCATION => 'People enroll in courses or training',
            self::B2B_SERVICES => 'Clients hire your expertise',
            self::NONPROFIT => 'Awareness, signups or donations',
            default => 'None of these quite fit',
        };
    }

    /**
     * The full behavior profile for a type. Unknown/null types get the
     * 'other' profile, which is calibrated to match today's type-blind
     * pipeline (balanced intents, protect-capable guard, soft CTA).
     *
     * Keys:
     *  - intent_weights: multiplier per search intent, used by keyword
     *    selection (winnability × intent weight; volume tiebreak).
     *  - query_shapes: template strings for OfferQueryGenerator; placeholders
     *    {offer} and {audience} are substituted per sell-offering.
     *  - guard_default: posture when the client never chose (auto-enable
     *    keeps its existing "human decision wins" rule).
     *  - cta_style / voice: writer framing hints (Phase F).
     *  - article_mix: TOFU/MOFU/BOFU target ratio for ideation.
     *  - ymyl_care: force conservative claims regardless of topic.
     *  - avoid_patterns: regexes whose keyword matches get heavily
     *    down-weighted in selection — queries that belong to a DIFFERENT
     *    site type (e.g. "perfume reviews"/"comparison site" are affiliate
     *    queries; a brand ranking for them attracts readers looking for
     *    independent reviews, not the brand's own blog).
     *
     * @return array{intent_weights: array<string,float>, query_shapes: list<string>, guard_default: string, cta_style: string, voice: string, article_mix: array{tofu: float, mofu: float, bofu: float}, ymyl_care: bool, avoid_patterns: list<string>}
     */
    public static function profile(?string $type): array
    {
        $profile = self::map()[self::isValid($type) ? $type : self::OTHER];
        $profile['avoid_patterns'] ??= [];

        return $profile;
    }

    /** @return array<string, array<string, mixed>> */
    private static function map(): array
    {
        return [
            self::BLOG => [
                'intent_weights' => ['informational' => 1.0, 'commercial' => 0.3, 'transactional' => 0.1, 'navigational' => 0.0],
                'query_shapes' => [
                    'how to {offer}', 'what is {offer}', '{offer} tips for {audience}',
                    '{offer} examples', 'beginner guide to {offer}',
                ],
                'guard_default' => self::GUARD_OFF,
                'cta_style' => 'subscribe',
                'voice' => 'personal',
                'article_mix' => ['tofu' => 0.8, 'mofu' => 0.15, 'bofu' => 0.05],
                'ymyl_care' => false,
            ],
            self::AFFILIATE => [
                'intent_weights' => ['informational' => 0.6, 'commercial' => 1.0, 'transactional' => 0.4, 'navigational' => 0.1],
                'query_shapes' => [
                    'best {offer} for {audience}', '{offer} review', '{offer} vs alternatives',
                    'top {offer} compared', 'is {offer} worth it',
                ],
                'guard_default' => self::GUARD_BRANDS_REQUIRED,
                'cta_style' => 'outbound',
                'voice' => 'personal',
                'article_mix' => ['tofu' => 0.3, 'mofu' => 0.6, 'bofu' => 0.1],
                'ymyl_care' => false,
            ],
            self::BRAND => [
                'intent_weights' => ['informational' => 0.8, 'commercial' => 0.9, 'transactional' => 0.6, 'navigational' => 0.1],
                'query_shapes' => [
                    'how to choose {offer}', 'best {offer} for {audience}', '{offer} guide',
                    '{offer} as a gift', 'how to use {offer}',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'product',
                'voice' => 'brand',
                'article_mix' => ['tofu' => 0.5, 'mofu' => 0.3, 'bofu' => 0.2],
                'ymyl_care' => false,
                // Affiliate-shaped ("reviews", "vs") and local/B2B-sourcing
                // ("near me", "manufacturers") queries a brand's own blog
                // shouldn't chase — wrong searcher for a global product brand.
                'avoid_patterns' => ['/\breviews?\b/u', '/\bcomparison\b/u', '/\bvs\b/u', '/\bnear me\b/u', '/\bmanufacturers?\b/u', '/\bwholesale\b/u'],
            ],
            self::RESELLER => [
                'intent_weights' => ['informational' => 0.6, 'commercial' => 1.0, 'transactional' => 0.8, 'navigational' => 0.1],
                'query_shapes' => [
                    'best {offer} brands', '{offer} buying guide', '{offer} comparison',
                    'cheap vs premium {offer}', 'which {offer} should I buy',
                ],
                'guard_default' => self::GUARD_STOCKED_ONLY,
                'cta_style' => 'category',
                'voice' => 'brand',
                'article_mix' => ['tofu' => 0.4, 'mofu' => 0.4, 'bofu' => 0.2],
                'ymyl_care' => false,
            ],
            self::LOCAL_SERVICE => [
                'intent_weights' => ['informational' => 0.8, 'commercial' => 0.6, 'transactional' => 1.0, 'navigational' => 0.1],
                'query_shapes' => [
                    'how much does {offer} cost', 'how to choose a {offer} company',
                    'how often should you {offer}', '{offer} checklist', 'diy vs professional {offer}',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'contact',
                'voice' => 'friendly_professional',
                'article_mix' => ['tofu' => 0.5, 'mofu' => 0.3, 'bofu' => 0.2],
                'ymyl_care' => false,
            ],
            self::SAAS => [
                'intent_weights' => ['informational' => 0.9, 'commercial' => 1.0, 'transactional' => 0.7, 'navigational' => 0.2],
                'query_shapes' => [
                    'how to {offer}', 'best tools for {offer}', '{offer} software comparison',
                    '{offer} for {audience}', '{offer} workflow guide',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'trial',
                'voice' => 'professional',
                'article_mix' => ['tofu' => 0.5, 'mofu' => 0.35, 'bofu' => 0.15],
                'ymyl_care' => false,
            ],
            self::TOOL => [
                // Free browser tools (name generators, calculators, converters):
                // traffic IS the product (ads/upsell), searches are utility-
                // shaped ("free X generator", "X ideas"), and a rival tool
                // mention steers the visit away — protect, like a brand.
                'intent_weights' => ['informational' => 1.0, 'commercial' => 0.4, 'transactional' => 0.5, 'navigational' => 0.1],
                'query_shapes' => [
                    'free {offer}', '{offer} ideas', 'best {offer} for {audience}',
                    'aesthetic {offer}', 'how to use {offer}',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'trial',
                'voice' => 'personal',
                'article_mix' => ['tofu' => 0.7, 'mofu' => 0.2, 'bofu' => 0.1],
                'ymyl_care' => false,
            ],
            self::CREATOR => [
                // The person IS the product (courses, coaching, newsletter):
                // first-person voice is existential, rival-creator mentions
                // divert the audience they sell to.
                'intent_weights' => ['informational' => 1.0, 'commercial' => 0.6, 'transactional' => 0.5, 'navigational' => 0.1],
                'query_shapes' => [
                    'how to {offer}', '{offer} tips from experience', 'best {offer} for {audience}',
                    '{offer} mistakes to avoid', 'learn {offer}',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'course',
                'voice' => 'personal',
                'article_mix' => ['tofu' => 0.7, 'mofu' => 0.2, 'bofu' => 0.1],
                'ymyl_care' => false,
            ],
            self::MARKETPLACE => [
                // Two-sided platform / directory: listings are the product,
                // queries are find/compare/price-shaped, rivals are other
                // platforms.
                'intent_weights' => ['informational' => 0.7, 'commercial' => 0.9, 'transactional' => 1.0, 'navigational' => 0.2],
                'query_shapes' => [
                    'how to find {offer}', 'best {offer}', '{offer} prices',
                    'how to choose {offer}', '{offer} guide for {audience}',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'platform',
                'voice' => 'professional',
                'article_mix' => ['tofu' => 0.4, 'mofu' => 0.3, 'bofu' => 0.3],
                'ymyl_care' => false,
            ],
            self::EDUCATION => [
                // Paid courses/training (split from nonprofit 2026-07-23 —
                // the old "Nonprofit / education" chip conflated a course
                // academy with a charity).
                'intent_weights' => ['informational' => 0.9, 'commercial' => 0.9, 'transactional' => 0.7, 'navigational' => 0.1],
                'query_shapes' => [
                    'best {offer} course', 'learn {offer} online', '{offer} certification guide',
                    'is {offer} worth learning', '{offer} for beginners',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'enroll',
                'voice' => 'professional',
                'article_mix' => ['tofu' => 0.5, 'mofu' => 0.3, 'bofu' => 0.2],
                'ymyl_care' => false,
            ],
            self::B2B_SERVICES => [
                'intent_weights' => ['informational' => 1.0, 'commercial' => 0.7, 'transactional' => 0.5, 'navigational' => 0.1],
                'query_shapes' => [
                    '{offer} best practices', 'how to choose a {offer} provider',
                    '{offer} checklist for {audience}', '{offer} mistakes to avoid', '{offer} trends',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'consultation',
                'voice' => 'professional',
                'article_mix' => ['tofu' => 0.6, 'mofu' => 0.3, 'bofu' => 0.1],
                'ymyl_care' => true,
            ],
            self::NONPROFIT => [
                'intent_weights' => ['informational' => 1.0, 'commercial' => 0.2, 'transactional' => 0.3, 'navigational' => 0.0],
                'query_shapes' => [
                    'why {offer} matters', 'how to help with {offer}', '{offer} facts',
                    'how to get involved in {offer}', '{offer} for {audience}',
                ],
                'guard_default' => self::GUARD_OFF,
                'cta_style' => 'support',
                'voice' => 'warm',
                'article_mix' => ['tofu' => 0.8, 'mofu' => 0.2, 'bofu' => 0.0],
                'ymyl_care' => false,
            ],
            self::OTHER => [
                'intent_weights' => ['informational' => 0.8, 'commercial' => 0.7, 'transactional' => 0.5, 'navigational' => 0.1],
                'query_shapes' => [
                    'how to {offer}', 'best {offer} for {audience}', '{offer} guide',
                ],
                'guard_default' => self::GUARD_PROTECT,
                'cta_style' => 'soft',
                'voice' => 'neutral',
                'article_mix' => ['tofu' => 0.6, 'mofu' => 0.3, 'bofu' => 0.1],
                'ymyl_care' => false,
            ],
        ];
    }

    /** Chip options for the wizard: type => [label, description]. */
    public static function options(): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = ['label' => self::label($type), 'description' => self::description($type)];
        }

        return $out;
    }
}
