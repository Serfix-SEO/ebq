<?php

namespace App\AiTools\Tools\Media;

use App\AiTools\AbstractAiTool;
use App\AiTools\Categories;
use App\AiTools\Contracts\AiTool;
use App\AiTools\Contracts\AiToolMeta;
use App\AiTools\Contracts\InputField;
use App\AiTools\Contracts\ToolContext;

final class SchemaSuggestions extends AbstractAiTool
{
    public function meta(): AiToolMeta
    {
        return new AiToolMeta(
            id: 'schema-suggestions',
            name: 'Schema Suggestions',
            category: Categories::MEDIA,
            description: 'Structured-data recommendations grounded in what top pages on the Serfix network are using.',
            inputs: [
                new InputField('focus_keyword', 'Focus keyword', 'text', required: true, maxLength: 200),
                new InputField('article_text', 'Article body (for grounding)', 'textarea', required: true, maxLength: 12000),
                new InputField('url', 'Page URL (optional)', 'url'),
            ],
            outputType: 'schema',
            estCredits: 8,
            surfaces: [AiTool::SURFACE_STUDIO, AiTool::SURFACE_SIDEBAR],
            contextSignals: [AiTool::SIGNAL_PAGE_AUDIT, AiTool::SIGNAL_NETWORK_INSIGHT],
        );
    }

    protected function expectsJson(): bool
    {
        return true;
    }

    protected function llmOptions(): array
    {
        return ['temperature' => 0.3, 'max_tokens' => 2500, 'timeout' => 90];
    }

    protected function buildUserPrompt(array $input, ToolContext $context): string
    {
        $kw = (string) ($input['focus_keyword'] ?? '');
        $body = mb_substr((string) ($input['article_text'] ?? ''), 0, 8000);

        $audit = '';
        if (is_array($context->pageAudit)) {
            $existing = (array) ($context->pageAudit['schema_types'] ?? []);
            if ($existing !== []) {
                $audit = "\n\nThe page already emits these schema types — extend / improve, do not duplicate:\n- " . implode("\n- ", array_filter($existing, 'is_string'));
            }
        }

        $network = '';
        if (is_array($context->networkInsight) && ($context->networkInsight['cohort_size'] ?? 0) >= 5) {
            $top = array_slice((array) ($context->networkInsight['schema_types'] ?? []), 0, 5);
            if ($top !== []) {
                $network = "\n\nAcross the Serfix network for this keyword (cohort: " . (int) $context->networkInsight['cohort_size'] . " sites), these schema types appear most often on top-3 pages:";
                foreach ($top as $t) {
                    $network .= "\n- " . ($t['type'] ?? '') . " ({$t['share_pct']}%)";
                }
                $network .= "\nPrioritise these in your recommendations.";
            }
        }

        return "Focus keyword: {$kw}\nArticle body:\n{$body}\n\n"
            . "Return ONE JSON array of recommended schemas:\n"
            . "[{ \"type\": string (e.g. \"Article\", \"FAQPage\", \"HowTo\", \"Product\"), \"json_ld\": object (full @context + @type + populated fields) }]\n"
            . "Only suggest schemas that the article body actually supports. 1–4 items. JSON-LD must be valid Schema.org."
            . $audit . $network;
    }
}
