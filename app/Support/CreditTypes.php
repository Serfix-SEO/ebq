<?php

namespace App\Support;

/**
 * EBQ Content Credits — segregated activity types written into
 * `client_activities`. Filtering by `provider = 'ebq_content_credits'`
 * isolates AI Writer usage from Serper SERP API calls, AI Block edits,
 * AI Chat, etc., so billing and per-project reporting can sum cleanly.
 *
 * Every WriterProject log entry carries `meta.writer_project_id` (the
 * external_id UUID) so per-project totals are a single SUM query.
 */
final class CreditTypes
{
    public const PROVIDER = 'ebq_content_credits';

    public const AI_WRITER_BRIEF        = 'credit_usage.ai_writer.brief';
    public const AI_WRITER_BRIEF_CHAT   = 'credit_usage.ai_writer.brief_chat';
    public const AI_WRITER_STRATEGY_H1  = 'credit_usage.ai_writer.strategy_h1';
    public const AI_WRITER_IMAGE_SEARCH = 'credit_usage.ai_writer.image_search';
    public const AI_WRITER_GENERATE     = 'credit_usage.ai_writer.generate';

    public const TYPES = [
        self::AI_WRITER_BRIEF,
        self::AI_WRITER_BRIEF_CHAT,
        self::AI_WRITER_STRATEGY_H1,
        self::AI_WRITER_IMAGE_SEARCH,
        self::AI_WRITER_GENERATE,
    ];
}
