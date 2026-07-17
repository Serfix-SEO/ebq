<?php

namespace Tests\Unit\Content;

use App\Services\Content\HumanizerService;
use Tests\TestCase;

class HumanizerServiceTest extends TestCase
{
    private HumanizerService $humanizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->humanizer = new HumanizerService();
    }

    public function test_clean_strips_dashes_and_curly_quotes(): void
    {
        $dirty = "It\u{2019}s great \u{2014} really \u{201C}great\u{201D} \u{2013} you'll see -- today.";

        $clean = $this->humanizer->clean($dirty);

        $this->assertStringNotContainsString("\u{2014}", $clean);
        $this->assertStringNotContainsString("\u{2019}", $clean);
        $this->assertStringNotContainsString("\u{201C}", $clean);
        $this->assertStringNotContainsString(' -- ', $clean);
        $this->assertStringContainsString("It's great", $clean);
    }

    public function test_lint_flags_banned_phrases(): void
    {
        $html = '<p>Let us delve into this topic and leverage the ever-evolving landscape of SEO. Moreover, this comprehensive guide helps.</p>';

        $issues = $this->humanizer->lint($html);

        $codes = array_column($issues, 'code');
        $this->assertContains('banned_phrases', $codes);
        $banned = collect($issues)->firstWhere('code', 'banned_phrases');
        $this->assertStringContainsString('delve', $banned['message']);
    }

    public function test_lint_flags_surviving_dashes(): void
    {
        $issues = $this->humanizer->lint("<p>Text \u{2014} with dash. More text here to read now.</p>");

        $this->assertContains('dashes', array_column($issues, 'code'));
    }

    public function test_lint_flags_uniform_sentences(): void
    {
        // 14 sentences, every one exactly 8 words: stddev 0 => flagged.
        $sentence = 'The quick brown fox jumps over lazy dogs. ';
        $issues = $this->humanizer->lint('<p>'.str_repeat($sentence, 14).'</p>');

        $this->assertContains('uniform_sentences', array_column($issues, 'code'));
    }

    public function test_lint_passes_clean_varied_text(): void
    {
        $html = '<p>Short one. This next sentence runs quite a bit longer because it explains a concrete detail about the topic with specific numbers like 42 percent.</p>'
            .'<p>Another tiny line. Then again a much longer thought follows here, weaving in a second concrete example that a practitioner would genuinely mention when writing from experience.</p>'
            .'<p>Punchy. Real writing mixes rhythm like this and keeps the reader moving through the argument without formula or filler anywhere.</p>'
            .'<p>One more short beat. A final longer sentence closes the section by pointing at what the next part of the article will demonstrate with data.</p>';

        $issues = $this->humanizer->lint($html);

        $this->assertSame([], array_column($issues, 'code'));
    }

    public function test_lint_flags_repeated_ngrams(): void
    {
        $run = 'the best way to fix this problem quickly today';
        $filler = 'Different words expand the article body considerably now. ';
        $html = '<p>'.$run.'. '.$filler.$run.'. '.$filler.$run.'.</p>';

        $issues = $this->humanizer->lint($html);

        $this->assertContains('repeated_phrasing', array_column($issues, 'code'));
    }

    public function test_prompt_rules_include_banned_phrases_and_dash_ban(): void
    {
        $rules = $this->humanizer->promptRules();

        $this->assertStringContainsString('em dashes', $rules);
        $this->assertStringContainsString('delve', $rules);
        $this->assertStringContainsString('contractions', $rules);
    }
}
