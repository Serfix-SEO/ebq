<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The person an article is published by.
 *
 * AI answers cite people, not domains, and every JSON-LD author we emitted
 * before this was `Organization: <bare domain>` — the weakest claim to
 * expertise available. One author per website is enough for almost every
 * client; the schema allows more later without changing anything here.
 *
 * Nothing about this is invented: if a client does not fill the form in, no
 * Person node is emitted and the article says so honestly rather than
 * attributing itself to a name that does not exist.
 */
class ContentAuthor extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'same_as' => 'array',
            'is_default' => 'bool',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** The author to publish under, or null when the client hasn't set one. */
    public static function defaultFor(string $websiteId): ?self
    {
        return static::query()
            ->where('website_id', $websiteId)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }

    /** Profile URLs, cleaned — schema.org sameAs only accepts absolute URLs. */
    public function sameAsUrls(): array
    {
        return array_values(array_filter(
            array_map('trim', (array) ($this->same_as ?? [])),
            static fn (string $u): bool => $u !== '' && filter_var($u, FILTER_VALIDATE_URL) !== false
        ));
    }

    /**
     * The author as the WordPress plugin stores it, under `_ebq_author`.
     *
     * The plugin cannot read this table, and its own Person node is built from
     * the WordPress ACCOUNT that received the post — an admin login, on every
     * install we push to — so without this the page's structured data credited
     * someone the reader never sees. Field names are the plugin's
     * (EBQ_Meta_Fields::sanitize_author); the properties they become are the
     * same ones `ArticleSchemaGraph::person()` emits everywhere else, so one
     * author describes one entity on every destination.
     *
     * @return array<string, mixed>
     */
    public function pluginPayload(): array
    {
        $payload = ['name' => (string) $this->name];

        foreach ([
            'job_title' => $this->role,
            'description' => $this->bio,
            'knows_about' => $this->credentials,
            'image' => $this->avatar_url,
        ] as $key => $value) {
            if (filled($value)) {
                $payload[$key] = (string) $value;
            }
        }

        $sameAs = $this->sameAsUrls();
        if ($sameAs !== []) {
            $payload['same_as'] = $sameAs;
            // The first profile link doubles as the Person's own url — the page
            // an engine can follow to corroborate that the person exists.
            $payload['url'] = $sameAs[0];
        }

        return $payload;
    }

    /** One line for the byline: "Sara Malik, Head Perfumer". */
    public function byline(): string
    {
        $role = trim((string) $this->role);

        return $role !== '' ? $this->name.', '.$role : (string) $this->name;
    }
}
