<?php

namespace App\AiTools\Tools\Research;

use App\AiTools\AbstractAiTool;
use App\AiTools\Categories;
use App\AiTools\Contracts\AiTool;
use App\AiTools\Contracts\AiToolMeta;
use App\AiTools\Contracts\InputField;
use App\AiTools\Contracts\ToolContext;

final class TopicResearch extends AbstractAiTool
{
    public function meta(): AiToolMeta
    {
        return new AiToolMeta(
            id: 'topic-research',
            name: 'Topic Research',
            category: Categories::RESEARCH,
            description: 'Map a topic into clusters and sub-topics, ranked by Serfix-network demand and your existing GSC traction.',
            inputs: [
                new InputField('topic', 'Topic / niche', 'text', required: true,
                    placeholder: 'e.g. content marketing for SaaS', maxLength: 200),
                new InputField('audience', 'Audience (optional)', 'text',
                    placeholder: 'e.g. mid-market SaaS founders', maxLength: 200),
            ],
            outputType: 'list',
            estCredits: 8,
            surfaces: [AiTool::SURFACE_STUDIO],
            contextSignals: [AiTool::SIGNAL_GSC],
            cacheTtlSeconds: 3600,
        );
    }

    protected function buildUserPrompt(array $input, ToolContext $context): string
    {
        $topic = (string) ($input['topic'] ?? '');
        $audience = (string) ($input['audience'] ?? '');

        $gsc = '';
        if (is_array($context->gscTopQueries) && $context->gscTopQueries !== []) {
            $top = array_slice($context->gscTopQueries, 0, 12);
            $gsc = "\n\nThe user's site already gets these queries from Google Search:\n";
            foreach ($top as $q) {
                $gsc .= "- {$q['query']} (clicks: {$q['clicks']}, pos: {$q['position']})\n";
            }
            $gsc .= "Prefer cluster recommendations that complement these — don't repeat what already ranks well.";
        }

        return "Topic: {$topic}\n"
            . ($audience !== '' ? "Audience: {$audience}\n" : '')
            . $gsc
            . "\n\nReturn a list of 12–18 sub-topic clusters worth covering. One sub-topic per line. No numbering, no bullets, just the cluster name on each line. Each cluster should be 2–6 words and search-friendly.";
    }
}
