<?php

namespace Tests\Feature\Content;

use App\Support\ContentImageGuardrails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client's hero image carried a COMPETITOR's clinic name ("Al Noor Medical
 * Center") because we asked the image model for a text overlay on a real-world
 * scene and never told it what not to draw. These pin the fix.
 */
class ImageBrandGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_negatives_block_text_and_invented_business_names(): void
    {
        $n = ContentImageGuardrails::NEGATIVE_PROMPT;

        foreach (['business names', 'clinic names', 'signage', 'logos', 'watermarks', 'text', 'letters'] as $term) {
            $this->assertStringContainsString($term, $n, "'{$term}' must be a negative for pipeline images");
        }
    }

    public function test_client_named_competitors_are_added_as_negatives(): void
    {
        $n = ContentImageGuardrails::forCompetitors([
            'https://www.alnoormedical.ae/about',
            'aster.ae',
            '',              // ignored
            'ab.com',        // label too short to be a useful negative
        ]);

        $this->assertStringContainsString('alnoormedical', $n);
        $this->assertStringContainsString('aster', $n);
        $this->assertStringNotContainsString(', ab,', $n);
        // The blanket rules survive alongside the specific ones.
        $this->assertStringContainsString('signage', $n);
    }

    public function test_manual_regeneration_keeps_the_clients_own_intent(): void
    {
        // The TMC client typed "change the name of the clinic to TMC General
        // clinic" trying to fix this bug themselves. A blanket no-text rule on
        // that path would fight them.
        $n = ContentImageGuardrails::forCompetitors(
            ['alnoormedical.ae'],
            ContentImageGuardrails::MANUAL_NEGATIVE_PROMPT,
        );

        $this->assertStringContainsString('alnoormedical', $n, 'competitors stay blocked even when the client directs the image');
        $this->assertStringContainsString('logos', $n);
        $this->assertStringNotContainsString('business names', $n);
        $this->assertStringNotContainsString('letters', $n);
    }

    public function test_no_competitors_configured_still_yields_the_blanket_rules(): void
    {
        $this->assertSame(ContentImageGuardrails::NEGATIVE_PROMPT, ContentImageGuardrails::forCompetitors([]));
    }

    public function test_the_art_director_is_never_allowed_to_request_text(): void
    {
        // Guards the prompt contract itself: the old wording explicitly let the
        // hero image carry "a bold text overlay", which is what invited the
        // model to invent signage.
        $source = file_get_contents(app_path('Jobs/GenerateContentImagesJob.php'));

        $this->assertStringNotContainsString('text overlay if it suits', $source);
        $this->assertStringContainsString('NEVER ask for text', $source);
        $this->assertStringContainsString('Never name any business', $source);
    }

    public function test_every_generation_call_passes_a_negative_prompt(): void
    {
        foreach (['Jobs/GenerateContentImagesJob.php', 'Jobs/GenerateInlineImageJob.php'] as $file) {
            $source = file_get_contents(app_path($file));
            $this->assertStringContainsString(
                "'negative_prompt' => \$negativePrompt",
                $source,
                $file.' must pass negative_prompt — IdeogramClient supports it and silence is what let a competitor through',
            );
        }
    }
}
