<?php

namespace Tests\Feature\Support;

use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

/**
 * The reply editor's whole Alpine component lives in one `x-data="{ … }"`
 * attribute. A single double quote anywhere inside it — even in a comment —
 * ends the attribute early, and Alpine then dies with a syntax error that
 * kills every toolbar button silently. That is how a client reply went out
 * with a dead link (ticket 01m0b359042x3pt20z4wb4zrbw).
 */
class SupportEditorComponentTest extends TestCase
{
    private function xData(): string
    {
        $html = view('components.support.html-editor', [
            'name' => 'body',
            'placeholder' => 'Write…',
            'attributes' => new ComponentAttributeBag([]),
        ])->render();

        $start = strpos($html, 'x-data="');
        $this->assertNotFalse($start, 'component lost its x-data attribute');
        $start += strlen('x-data="');
        $end = strpos($html, '"', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_the_x_data_attribute_is_not_truncated_by_a_stray_quote(): void
    {
        $expr = $this->xData();

        // Truncation shows up as a body that never closes its own brace.
        $this->assertStringEndsWith('}', rtrim($expr));
        $this->assertStringContainsString('link()', $expr);
        $this->assertStringContainsString('sync()', $expr);
        $this->assertSame(
            substr_count($expr, '{'),
            substr_count($expr, '}'),
            'unbalanced braces — the attribute was cut short by a double quote'
        );
    }

    public function test_link_insertion_handles_scheme_less_urls_and_empty_selections(): void
    {
        $expr = $this->xData();

        // Guards proven in a headless browser; keep them from being reverted.
        $this->assertStringContainsString("'https://' + url", $expr, 'scheme-less URLs must be upgraded');
        $this->assertStringContainsString('cloneRange', $expr, 'the selection must be captured before prompt()');
        $this->assertStringContainsString('createElement', $expr, 'the anchor is built by hand, not via execCommand');
    }
}
