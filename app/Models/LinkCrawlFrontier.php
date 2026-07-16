<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A URL in the Tier-1.5 link-crawler work-list (see the
 * link_crawl_frontier migration). Status flow:
 *   pending → done   (fetched, links recorded, recrawl scheduled via next_at)
 *          → failed  (max attempts exhausted)
 *          → blocked (robots.txt disallows)
 */
class LinkCrawlFrontier extends Model
{
    protected $table = 'link_crawl_frontier';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_at' => 'datetime'];
    }

    public static function hashFor(string $url): string
    {
        return sha1($url, true);
    }
}
