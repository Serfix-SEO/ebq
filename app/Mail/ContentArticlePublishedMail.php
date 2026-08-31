<?php

namespace App\Mail;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentTopic;
use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;
use App\Services\Reports\ReportBrandingResolver;
use App\Support\LocaleConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "Your new article is live" — sent to the site owner once Content Autopilot
 * confirms a publication on at least one connected platform.
 *
 * Everything the mail renders is snapshotted into $facts in the constructor so
 * the queued render never re-reads the article (a later revision must not
 * change what the recipient was told was published).
 */
class ContentArticlePublishedMail extends Mailable
{
    use Queueable, SerializesModels {
        getSerializedPropertyValue as traitGetSerializedPropertyValue;
    }

    /**
     * `ReportBranding::ebqDefault()` is an in-memory, never-persisted model, so
     * the SerializesModels identifier round-trip would throw ModelNotFound on
     * the worker (the bug that silently killed default-branded growth reports —
     * see GrowthReportMail). Serialize the unsaved default whole.
     */
    protected function getSerializedPropertyValue($value, $withRelations = true)
    {
        if ($value instanceof ReportBranding && ! $value->exists) {
            return $value;
        }

        return $this->traitGetSerializedPropertyValue($value, $withRelations);
    }

    public ReportBranding $branding;

    /** @var array<string, mixed> */
    public array $facts = [];

    /**
     * @param  list<string>  $platforms  human labels of the platforms that confirmed
     */
    public function __construct(
        public User $user,
        public Website $website,
        public ContentTopic $topic,
        ContentArticle $article,
        ?string $liveUrl = null,
        array $platforms = [],
        ?ReportBranding $branding = null,
    ) {
        $this->branding = $branding
            ?? app(ReportBrandingResolver::class)->for($website->owner ?? $user, $website);

        $this->locale(LocaleConfig::resolve($user->locale));

        $this->facts = $this->snapshot($article, $liveUrl, $platforms);
    }

    /**
     * @param  list<string>  $platforms
     * @return array<string, mixed>
     */
    private function snapshot(ContentArticle $article, ?string $liveUrl, array $platforms): array
    {
        $html = (string) $article->html;
        $words = (int) ($article->word_count ?: \App\Support\UnicodeText::wordCount(strip_tags($html)));
        $featured = $article->images()
            ->where('role', ContentImage::ROLE_FEATURED)
            ->where('status', ContentImage::STATUS_GENERATED)
            ->first();

        $secondary = array_values(array_filter(array_map(
            'trim',
            (array) ($this->topic->secondary_keywords ?? []),
        )));

        return [
            'title' => (string) ($article->meta_title ?: $article->h1 ?: $this->topic->title),
            'h1' => (string) ($article->h1 ?: $this->topic->title),
            'meta_description' => (string) $article->meta_description,
            'slug' => (string) $article->slug,
            'live_url' => $liveUrl,
            'display_url' => $liveUrl ? Str::limit(preg_replace('#^https?://#', '', $liveUrl), 60) : null,
            'review_url' => route('content.review', $this->topic->id),
            'featured_image' => $featured?->url(),
            'featured_alt' => (string) ($featured?->alt_text ?? ''),
            'seo_score' => $article->seo_score !== null ? (int) $article->seo_score : null,
            'word_count' => $words,
            'read_minutes' => max(1, (int) round($words / 200)),
            'section_count' => preg_match_all('/<h2\b/i', $html),
            'image_count' => $article->images()
                ->where('status', ContentImage::STATUS_GENERATED)->count(),
            'target_keyword' => (string) ($article->focus_keyword ?: $this->topic->target_keyword),
            'secondary_keywords' => array_slice($secondary, 0, 6),
            'keyword_volume' => $this->topic->keyword_volume ? (int) $this->topic->keyword_volume : null,
            'platforms' => array_values(array_unique($platforms)),
            'published_at' => ($this->topic->published_at ?? now())->toIso8601String(),
            'indexing_submitted' => $this->website->hasGsc(),
        ];
    }

    /** Publish time in the recipient's timezone, pre-formatted for the view. */
    public function publishedAtLabel(): string
    {
        return Carbon::parse($this->facts['published_at'], $this->user->timezoneForDisplay())
            ->format('M j, Y \a\t g:i A');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your new article is live on :domain — :title', [
                'domain' => $this->website->domain,
                'title' => Str::limit($this->facts['h1'], 60),
            ]),
            replyTo: $this->branding->reply_to_email
                ? [new Address($this->branding->reply_to_email)]
                : [],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-EBQ-Content-Topic-Id' => (string) $this->topic->id,
            'X-EBQ-Content-Website-Id' => (string) $this->website->id,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-article-published',
            with: [
                'f' => $this->facts,
                'publishedAt' => $this->publishedAtLabel(),
            ],
        );
    }
}
