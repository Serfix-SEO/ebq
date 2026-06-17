<?php

namespace App\Http\Middleware;

use App\Support\ShardContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes the request to the shard node(s) hosting the active website's data, so
 * tenant/crawl-tier models resolve to the right connection. Appended to the web
 * group (runs after the session is started). The active website is the session
 * `current_website_id` (validated elsewhere by the WebsiteSelector against the
 * user's accessible sites). No active website → no-op (default connection).
 */
class ResolveShardContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->has('current_website_id')) {
            $websiteId = $request->session()->get('current_website_id');
            if ($websiteId !== null && $websiteId !== '') {
                app(ShardContext::class)->forWebsite((string) $websiteId);
            }
        }

        return $next($request);
    }
}
