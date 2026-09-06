<?php

namespace App\Services\Content\Catalog;

use App\Support\Audit\HtmlAuditor;
use App\Support\UnicodeText;

/**
 * Turns a product page's HTML into a normalized product record (Strict
 * Product Mode). Deterministic chain, cheapest first:
 *
 *   1. JSON-LD `Product` nodes (Shopify/Woo/Magento/Salla emit these by
 *      default) — via HtmlAuditor::structuredData().
 *   2. OpenGraph product meta (og:type=product + product:price:*).
 *
 * The LLM fallback for schema-less shops lives in ExtractProductLlmJob, not
 * here — this class never spends money.
 *
 * Returns null when the page carries no recognizable product (collection
 * pages, blogs, etc. — the discovery heuristics over-collect on purpose).
 */
class ProductExtractor
{
    /**
     * @return array{name:string, description:?string, brand:?string,
     *   price_cents:?int, currency:?string, availability:string,
     *   image_url:?string, category:?string, sku:?string,
     *   canonical_url:?string, source:string, terms:list<string>}|null
     */
    public function extract(string $html, string $url): ?array
    {
        $auditor = new HtmlAuditor($html, $url);

        if (($record = $this->fromJsonLd($auditor)) !== null) {
            return $record;
        }

        return $this->fromOpenGraph($auditor, $html);
    }

    // ── JSON-LD ─────────────────────────────────────────────────────────

    private function fromJsonLd(HtmlAuditor $auditor): ?array
    {
        $nodes = $auditor->structuredData(['Product']);
        if ($nodes === []) {
            return null;
        }
        // Multiple Product nodes on one page (rare; related-product widgets):
        // prefer the one with an offer, else the first with a name.
        usort($nodes, fn ($a, $b) => (int) isset($b['offers']) <=> (int) isset($a['offers']));
        foreach ($nodes as $node) {
            $name = $this->str($node['name'] ?? null, 300);
            if ($name === null) {
                continue;
            }
            [$priceCents, $currency, $availability] = $this->offer($node['offers'] ?? null);

            return $this->record(
                name: $name,
                description: $this->str($node['description'] ?? null, 2000),
                brand: $this->brandName($node['brand'] ?? null),
                priceCents: $priceCents,
                currency: $currency,
                availability: $availability,
                imageUrl: $this->imageUrl($node['image'] ?? null),
                category: $this->str($node['category'] ?? null, 200),
                sku: $this->str($node['sku'] ?? ($node['mpn'] ?? null), 120),
                canonicalUrl: $this->str($node['url'] ?? null, 700),
                source: 'jsonld',
            );
        }

        return null;
    }

    /**
     * offers can be an Offer, an AggregateOffer, or an array of Offers.
     *
     * @return array{0:?int, 1:?string, 2:string}
     */
    private function offer(mixed $offers): array
    {
        if (is_array($offers) && array_is_list($offers)) {
            $offers = $offers[0] ?? null;
        }
        if (! is_array($offers)) {
            return [null, null, 'unknown'];
        }
        $price = $offers['price'] ?? $offers['lowPrice'] ?? null;
        $priceCents = null;
        if (is_numeric($price)) {
            $priceCents = (int) round(((float) $price) * 100);
        }
        $currency = $this->str($offers['priceCurrency'] ?? null, 8);

        // schema.org availability arrives as URL or bare token, any casing.
        $availabilityRaw = strtolower((string) ($offers['availability'] ?? ''));
        $availability = match (true) {
            str_contains($availabilityRaw, 'instock') => 'in_stock',
            str_contains($availabilityRaw, 'outofstock'),
            str_contains($availabilityRaw, 'soldout'),
            str_contains($availabilityRaw, 'discontinued') => 'out_of_stock',
            str_contains($availabilityRaw, 'preorder'),
            str_contains($availabilityRaw, 'presale') => 'preorder',
            default => 'unknown',
        };

        return [$priceCents, $currency, $availability];
    }

    private function brandName(mixed $brand): ?string
    {
        if (is_array($brand)) {
            $brand = $brand['name'] ?? null;
        }

        return $this->str($brand, 160);
    }

    private function imageUrl(mixed $image): ?string
    {
        if (is_array($image)) {
            $image = array_is_list($image) ? ($image[0] ?? null) : ($image['url'] ?? null);
        }
        if (is_array($image)) { // list of ImageObjects
            $image = $image['url'] ?? null;
        }
        $image = $this->str($image, 700);

        // Protocol-relative URLs (Shopify CDN habit) → https.
        return $image !== null && str_starts_with($image, '//') ? 'https:'.$image : $image;
    }

    // ── OpenGraph fallback ──────────────────────────────────────────────

    private function fromOpenGraph(HtmlAuditor $auditor, string $html): ?array
    {
        $meta = fn (string $property): ?string => $this->str(
            $this->metaContent($html, $property), 2000
        );

        $type = strtolower((string) $meta('og:type'));
        if (! str_contains($type, 'product')) {
            return null;
        }
        $name = $this->str($meta('og:title'), 300);
        if ($name === null) {
            return null;
        }
        $price = $meta('product:price:amount') ?? $meta('og:price:amount');

        return $this->record(
            name: $name,
            description: $meta('og:description'),
            brand: null,
            priceCents: is_numeric($price) ? (int) round(((float) $price) * 100) : null,
            currency: $this->str($meta('product:price:currency') ?? $meta('og:price:currency'), 8),
            availability: match (strtolower((string) $meta('product:availability'))) {
                'instock', 'in stock' => 'in_stock',
                'oos', 'out of stock', 'outofstock' => 'out_of_stock',
                default => 'unknown',
            },
            imageUrl: $this->str($meta('og:image'), 700),
            category: null,
            sku: null,
            canonicalUrl: $this->str($meta('og:url'), 700),
            source: 'og',
        );
    }

    /** Meta lookup by property= OR name= (some themes mix them up). */
    private function metaContent(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');
        if (preg_match('/<meta[^>]+(?:property|name)=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$quoted.'["\']/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        return null;
    }

    // ── shared ──────────────────────────────────────────────────────────

    /** Trimmed, length-capped string or null for anything blank/non-scalar. */
    private function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return array{name:string, description:?string, brand:?string,
     *   price_cents:?int, currency:?string, availability:string,
     *   image_url:?string, category:?string, sku:?string,
     *   canonical_url:?string, source:string, terms:list<string>}
     */
    public function record(
        string $name,
        ?string $description,
        ?string $brand,
        ?int $priceCents,
        ?string $currency,
        string $availability,
        ?string $imageUrl,
        ?string $category,
        ?string $sku,
        ?string $canonicalUrl,
        string $source,
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'brand' => $brand,
            'price_cents' => $priceCents,
            'currency' => $currency,
            'availability' => $availability,
            'image_url' => $imageUrl,
            'category' => $category,
            'sku' => $sku,
            'canonical_url' => $canonicalUrl,
            'source' => $source,
            'terms' => self::terms($name, $category, $brand),
        ];
    }

    /**
     * Folded match tokens (UnicodeText — Arabic-safe) precomputed at store
     * time so topic↔product matching never re-folds per comparison.
     *
     * @return list<string>
     */
    public static function terms(?string ...$parts): array
    {
        $folded = UnicodeText::fold(implode(' ', array_filter($parts, fn ($p) => (string) $p !== '')));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2)));
    }
}
