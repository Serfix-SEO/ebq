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

    /** One line for the byline: "Sara Malik, Head Perfumer". */
    public function byline(): string
    {
        $role = trim((string) $this->role);

        return $role !== '' ? $this->name.', '.$role : (string) $this->name;
    }
}
