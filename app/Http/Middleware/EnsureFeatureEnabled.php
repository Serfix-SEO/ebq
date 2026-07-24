<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global feature kill-switch. `feature.enabled:ai_studio` 404s the request
 * whenever `config('features.ai_studio')` is false — a hard OFF for a whole
 * product area, independent of the per-team `feature:` permission gate (which
 * a beta/disabled area's routes still carry). Config is read per-request, so
 * this stays correct under a cached route table.
 */
class EnsureFeatureEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless((bool) config("features.{$feature}", false), 404);

        return $next($request);
    }
}
