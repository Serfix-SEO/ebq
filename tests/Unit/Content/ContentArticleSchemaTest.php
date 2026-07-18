<?php

namespace Tests\Unit\Content;

use App\Models\ContentArticle;
use App\Services\Content\ContentArticleSchema;
use Tests\TestCase;

class ContentArticleSchemaTest extends TestCase
{
    private function article(string $html): ContentArticle
    {
        $a = new ContentArticle;
        $a->html = $html;

        return $a;
    }

    private function schema(): ContentArticleSchema
    {
        return new ContentArticleSchema;
    }

    public function test_builds_faqpage_entry_in_plugin_shape(): void
    {
        $html = '<h2 id="intro">Intro</h2><p>Hello.</p>'
            .'<h2 id="faq-frequently-asked">Frequently Asked Questions</h2>'
            .'<h3>Can I use spaces?</h3><p>Yes, spaces work fine.</p>'
            .'<h3>How often can I change it?</h3><p>Every two weeks.</p>'
            .'<h2 id="end">Wrap up</h2><p>Bye.</p>';

        $entries = $this->schema()->entries($this->article($html));

        $this->assertCount(1, $entries);
        $e = $entries[0];
        $this->assertSame('faq', $e['template']);
        $this->assertSame('FAQPage', $e['type']);
        $this->assertTrue($e['enabled']);
        $this->assertCount(2, $e['data']['questions']);
        $this->assertSame('Can I use spaces?', $e['data']['questions'][0]['question']);
        $this->assertSame('Yes, spaces work fine.', $e['data']['questions'][0]['answer']);
        // Answer stops at the next question — no bleed.
        $this->assertStringNotContainsString('Every two weeks', $e['data']['questions'][0]['answer']);
    }

    public function test_detects_faq_by_heading_text_without_id(): void
    {
        $html = '<h2>FAQ</h2><h3>Q one?</h3><p>A one.</p><h3>Q two?</h3><p>A two.</p>';
        $entries = $this->schema()->entries($this->article($html));
        $this->assertCount(1, $entries);
        $this->assertCount(2, $entries[0]['data']['questions']);
    }

    public function test_no_entry_when_no_faq_section(): void
    {
        $html = '<h2 id="a">Section</h2><p>Body.</p><h3>Not in a FAQ</h3><p>x</p>';
        $this->assertSame([], $this->schema()->entries($this->article($html)));
        $this->assertSame('', $this->schema()->json($this->article($html)));
    }

    public function test_single_question_is_not_enough(): void
    {
        // FAQPage with <2 Q&A is low value — skip.
        $html = '<h2 id="faq">FAQ</h2><h3>Only one?</h3><p>Yes.</p>';
        $this->assertSame([], $this->schema()->entries($this->article($html)));
    }

    public function test_answer_does_not_leak_into_the_next_section(): void
    {
        $html = '<h2 id="faq">Frequently asked questions</h2>'
            .'<h3>Q one?</h3><p>Answer one.</p>'
            .'<h3>Q two?</h3><p>Answer two.</p>'
            .'<h2 id="more">More content</h2><p>Unrelated body that must not appear in schema.</p>';

        $entries = $this->schema()->entries($this->article($html));
        $blob = json_encode($entries);
        $this->assertStringNotContainsString('Unrelated body', (string) $blob);
    }
}
