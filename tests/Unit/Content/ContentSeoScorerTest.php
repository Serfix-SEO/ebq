<?php

namespace Tests\Unit\Content;

use App\Services\Content\ContentSeoScorer;
use Tests\TestCase;

class ContentSeoScorerTest extends TestCase
{
    private ContentSeoScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ContentSeoScorer();
    }

    /** A deliberately strong article against its context. */
    private function goodArticle(): array
    {
        $kw = 'pubg name generator';
        $body = '<p>Try our pubg name generator to build a unique in-game identity in seconds. '
            .'This opening paragraph explains exactly what the pubg name generator does and why players use it, with concrete details.</p>';
        // Each section repeats the exact focus phrase so density (occ/words)
        // clears 0.5% and it lands in every third of the body.
        $section = static fn (string $h, string $extra = '') => "<h2>{$h}</h2>"
            .'<p>A good pubg name generator gives you concrete examples and numbers like 128 symbols to keep readers engaged.'.$extra.'</p>'
            .'<p>'.str_repeat('The pubg name generator expands this section with genuinely relevant detail players search for. ', 8).'</p>';

        $html = $body
            .$section('How the pubg name generator works', ' Internal link: <a href="/symbols">symbol library</a>.')
            .$section('Choosing stylish fonts with the pubg name generator', ' See our <a href="/fonts">font styles</a> page.')
            .$section('Symbols that work in game', ' External source: <a href="https://en.wikipedia.org/wiki/Unicode">Unicode reference</a>.')
            .$section('Key takeaways for your new name')
            .'<h2>FAQ: frequently asked questions</h2><p>The pubg name generator answers common player questions with useful specifics. What matters is coverage.</p>';

        // Pad toward the 2000-word target while keeping the phrase present in the tail.
        $html .= '<h2>Advanced naming tactics</h2><p>'.str_repeat('The pubg name generator offers extra practical advice with varied phrasing. ', 40).'</p>';

        return [
            'html' => $html,
            'meta_title' => 'PUBG Name Generator: The Ultimate Guide to Stylish Names',
            'meta_description' => 'Use this pubg name generator to create stylish PUBG names with symbols and fonts. Copy unique in-game names in seconds, totally free.',
            'h1' => 'PUBG Name Generator for Stylish Players',
            'slug' => 'pubg-name-generator-stylish',
            'context' => [
                'target_keyword' => $kw,
                'secondary_keywords' => ['stylish fonts', 'symbols'],
                'site_host' => 'pubgnamegenerator.net',
                'site_urls' => ['https://pubgnamegenerator.net/symbols', 'https://pubgnamegenerator.net/fonts'],
                'existing_titles' => ['Symbol Library for BGMI'],
                'article_length' => 2000,
                'toggles' => ['key_takeaways' => true, 'faq' => true, 'external_links' => true],
                'style_issues' => [],
            ],
        ];
    }

    public function test_good_article_scores_high(): void
    {
        $a = $this->goodArticle();

        $result = $this->scorer->score($a['html'], $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertGreaterThanOrEqual(85, $result['score'], json_encode($result['issues']));
    }

    public function test_missing_keyword_everywhere_fails_hard(): void
    {
        $a = $this->goodArticle();

        $result = $this->scorer->score(
            '<p>'.str_repeat('Generic text without the phrase at all. ', 100).'</p>',
            'Some Unrelated Title Here For Testing',
            'A meta description that is long enough to pass the length check but never mentions the phrase we optimized for at all here.',
            'Totally Different Heading',
            'different-slug',
            $a['context']
        );

        $codes = array_column($result['issues'], 'code');
        $this->assertContains('kw_in_meta_title', $codes);
        $this->assertContains('kw_in_h1', $codes);
        $this->assertContains('kw_in_first_words', $codes);
        $this->assertContains('h2_count', $codes);
        $this->assertLessThan(60, $result['score']);
    }

    public function test_invalid_internal_link_is_flagged(): void
    {
        $a = $this->goodArticle();
        $a['html'] .= '<p><a href="/made-up-page-that-does-not-exist">broken internal</a></p>';

        $result = $this->scorer->score($a['html'], $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertContains('internal_links_valid', array_column($result['issues'], 'code'));
    }

    public function test_duplicate_title_flags_cannibalization(): void
    {
        $a = $this->goodArticle();
        $a['context']['existing_titles'] = ['PUBG Name Generator for Stylish Players 2026'];

        $result = $this->scorer->score($a['html'], $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertContains('title_unique', array_column($result['issues'], 'code'));
    }

    public function test_style_issues_reduce_score(): void
    {
        $a = $this->goodArticle();
        $clean = $this->scorer->score($a['html'], $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $a['context']['style_issues'] = [
            ['code' => 'banned_phrases', 'message' => 'Replace giveaway phrases.'],
        ];
        $dirty = $this->scorer->score($a['html'], $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertLessThan($clean['score'], $dirty['score']);
        $this->assertContains('style_clean', array_column($dirty['issues'], 'code'));
    }

    public function test_weights_renormalize_without_site_urls(): void
    {
        $a = $this->goodArticle();
        unset($a['context']['site_urls']);

        $result = $this->scorer->score($a['html'], $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $codes = array_column($result['checks'], 'code');
        $this->assertNotContains('internal_links', $codes);
        $this->assertGreaterThanOrEqual(85, $result['score']);
    }

    public function test_keyword_stuffing_density_flagged(): void
    {
        $a = $this->goodArticle();
        $stuffed = '<p>'.str_repeat('pubg name generator ', 120).'</p><h2>pubg name generator</h2><h2>B</h2><h2>C</h2><h2>D</h2>';

        $result = $this->scorer->score($stuffed, $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertContains('kw_density', array_column($result['issues'], 'code'));
    }

    /**
     * A long-tail 5-word keyphrase used a natural number of times (a handful of
     * mentions across ~1900 words) can never reach 0.5% as verbatim runs, yet is
     * genuinely well-optimised. Phrase-length-weighted density must NOT flag it.
     */
    public function test_long_tail_keyphrase_density_not_flagged(): void
    {
        $kw = 'how to make bgmi username memorable';
        $filler = str_repeat('This paragraph gives players concrete, practical naming advice with useful specifics. ', 30);
        $mention = "<p>Learning {$kw} comes down to a few repeatable habits. {$filler}</p>";
        // 6 natural mentions of the exact 5-word phrase, one in each third.
        $html = str_repeat($mention, 2)
            ."<h2>How to Make BGMI Username Memorable with Alliteration</h2>{$mention}"
            .str_repeat($mention, 3);

        $result = $this->scorer->score(
            $html,
            'How to Make a BGMI Username Memorable (Simple Guide)',
            'Learn how to make BGMI username memorable with simple, repeatable naming tricks that actually stick with your squad and rivals alike today.',
            'How to Make BGMI Username Memorable',
            'how-to-make-bgmi-username-memorable',
            ['target_keyword' => $kw, 'toggles' => [], 'style_issues' => []]
        );

        $this->assertNotContains('kw_density', array_column($result['issues'], 'code'), json_encode($result['issues']));
    }

    /** The keyphrase living in an H3 (not an H2) still satisfies kw_in_a_heading. */
    public function test_keyword_in_h3_satisfies_heading_check(): void
    {
        $a = $this->goodArticle();
        // Give it four H2s with the phrase only inside an H3 subheading.
        $html = '<p>Intro about the pubg name generator right here.</p>'
            .'<h2>Overview</h2><p>Body one about the pubg name generator.</p>'
            .'<h3>Using the pubg name generator step by step</h3><p>Detail.</p>'
            .'<h2>Fonts</h2><p>Body two mentions the pubg name generator again.</p>'
            .'<h2>Symbols</h2><p>Body three.</p><h2>Wrap up</h2><p>The pubg name generator recap.</p>';

        $result = $this->scorer->score($html, $a['meta_title'], $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertNotContains('kw_in_a_heading', array_column($result['issues'], 'code'));
    }

    /** A 43-char meta title is acceptable (40-60 band), not a hard fail. */
    public function test_short_but_reasonable_meta_title_passes(): void
    {
        $a = $this->goodArticle();
        $title = 'PUBG Name Generator: Stylish Names Guide'; // 40 chars, inside 40-60

        $result = $this->scorer->score($a['html'], $title, $a['meta_description'], $a['h1'], $a['slug'], $a['context']);

        $this->assertNotContains('meta_title_length', array_column($result['issues'], 'code'), 'len='.mb_strlen($title));
    }
}
