<?php

namespace App\Http\Controllers;

use App\Models\GuestKeywordVolume;
use App\Models\GuestPageAudit;
use App\Models\GuestPageSpeed;
use App\Models\GuestRankCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Blurred teaser preview for the public tools. Renders each tool's REAL result
 * view with fabricated sample data (status=completed), overlaid with a
 * backdrop-blur + signup/login modal. No API, no DB write. After signup/login
 * the visitor returns to the tool page with ?autorun=1 and the real tool runs.
 */
class ToolPreviewController extends Controller
{
    private const TOOLS = [
        'audit' => ['view' => 'guest-audit.show', 'var' => 'audit', 'page' => '/free-audit'],
        'pagespeed' => ['view' => 'guest-pagespeed.show', 'var' => 'report', 'page' => '/pagespeed-test'],
        'rank' => ['view' => 'guest-rank-check.show', 'var' => 'report', 'page' => '/rank-tracker'],
        'volume' => ['view' => 'guest-keyword-volume.show', 'var' => 'report', 'page' => '/keyword-volume-checker'],
    ];

    public function show(Request $request, string $tool): View
    {
        abort_unless(isset(self::TOOLS[$tool]), 404);
        $cfg = self::TOOLS[$tool];

        $inputs = array_filter($request->only(['url', 'keyword', 'domain', 'country']), fn ($v) => $v !== null && $v !== '');
        $redirect = $cfg['page'].'?autorun=1'.($inputs ? '&'.http_build_query($inputs) : '');

        $model = match ($tool) {
            'audit' => $this->auditSample($inputs),
            'pagespeed' => $this->pagespeedSample($inputs),
            'rank' => $this->rankSample($inputs),
            'volume' => $this->volumeSample($inputs),
        };

        return view($cfg['view'], [
            $cfg['var'] => $model,
            'teaser' => true,
            'teaserRedirect' => $redirect,
        ]);
    }

    private function auditSample(array $in): GuestPageAudit
    {
        $url = $in['url'] ?? 'https://example.com/';
        $kw = $in['keyword'] ?? 'seo audit';

        return new GuestPageAudit([
            'token' => (string) Str::uuid(),
            'status' => 'completed',
            'url' => $url,
            'keyword' => $kw,
            'primary_keyword' => $kw,
            'primary_keyword_source' => 'input',
            'result' => [
                'metadata' => ['title' => 'Example — '.$kw, 'title_length' => 42, 'meta_description' => 'A sample meta description for the preview.', 'meta_description_length' => 138, 'canonical' => $url, 'canonical_matches' => true, 'og_tag_count' => 5, 'twitter_tag_count' => 3],
                'content' => ['word_count' => 1240, 'headings' => [['level' => 1, 'text' => 'Sample H1'], ['level' => 2, 'text' => 'Section']], 'heading_order_ok' => true, 'keyword_density' => [['term' => $kw, 'count' => 18, 'density' => 1.4], ['term' => 'tools', 'count' => 9, 'density' => 0.7]]],
                'images' => ['total' => 18, 'missing_alt' => ['/img/a.png'], 'missing_alt_count' => 1, 'modern_format_count' => 12],
                'links' => ['internal' => [], 'internal_count' => 34, 'external' => [], 'external_count' => 9, 'broken' => []],
                'technical' => ['is_https' => true, 'http_status' => 200, 'ttfb_ms' => 210, 'page_size_bytes' => 480000, 'compression' => 'gzip', 'stack' => ['WordPress']],
                'advanced' => ['has_favicon' => true, 'schema_blocks' => 2],
                'recommendations' => [
                    ['severity' => 'good', 'title' => 'HTTPS enabled', 'section' => 'Technical', 'why' => '', 'fix' => ''],
                    ['severity' => 'warning', 'title' => 'Meta description slightly long', 'section' => 'Metadata', 'why' => '', 'fix' => ''],
                    ['severity' => 'critical', 'title' => 'Missing alt text on 1 image', 'section' => 'Images', 'why' => '', 'fix' => ''],
                    ['severity' => 'info', 'title' => 'Add more internal links', 'section' => 'Links', 'why' => '', 'fix' => ''],
                ],
                'keywords' => ['available' => false],
                'core_web_vitals' => null,
                'benchmark' => null,
            ],
        ]);
    }

