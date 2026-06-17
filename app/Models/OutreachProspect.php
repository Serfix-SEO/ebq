<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * @property string $id
 * @property string $website_id
 * @property string $referring_domain
 * @property int|null $domain_authority
 * @property list<string>|null $linked_to_competitors
 * @property list<string>|null $anchor_examples
 * @property string $status
 * @property string|null $notes
 * @property array<string, mixed>|null $latest_draft
 * @property \Illuminate\Support\Carbon|null $first_seen_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $contacted_at
 */
class OutreachProspect extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    /**
     * Workflow states. The full kanban: new → drafted → contacted →
     * replied → converted (success) OR declined (no thanks) OR snoozed
     * (revisit later). Status is the single field the user updates from
     * the HQ tab; everything else is auto-computed.
     */
    public const STATUS_NEW       = 'new';
    public const STATUS_DRAFTED   = 'drafted';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_REPLIED   = 'replied';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_SNOOZED   = 'snoozed';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_DRAFTED,
        self::STATUS_CONTACTED,
        self::STATUS_REPLIED,
        self::STATUS_CONVERTED,
        self::STATUS_DECLINED,
        self::STATUS_SNOOZED,
    ];

    protected $fillable = [
        'website_id',
        'referring_domain',
        'domain_authority',
        'linked_to_competitors',
        'anchor_examples',
        'status',
        'notes',
        'latest_draft',
        'first_seen_at',
        'last_seen_at',
        'contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_to_competitors' => 'array',
            'anchor_examples' => 'array',
            'latest_draft' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'contacted_at' => 'datetime',
            'domain_authority' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
