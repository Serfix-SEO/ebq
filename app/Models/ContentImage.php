<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentImage extends Model
{
    use HasFactory;
    use HasUlids;

    public const ROLE_FEATURED = 'featured';

    public const ROLE_INLINE = 'inline';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    /**
     * The filesystem disk generated images live on — one source of truth for
     * the job (write), url() (read), and the WP sideloader (fetch). 'public'
     * by default; 's3' (Hetzner Object Storage) when configured.
     */
    public static function disk(): string
    {
        return (string) config('services.content.images_disk', 'public');
    }

    /** Public URL for the stored image (works for local + S3 disks), or null. */
    public function url(): ?string
    {
        return $this->disk_path
            ? \Illuminate\Support\Facades\Storage::disk(self::disk())->url($this->disk_path)
            : null;
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ContentArticle::class, 'article_id');
    }
}
