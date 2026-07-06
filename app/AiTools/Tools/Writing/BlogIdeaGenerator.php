<?php

namespace App\AiTools\Tools\Writing;

use App\AiTools\AbstractAiTool;
use App\AiTools\Categories;
use App\AiTools\Contracts\AiTool;
use App\AiTools\Contracts\AiToolMeta;
use App\AiTools\Contracts\InputField;
use App\AiTools\Contracts\ToolContext;

final class BlogIdeaGenerator extends AbstractAiTool
{
    public function meta(): AiToolMeta
    {
        return new AiToolMeta(
            id: 'blog-idea-generator',
            name: 'Blog Post Ideas',
            category: Categories::WRITING,
            description: '10 post ideas anchored to your topical gaps and unranked GSC queries.',
            inputs: [
                new InputField('topic', 'Topic / niche', 'text', required: true, maxLength: 200),
                new InputField('audience', 'Audience (optional)', 'text', maxLength: 200),
            ],
            outputType: 'list',
            estCredits: 6,
            surfaces: [AiTool::SURFACE_STUDIO],
            contextSignals: [AiTool::SIGNAL_GSC, AiTool::SIGNAL_NETWORK_INSIGHT],
            cacheTtlSeconds: 1800,
        );
    }

    protected function buildUserPrompt(array $input, ToolContext $context): string
    {
        $topic = (string) ($input['topic'] ?? '');
        $audience = (string) ($input['audience'] ?? '');

        $gscBlock = '';
        if (is_array($context->gscClustersForKeyword) && ! empty($context->gscClustersForKeyword['related_queries'])) {
            $unranked = array_filter($context->gscClustersForKeyword['related_queries'], static fn ($q) => $q['position'] > 10);
            if ($unranked !== []) {
                $gscBlock = "\n\nUnranked queries the site already gets impressions for (HIGH-PRIORITY ideas):\n";
                foreach (array_slice($unranked, 0, 6) as $q) {
                    $gscBlock .= "- {$q['query']} (impr: {$q['impressions']})\n";
                }
            }
        }

        $networkBlock = '';
        if (is_array($context->networkInsight) && ($context->networkInsight['cohort_size'] ?? 0) >= 5) {
            $networkBlock = "\n\nAcross the Serfix network for this topic (cohort: {$context->networkInsight['cohort_size']} sites), top schema patterns: ";
            foreach (array_slice((array) ($context->networkInsight['schema_types'] ?? []), 0, 3) as $s) {
                $networkBlock .= ($s['type'] ?? '') . ' ';
            }
        }

        return "Topic: {$topic}\n"
            . ($audience !== '' ? "Audience: {$audience}\n" : '')
            . "Generate 10 specific blog post ideas. Each title should be ≤80 characters and clickable. Mix angles: how-to, listicle, opinion, comparison, case study. One per line, no numbering."
            . $gscBlock . $networkBlock;
    }
}
