<?php

namespace Tests\Feature\Content;

use App\Services\Content\Catalog\ProductExtractor;
use App\Support\Audit\HtmlAuditor;
use Tests\TestCase;

/**
 * Strict Product Mode: deterministic product extraction from store pages.
 * JSON-LD first (Shopify/Woo/Magento default), OG fallback; the LLM path is
 * a separate job and not exercised here.
 */
class ProductExtractionTest extends TestCase
{
    private function page(string $jsonLd = '', string $extraHead = ''): string
    {
        return '<!doctype html><html><head><title>P</title>'.$extraHead
            .($jsonLd !== '' ? '<script type="application/ld+json">'.$jsonLd.'</script>' : '')
            .'</head><body><h1>Page</h1></body></html>';
    }

    public function test_plain_product_json_ld(): void
    {
        $html = $this->page(json_encode([
            '@context' => 'https://schema.org', '@type' => 'Product',
            'name' => 'Vitamin C Serum 30ml',
            'description' => 'Brightening serum with 15% vitamin C.',
            'brand' => ['@type' => 'Brand', 'name' => 'GlowLab'],
            'sku' => 'VC-30',
            'image' => ['https://cdn.example.com/vc.jpg'],
            'offers' => [
                '@type' => 'Offer', 'price' => '24.99', 'priceCurrency' => 'USD',
                'availability' => 'https://schema.org/InStock',
            ],
        ]));

        $r = app(ProductExtractor::class)->extract($html, 'https://shop.test/products/vitamin-c');

        $this->assertNotNull($r);
        $this->assertSame('Vitamin C Serum 30ml', $r['name']);
        $this->assertSame('GlowLab', $r['brand']);
        $this->assertSame(2499, $r['price_cents']);
        $this->assertSame('USD', $r['currency']);
        $this->assertSame('in_stock', $r['availability']);
        $this->assertSame('https://cdn.example.com/vc.jpg', $r['image_url']);
        $this->assertSame('VC-30', $r['sku']);
        $this->assertSame('jsonld', $r['source']);
        $this->assertContains('vitamin', $r['terms']);
        $this->assertContains('serum', $r['terms']);
    }

    public function test_graph_wrapper_and_array_type(): void
    {
        $html = $this->page(json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'BreadcrumbList', 'itemListElement' => []],
                ['@type' => ['Product', 'IndividualProduct'], 'name' => 'Boxy Case',
                    'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => 9.5, 'priceCurrency' => 'EUR', 'availability' => 'InStock']],
            ],
        ]));

        $r = app(ProductExtractor::class)->extract($html, 'https://shop.test/products/case');

        $this->assertSame('Boxy Case', $r['name']);
        $this->assertSame(950, $r['price_cents']);
        $this->assertSame('in_stock', $r['availability']);
    }

    public function test_offers_as_list_and_out_of_stock(): void
    {
        $html = $this->page(json_encode([
            '@type' => 'Product', 'name' => 'Old Gadget',
            'offers' => [['@type' => 'Offer', 'price' => 5, 'priceCurrency' => 'GBP', 'availability' => 'https://schema.org/OutOfStock']],
        ]));

        $r = app(ProductExtractor::class)->extract($html, 'https://shop.test/p/1');

        $this->assertSame('out_of_stock', $r['availability']);
        $this->assertSame(500, $r['price_cents']);
    }

    public function test_invalid_json_ld_is_skipped_then_og_fallback(): void
    {
        $html = $this->page('{not json', '
            <meta property="og:type" content="product">
            <meta property="og:title" content="OG Only Widget">
            <meta property="og:description" content="From meta tags.">
            <meta property="product:price:amount" content="12.00">
            <meta property="product:price:currency" content="USD">
            <meta property="og:image" content="https://cdn.example.com/w.png">
        ');

        $r = app(ProductExtractor::class)->extract($html, 'https://shop.test/products/w');

        $this->assertSame('og', $r['source']);
        $this->assertSame('OG Only Widget', $r['name']);
        $this->assertSame(1200, $r['price_cents']);
    }

    public function test_non_product_page_returns_null(): void
    {
        $html = $this->page(json_encode(['@type' => 'Article', 'name' => 'Blog post']));

        $this->assertNull(app(ProductExtractor::class)->extract($html, 'https://shop.test/blog/post'));
    }

    public function test_arabic_product_names_fold_into_terms(): void
    {
        $html = $this->page(json_encode([
            '@type' => 'Product', 'name' => 'سيروم فيتامين سي أصلي',
        ]));

        $r = app(ProductExtractor::class)->extract($html, 'https://shop.test/products/vc-ar');

        $this->assertContains('سيروم', $r['terms']);
        // hamza-alef folded: أصلي → اصلي
        $this->assertContains('اصلي', $r['terms']);
    }

    public function test_structured_data_helper_filters_types(): void
    {
        $auditor = new HtmlAuditor($this->page(json_encode([
            ['@type' => 'Product', 'name' => 'A'],
            ['@type' => 'Organization', 'name' => 'B'],
        ])), 'https://x.test/');

        $nodes = $auditor->structuredData(['Product']);

        $this->assertCount(1, $nodes);
        $this->assertSame('A', $nodes[0]['name']);
    }

    public function test_protocol_relative_shopify_image_normalized(): void
    {
        $html = $this->page(json_encode([
            '@type' => 'Product', 'name' => 'CDN Thing',
            'image' => '//cdn.shopify.com/s/files/x.png',
        ]));

        $r = app(ProductExtractor::class)->extract($html, 'https://shop.test/products/c');

        $this->assertSame('https://cdn.shopify.com/s/files/x.png', $r['image_url']);
    }
}
