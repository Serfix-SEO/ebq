<?php

namespace App\AiTools\Tools\Research;

use App\AiTools\AbstractAiTool;
use App\AiTools\Categories;
use App\AiTools\Contracts\AiTool;
use App\AiTools\Contracts\AiToolMeta;
use App\AiTools\Contracts\InputField;
use App\AiTools\Contracts\ToolContext;

final class KeywordSuggestions extends AbstractAiTool
{
    public function meta(): AiToolMeta
    {
        return new AiToolMeta(
            id: 'keyword-suggestions',
            name: 'Keyword Suggestions',
            category: Categories::RESEARCH,
            description: 'Semantically related keywords ranked by your unranked-GSC opportunity.',
            inputs: [
                new InputField('keyword', 'Focus keyword', 'text', required: true, maxLength: 200),
            ],
            outputType: 'list',
            estCredits: 5,
            surfaces: [AiTool::SURFACE_STUDIO, AiTool::SURFACE_SIDEBAR],
            contextSignals: [AiTool::SIGNAL_GSC],
            cacheTtlSeconds: 3600,
        );
    }

    protected function buildUserPrompt(array $input, ToolContext $context): string
    {
        $keyword = (string) ($input['keyword'] ?? '');

        $gsc = '';
        if (is_array($context->gscClustersForKeyword) && ! empty($context->gscClustersForKeyword['related_queries'])) {
            $unranked = array_filter($context->gscClustersForKeyword['related_queries'], static fn ($q) => $q['position'] > 10);
            if ($unranked !== []) {
                $gsc = "\n\nQueries the site already gets impressions for but ranks past page 1 (PRIORITISE — these are striking-distance):\n";
                foreach (array_slice($unranked, 0, 10) as $q) {
                    $gsc .= "- {$q['query']} (pos: {$q['position']})\n";
                }
            }
        }

        // Audience language: keywords are SEARCH DATA, not copy — a real
        // query (incl. the GSC list above) must never be translated into
        // something nobody types. But a non-English audience also
        // searches in its own script, so ask for a mix instead of
        // letting the English GSC list crowd out native variations.
        $langName = \App\Services\AiWriterService::LANGUAGE_NAMES[strtolower(trim((string) ($input['language'] ?? '')))] ?? '';
        $langLine = ($langName !== '' && $langName !== 'English')
            ? "\nAudience language: {$langName}. Return a MIX — at least half the suggestions must be native {$langName} queries as that audience actually types them, alongside the strongest queries in other scripts. Any query listed above must stay verbatim, never translated."
            : '';

        return "Suggest 15 keyword variations and semantically-related queries for: {$keyword}.\n"
            . "Return one per line, no numbering or bullets, just the keyword text. Skip duplicates of the focus keyword."
            . $langLine
            . $gsc;
    }
}
