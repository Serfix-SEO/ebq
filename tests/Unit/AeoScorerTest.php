<?php

namespace Tests\Unit;

use App\Services\Content\Aeo\AeoScorer;
use PHPUnit\Framework\TestCase;

/**
 * Answer-readiness scoring. Pure function, no DB — every case here is a shape
 * of article an answer engine either can or cannot quote.
 */
class AeoScorerTest extends TestCase
{
    private function score(string $html, array $context = []): array
    {
        return (new AeoScorer)->score($html, $context['h1'] ?? 'Best oud perfume', $context);
    }

    private function codes(array $result): array
    {
        return array_column($result['issues'], 'code');
    }

    private function goodArticle(): string
    {
        return <<<'HTML'
        <p>The best oud perfume for hot weather is a light, resinous blend worn sparingly on pulse points, because heat amplifies oud's depth and a single spray carries further than three in winter.</p>
        <h2>What is oud?</h2>
        <p>Oud is the resin formed in agarwood trees after infection, distilled into an oil that smells woody and sweet. According to a 2024 industry survey, 62% of buyers describe it as their longest-lasting note.</p>
        <h2>How much should you apply in summer?</h2>
        <p>Apply one spray to each wrist and let it settle rather than rubbing, which crushes the top notes and shortens wear time.</p>
        <ul><li>One spray per pulse point</li><li>Reapply after six hours</li></ul>
        <h2>Which concentration lasts longest?</h2>
        <p>Extrait lasts longest because it carries the most oil, typically eight to twelve hours on skin.</p>
        <table><tr><th>Type</th><th>Hours</th></tr><tr><td>Extrait</td><td>8-12</td></tr></table>
        <p>Reviewed by our perfumer in March 2026.</p>
        HTML;
    }

    public function test_a_well_shaped_article_scores_high(): void
    {
        $result = $this->score($this->goodArticle(), ['author_name' => 'Sara Malik']);

        $this->assertGreaterThanOrEqual(85, $result['score'], implode(', ', $this->codes($result)));
        $this->assertSame(AeoScorer::VERSION, $result['version']);
    }

    public function test_an_article_that_buries_the_answer_is_flagged(): void
    {
        $html = '<p>Perfume has been made for centuries, and the history of scent is long and fascinating, '
            .'stretching back to ancient Egypt where resins were burned in temples, through the Arab world '
            .'where distillation was refined, and on into the French houses that shaped modern taste, which '
            .'is a story worth telling before we get anywhere near your actual question about oud in summer.</p>'
            .'<h2>Oud in summer</h2><p>It depends on the weather and your skin.</p>';

        $this->assertContains('answer_first', $this->codes($this->score($html)));
    }

    public function test_a_section_that_cannot_stand_alone_is_named(): void
    {
        $html = '<p>The best oud for summer is a light resinous blend worn on pulse points in single sprays.</p>'
            .'<h2>Concentration</h2><p>It also matters more than people think, especially in heat, because the '
            .'oil load decides how long the scent survives a hot afternoon and how far it travels from the skin.</p>'
            .'<h2>Application</h2><p>This is where most people go wrong with the amount they use, spraying four '
            .'or five times when one settled spray on each wrist would carry them through the whole day.</p>';

        $result = $this->score($html);
        $this->assertContains('self_contained_sections', $this->codes($result));

        // The fix message must name the offending sections — a scorer that
        // says "some sections" gives the reviser nothing to act on.
        $message = collect($result['issues'])->firstWhere('code', 'self_contained_sections')['message'];
        $this->assertStringContainsString('Concentration', $message);
        $this->assertStringContainsString('Application', $message);
    }

    public function test_a_question_answered_only_by_a_list_is_flagged(): void
    {
        $html = '<p>The best oud perfume for hot weather is a light resinous blend applied to pulse points once.</p>'
            .'<h2>How do you apply oud in summer?</h2>'
            .'<ul><li>One spray to each wrist, settled rather than rubbed in</li>'
            .'<li>Pulse points only, because heat carries the scent further than skin does</li>'
            .'<li>Do not rub the wrists together, which crushes the lighter top notes</li></ul>';

        // A model quoting this gets a naked list with no sentence to frame it.
        $this->assertContains('answer_after_question', $this->codes($this->score($html)));
    }

    public function test_statements_of_fact_without_a_source_are_flagged(): void
    {
        $html = str_replace('According to a 2024 industry survey, 62% of buyers describe it as their longest-lasting note.', '', $this->goodArticle());

        $this->assertContains('cited_claims', $this->codes($this->score($html)));
    }

    public function test_an_author_satisfies_the_byline_check_without_asking_the_model_for_one(): void
    {
        $html = '<p>The best oud perfume for hot weather is a light resinous blend worn on pulse points sparingly.</p>'
            .'<h2>What is oud?</h2><p>Oud is a resin from agarwood, distilled into a woody oil with real depth '
            .'that behaves differently in heat, where it opens faster and carries further than it does in winter.</p>';

        $this->assertContains('byline_or_date', $this->codes($this->score($html)));
        $this->assertNotContains('byline_or_date', $this->codes($this->score($html, ['author_name' => 'Sara Malik'])));
    }

    public function test_widgets_are_not_mistaken_for_the_opening(): void
    {
        // The takeaways box renders first but is not the article's answer —
        // the same correction ContentSeoScorer needed in July.
        $html = '<div class="key-takeaways"><ul><li>Oud is resinous</li><li>Less is more</li></ul></div>'
            .'<nav class="content-toc"><ul><li>Jump</li></ul></nav>'
            .'<p>The best oud perfume for hot weather is a light resinous blend worn on pulse points sparingly.</p>'
            .'<h2>What is oud?</h2><p>Oud is a resin from agarwood, distilled into a woody oil with real depth.</p>';

        $this->assertNotContains('answer_first', $this->codes($this->score($html)));
    }

    public function test_every_issue_carries_an_instruction_a_reviser_can_act_on(): void
    {
        $result = $this->score('<p>Short.</p>');

        $this->assertNotEmpty($result['issues']);
        foreach ($result['issues'] as $issue) {
            $this->assertGreaterThan(30, strlen($issue['message']), $issue['code'].' has no usable instruction');
            $this->assertGreaterThan(0, $issue['weight']);
        }
        // Heaviest first, so a truncated revision prompt keeps what matters.
        $weights = array_column($result['issues'], 'weight');
        $this->assertSame($weights, collect($weights)->sortDesc()->values()->all());
    }

    public function test_an_empty_article_scores_zero_rather_than_crashing(): void
    {
        $result = $this->score('');
        $this->assertSame(0, $result['score']);
    }
}