    private function pagespeedSample(array $in): GuestPageSpeed
    {
        $strategy = [
            'scores' => ['performance' => 82, 'accessibility' => 95, 'best_practices' => 92, 'seo' => 100],
            'metrics' => [
                ['key' => 'fcp', 'rating' => 'good', 'display' => '1.2 s', 'label' => 'First Contentful Paint'],
                ['key' => 'lcp', 'rating' => 'average', 'display' => '2.6 s', 'label' => 'Largest Contentful Paint'],
                ['key' => 'tbt', 'rating' => 'good', 'display' => '90 ms', 'label' => 'Total Blocking Time'],
                ['key' => 'cls', 'rating' => 'good', 'display' => '0.02', 'label' => 'Cumulative Layout Shift'],
            ],
            'opportunities' => [['title' => 'Serve images in next-gen formats', 'rating' => 'poor', 'savings_ms' => 900, 'description' => 'Convert PNG/JPEG to WebP.', 'resources' => []]],
            'diagnostics' => [['title' => 'Avoid enormous network payloads', 'rating' => 'average', 'display' => '1.8 MB', 'description' => '', 'resources' => []]],
            'failed_audits' => ['accessibility' => [], 'best_practices' => [], 'seo' => []],
        ];

        return new GuestPageSpeed([
            'token' => (string) Str::uuid(),
            'status' => 'completed',
            'url' => $in['url'] ?? 'https://example.com/',
            'result' => ['mobile' => $strategy, 'desktop' => $strategy],
        ]);
    }

    private function rankSample(array $in): GuestRankCheck
    {
        $kw = $in['keyword'] ?? 'seo tools';
        $domain = $in['domain'] ?? 'example.com';

        return new GuestRankCheck([
            'token' => (string) Str::uuid(),
            'status' => 'completed',
            'keyword' => $kw,
            'domain' => $domain,
            'country' => $in['country'] ?? null,
            'result' => [
                'keyword' => $kw, 'domain' => $domain, 'country' => $in['country'] ?? null,
                'position' => 7, 'found_url' => 'https://'.$domain.'/', 'depth' => 100, 'scanned' => 100,
                'results' => [
                    ['position' => 6, 'title' => 'A competitor result', 'link' => 'https://competitor-a.com/', 'domain' => 'competitor-a.com', 'snippet' => 'Sample snippet.', 'is_target' => false],
                    ['position' => 7, 'title' => 'Your page', 'link' => 'https://'.$domain.'/', 'domain' => $domain, 'snippet' => 'Your sample snippet.', 'is_target' => true],
                    ['position' => 8, 'title' => 'Another competitor', 'link' => 'https://competitor-b.com/', 'domain' => 'competitor-b.com', 'snippet' => 'Sample snippet.', 'is_target' => false],
                ],
            ],
        ]);
    }

    private function volumeSample(array $in): GuestKeywordVolume
    {
        $kw = $in['keyword'] ?? 'seo tools';
        $trend = [];
        foreach (range(1, 12) as $m) {
            $trend[] = ['month' => $m, 'value' => 4000 + $m * 220];
        }

        return new GuestKeywordVolume([
            'token' => (string) Str::uuid(),
            'status' => 'completed',
            'keyword' => $kw,
            'country' => $in['country'] ?? 'global',
            'result' => [
                'keyword' => $kw, 'country' => $in['country'] ?? 'global',
                'volume' => 8100, 'cpc' => 2.35, 'currency' => 'USD', 'competition' => 0.42,
                'trend' => $trend, 'cached' => false,
            ],
        ]);
    }
}
