<?php

namespace App\AiTools\Tools\Media;

use App\AiTools\Categories;
use App\AiTools\Contracts\AiTool;
use App\AiTools\Contracts\AiToolMeta;
use App\AiTools\Contracts\AiToolResult;
use App\AiTools\Contracts\InputField;
use App\AiTools\Contracts\ToolContext;
use Illuminate\Support\Carbon;

/**
 * Internal-link suggestions are 100% data-driven (GSC click-weighted)
 * — no LLM call needed. We surface the candidate URLs the
 * ContextBuilder already pulled, with the user's anchor preferences
 * applied.
 *
 * This is one of Serfix's strongest moats: RankMath has no view of which
 * of your pages already get organic clicks for related queries.
 */
final class InternalLinkSuggestions implements AiTool
{
    public function meta(): AiToolMeta
    {
        return new AiToolMeta(
            id: 'internal-link-suggestions',
            name: 'Internal Link Suggestions',
            category: Categories::MEDIA,
            description: 'GSC-driven internal-link candidates with anchor text — what your existing pages already rank for.',
            inputs: [
                new InputField('focus_keyword', 'Focus keyword', 'text', required: true, maxLength: 200),
                new InputField('url', 'Current page URL (optional, will be excluded)', 'url'),
                new InputField('count', 'How many suggestions?', 'number', default: 6),
            ],
            outputType: 'links',
            estCredits: 1,
            surfaces: [AiTool::SURFACE_STUDIO, AiTool::SURFACE_SIDEBAR],
            contextSignals: [AiTool::SIGNAL_INTERNAL_LINKS],
        );
    }

    public function execute(array $input, ToolContext $context): AiToolResult
    {
        $count = max(1, min(12, (int) ($input['count'] ?? 6)));
        $candidates = is_array($context->internalLinkCandidates) ? $context->internalLinkCandidates : [];

        if ($candidates === []) {
            return AiToolResult::fail(
                error: 'no_internal_link_data',
                message: 'No GSC data yet for this topic on your site — once Google sends impressions for related queries we\'ll suggest links.',
                outputType: 'links',
            );
        }

        $value = array_map(static fn (array $c) => [
            'url' => $c['url'],
            'anchor' => $c['anchor'],
            'rationale' => sprintf("Already gets %d clicks/mo for '%s'.", $c['clicks'], $c['topic']),
        ], array_slice($candidates, 0, $count));

        return new AiToolResult(
            ok: true,
            outputType: 'links',
            value: $value,
            usage: ['prompt' => 0, 'completion' => 0, 'total' => 0],
            generatedAt: Carbon::now()->toIso8601String(),
            diagnostics: ['source' => 'gsc_token_overlap', 'candidates_considered' => count($candidates)],
        );
    }
}
