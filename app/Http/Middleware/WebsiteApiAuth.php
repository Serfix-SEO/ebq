<?php

namespace App\Http\Middleware;

use App\Models\PluginAuthFailure;
use App\Models\Website;
use App\Services\ClientActivityLogger;
use App\Support\PluginAuthHealth;
use App\Support\ShardContext;
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
        // A WordPress install whose token has died keeps calling hourly and
        // fails silently — the plugin degrades gracefully, so nobody notices
        // (pubgnamegenerator.net did it for two months). Every 401 from our own
        // plugin is remembered against the site named in its User-Agent, which
        // is the only identifier a rejected request carries.
        $bearer = $request->bearerToken();
        if (! $bearer) {
            PluginAuthHealth::recordFailure($request->userAgent());

            return response()->json(['error' => 'missing_token'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($bearer);
        if (! $accessToken) {
            PluginAuthHealth::recordFailure($request->userAgent());

            return response()->json(['error' => 'invalid_token'], 401);
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            PluginAuthHealth::recordFailure($request->userAgent());

            return response()->json(['error' => 'expired_token'], 401);
        }

        $tokenable = $accessToken->tokenable;
        if (! $tokenable instanceof Website) {
            // The token row outlived its website: deleting a site cascades its
            // tokens, but a token can survive that race and then resolve to
            // nothing. It answers 403 rather than 401, and is just as broken —
            // simcardairportbali.com did this for six days before the client
            // gave up and removed the plugin.
            PluginAuthHealth::recordFailure($request->userAgent(), PluginAuthFailure::REASON_WEBSITE_MISSING);

            return response()->json(['error' => 'invalid_tokenable'], 403);
        }

        if ($ability !== null && ! $accessToken->can($ability)) {
            return response()->json(['error' => 'insufficient_ability', 'required' => $ability], 403);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        // Authenticated, so any recorded failure run for this site is over.
        PluginAuthHealth::recordSuccess($tokenable);

        $request->attributes->set('api_website', $tokenable);
        $request->attributes->set('api_token', $accessToken);

        // Sharding: route this API request to the node(s) hosting the token's
        // website data (no-op until the website carries a node anchor).
        app(ShardContext::class)->forWebsite((string) $tokenable->id);
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
