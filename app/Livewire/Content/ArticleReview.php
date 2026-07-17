<?php

namespace App\Livewire\Content;

use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentTopic;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Review one topic's current article: rendered preview, SEO score, the
 * plain-language improvement list, and the approve / new-draft actions.
 *
 * Tenancy: the topic must belong to a website the auth user can access —
 * resolved through accessibleWebsitesQuery(), never trusted from the URL.
 */
class ArticleReview extends Component
{
    public string $topicId;

    public function mount(string $topicId): void
    {
        $this->topicId = $topicId;
        abort_unless($this->topic() !== null, 404);
    }

    private function topic(): ?ContentTopic
    {
        $websiteIds = Auth::user()?->accessibleWebsitesQuery()->select('id');
        if ($websiteIds === null) {
            return null;
        }

        return ContentTopic::query()
            ->whereKey($this->topicId)
            ->whereIn('website_id', $websiteIds)
            ->first();
    }

    public function approve(): void
    {
        $topic = $this->topic();
        if ($topic !== null && $topic->status === ContentTopic::STATUS_READY) {
            $topic->update(['status' => ContentTopic::STATUS_SCHEDULED]);
        }
    }

    public function requestNewDraft(): void
    {
        $topic = $this->topic();
        if ($topic === null || in_array($topic->status, ContentTopic::IN_FLIGHT, true)) {
            return;
        }
        $topic->forceFill([
            'status' => ContentTopic::STATUS_APPROVED,
            'last_error' => null,
            'stage_started_at' => null,
        ])->save();
        ProduceContentArticleJob::dispatch($topic->id);
    }

    /** Plain-language labels for scorer issue codes (client-safe copy). */
    public static function issueLabel(string $code): string
    {
        return match ($code) {
            'kw_in_meta_title', 'kw_in_h1', 'kw_in_meta_description',
            'kw_in_first_words', 'kw_in_slug', 'kw_in_a_heading' => __('Keyword placement can be stronger'),
            'meta_title_length', 'meta_description_length', 'h1_length' => __('Title or description length needs a tweak'),
            'word_count' => __('Article length is off target'),
            'h2_count', 'no_orphan_h3', 'heading_not_stuffed' => __('Section structure can be improved'),
            'kw_density', 'secondary_coverage' => __('Keyword usage can be more natural'),
            'internal_links', 'internal_links_valid', 'link_density' => __('Internal linking can be improved'),
            'external_link' => __('An authoritative source link is missing'),
            'img_alt_text' => __('Image descriptions need work'),
            'sentence_length', 'paragraph_length' => __('Readability can be improved'),
            'title_unique' => __('Too similar to an existing page'),
            'key_takeaways_present', 'faq_present', 'cta_present' => __('A requested section is missing'),
            'style_clean' => __('Writing style needs polish'),
            default => __('Minor improvement available'),
        };
    }

    /** Strip anything active before rendering the preview. */
    private function safePreviewHtml(?ContentArticle $article): string
    {
        $html = (string) ($article?->html ?? '');
        $html = preg_replace('/<(script|style|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+="[^"]*"/i', '', $html) ?? $html;

        return $html;
    }

    public function render()
    {
        $topic = $this->topic();
        $article = $topic?->currentArticle;

        $issueLabels = collect((array) ($article?->seo_issues ?? []))
            ->pluck('code')
            ->map(fn ($code) => self::issueLabel((string) $code))
            ->unique()
            ->values();

        return view('livewire.content.article-review', [
            'topic' => $topic,
            'article' => $article,
            'previewHtml' => $this->safePreviewHtml($article),
            'issueLabels' => $issueLabels,
            'presentation' => $topic ? ContentCalendar::statusPresentation($topic->status) : null,
        ]);
    }
}
