<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Nodus mascot rollout (2026-07-23): custom error pages and the shared
 * empty/loading components render the mascot. These render views directly
 * (no DB) so they stay fast and independent of app state.
 */
class NodusUiTest extends TestCase
{
    /** Every custom error page renders, shows its code, and carries the mascot. */
    public function test_error_pages_render_with_nodus(): void
    {
        foreach ([404, 419, 429, 500, 503] as $code) {
            $html = view("errors.{$code}")->render();

            $this->assertStringContainsString('class="nodus', $html, "error {$code} missing mascot");
            $this->assertStringContainsString((string) $code, $html, "error {$code} missing its code");
            // Home + go-back affordances are always present.
            $this->assertStringContainsString('history.back()', $html);
        }
    }

    /** The error state maps to the right mascot mood. */
    public function test_error_pages_pick_expected_mascot_state(): void
    {
        $this->assertStringContainsString('nodus--confused', view('errors.404')->render());
        $this->assertStringContainsString('nodus--confused', view('errors.500')->render());
        $this->assertStringContainsString('nodus--analyzing', view('errors.429')->render());
        $this->assertStringContainsString('nodus--searching', view('errors.503')->render());
    }

    /** The composed panel component renders title + message + mascot. */
    public function test_nodus_state_component_renders(): void
    {
        $html = Blade::render(
            '<x-nodus.state state="analyzing" :title="$t" :message="$m" />',
            ['t' => 'Working on it', 'm' => 'Hang tight']
        );

        $this->assertStringContainsString('nodus--analyzing', $html);
        $this->assertStringContainsString('Working on it', $html);
        $this->assertStringContainsString('Hang tight', $html);
    }

    /** The inline chip renders mascot + slot text. */
    public function test_nodus_inline_component_renders(): void
    {
        $html = Blade::render('<x-nodus.inline state="searching">Loading data</x-nodus.inline>');

        $this->assertStringContainsString('nodus--searching', $html);
        $this->assertStringContainsString('Loading data', $html);
    }

    /** The shared empty-state + processing-panel now front the mascot (all their
     *  consumers inherit it), with prop contracts unchanged. */
    public function test_shared_components_carry_nodus(): void
    {
        $empty = Blade::render('<x-insights.empty-state :title="$t" />', ['t' => 'No data']);
        $this->assertStringContainsString('class="nodus', $empty);
        $this->assertStringContainsString('No data', $empty);

        $proc = Blade::render(
            '<x-overview.processing-panel :title="$t" :description="$d" />',
            ['t' => 'Crunching', 'd' => 'Please wait']
        );
        $this->assertStringContainsString('class="nodus', $proc);
        $this->assertStringContainsString('Crunching', $proc);
    }
}
