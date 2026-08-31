<?php

namespace Tests\Feature\Content;

use App\Services\Content\ContentSeoScorer;
use App\Support\UnicodeText;
use Tests\TestCase;

/**
 * Arabic (and any non-Latin) articles must be measured, not punished
 * (prod 2026-08-31, namesforfreefire.com): str_word_count() counted a
 * ~2,000-word Arabic article as 39 words, and "اسماء" (typed keyword)
 * never matched "أسماء" (correct hamza spelling) — every version scored
 * ~50 and the topic regenerated 64 times.
 */
class UnicodeScoringTest extends TestCase
{
    public function test_word_count_counts_any_script(): void
    {
        $this->assertSame(7, UnicodeText::wordCount('أسماء فري فاير هي أول ما يراه'));
        $this->assertSame(4, UnicodeText::wordCount('plain latin words here'));
        $this->assertSame(0, UnicodeText::wordCount('  … —  '));
        $this->assertSame(3, UnicodeText::wordCount('mixed أسماء text'));
    }

    public function test_fold_normalizes_arabic_orthography_but_not_latin(): void
    {
        // Typed (bare alif) vs written (hamza) must meet in the middle.
        $this->assertSame(UnicodeText::fold('اسماء فري فاير'), UnicodeText::fold('أسماء فري فاير'));
        // Dotless ya, teh marbuta, tashkeel, tatweel.
        $this->assertSame(UnicodeText::fold('مستشفى'), UnicodeText::fold('مستشفي'));
        $this->assertSame(UnicodeText::fold('مدرسة'), UnicodeText::fold('مدرسه'));
        $this->assertSame(UnicodeText::fold('كَتَبَ'), UnicodeText::fold('كتب'));
        $this->assertSame(UnicodeText::fold('العـــربية'), UnicodeText::fold('العربية'));
        // Latin: plain lowercase, nothing else.
        $this->assertSame('best pubg names', UnicodeText::fold('Best PUBG Names'));
    }

    public function test_arabic_article_passes_keyword_and_word_count_checks(): void
    {
        $body = '<p>أسماء فري فاير هي أول ما يراه خصمك قبل أن تبدأ المعركة، والاسم الجيد يترك انطباعاً فورياً. '
            .str_repeat('هذا النص العربي يوضح أن عدد الكلمات يحسب بشكل صحيح الآن. ', 120).'</p>'
            .'<h2 id="a">أفضل أسماء فري فاير مزخرفة</h2><p>'.str_repeat('محتوى عربي إضافي مفيد وواضح للقارئ. ', 60).'</p>'
            .'<h2 id="b">قسم ثانٍ</h2><p>نص.</p><h2 id="c">قسم ثالث</h2><p>نص.</p><h2 id="d">قسم رابع</h2><p>نص.</p>';

        $result = app(ContentSeoScorer::class)->score(
            html: $body,
            metaTitle: 'أسماء فري فاير: دليلك الكامل لاسم مميز',
            metaDescription: 'دليل أسماء فري فاير الكامل: '.str_repeat('نصائح عملية ', 12),
            h1: 'أسماء فري فاير: دليلك الكامل',
            slug: 'free-fire-names',
            context: ['target_keyword' => 'اسماء فري فاير', 'article_length' => 1200],
        );

        $byCode = collect($result['checks'])->keyBy('code');
        foreach (['kw_in_meta_title', 'kw_in_h1', 'kw_in_first_words', 'kw_in_a_heading', 'kw_in_intro', 'word_count'] as $code) {
            $this->assertTrue((bool) $byCode[$code]['passed'],
                "check {$code} must pass for a hamza-spelled Arabic article whose keyword was typed bare-alif");
        }
    }

    public function test_latin_scoring_unchanged(): void
    {
        $result = app(ContentSeoScorer::class)->score(
            html: '<p>Best pubg names guide. '.str_repeat('Useful latin words fill this body naturally. ', 150).'</p>'
                .'<h2 id="a">Best pubg names ideas</h2><p>x</p><h2 id="b">B</h2><p>x</p><h2 id="c">C</h2><p>x</p><h2 id="d">D</h2><p>x</p>',
            metaTitle: 'Best PUBG Names: The Complete Guide Here',
            metaDescription: str_repeat('Practical naming advice for players. ', 4),
            h1: 'Best PUBG Names Guide',
            slug: 'best-pubg-names',
            context: ['target_keyword' => 'best pubg names', 'article_length' => 1200],
        );

        $byCode = collect($result['checks'])->keyBy('code');
        foreach (['kw_in_meta_title', 'kw_in_h1', 'kw_in_slug', 'word_count'] as $code) {
            $this->assertTrue((bool) $byCode[$code]['passed'], "latin regression: {$code}");
        }
    }
}
