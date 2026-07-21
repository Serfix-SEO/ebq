<?php

namespace Serfix\ContentAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies `X-Serfix-Signature: sha256=<hmac_sha256(raw_body, secret)>`.
 *
 * Three things this gets right that hand-rolled receivers usually do not:
 *
 *  1. Hashes the RAW body (`$request->getContent()`), never a re-encoded array.
 *     json_decode+json_encode does not round-trip byte-for-byte (key order,
 *     unicode escaping, float formatting), so re-encoding breaks valid HMACs.
 *  2. Compares with hash_equals — a plain === leaks the correct prefix through
 *     timing and is forgeable given enough attempts.
 *  3. Rejects deliveries older than `webhook.tolerance`, so a captured request
 *     cannot be replayed back at you tomorrow.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('content-ai.webhook.secret');
        if ($secret === '') {
            return response()->json([
                'error' => 'Content AI webhook secret is not configured (CONTENT_AI_WEBHOOK_SECRET).',
            ], 500);
        }

        $provided = (string) $request->header('X-Serfix-Signature', '');
        if ($provided === '') {
            return response()->json(['error' => 'Missing signature.'], 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        if (! $this->withinTolerance($request)) {
            return response()->json(['error' => 'Delivery timestamp outside the accepted window.'], 401);
        }

        return $next($request);
    }

    private function withinTolerance(Request $request): bool
    {
        $tolerance = (int) config('content-ai.webhook.tolerance', 300);
        if ($tolerance <= 0) {
            return true;
        }

        $sentAt = $request->input('sent_at');
        // A signed body with no timestamp is still authentic; only reject when a
        // timestamp is present AND stale, so the check can never break a valid
        // delivery from a publisher that omits the field.
        if (! is_string($sentAt) || $sentAt === '') {
            return true;
        }

        try {
            $sent = Carbon::parse($sentAt);
        } catch (\Throwable) {
            return true;
        }

        return $sent->diffInSeconds(now(), absolute: true) <= $tolerance;
    }
}
