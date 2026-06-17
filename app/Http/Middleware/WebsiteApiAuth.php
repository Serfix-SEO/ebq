<?php

namespace App\Http\Middleware;

use App\Models\Website;
use App\Services\ClientActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a Sanctum bearer token whose tokenable is a Website, attaches the
 * Website to the request, and optionally enforces an ability.
 *
 * Usage in routes: ->middleware('website.api:read:insights')
 */
class WebsiteApiAuth
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['error' => 'missing_token'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($bearer);
        if (! $accessToken) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return response()->json(['error' => 'expired_token'], 401);
        }

        $tokenable = $accessToken->tokenable;
        if (! $tokenable instanceof Website) {
            return response()->json(['error' => 'invalid_tokenable'], 403);
        }

        if ($ability !== null && ! $accessToken->can($ability)) {
            return response()->json(['error' => 'insufficient_ability', 'required' => $ability], 403);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('api_website', $tokenable);
        $request->attributes->set('api_token', $accessToken);

        // Sharding: route this API request to the node(s) hosting the token's
        // website data (no-op until the website carries a node anchor).
        app(\App\Support\ShardContext::class)->forWebsite((string) $tokenable->id);
        app(ClientActivityLogger::class)->log(
            'plugin.api_request',
            userId: (string) $tokenable->user_id,
            websiteId: (string) $tokenable->id,
            provider: 'wordpress',
            meta: ['path' => $request->path(), 'ability' => $ability]
        );

        return $next($request);
    }
}
