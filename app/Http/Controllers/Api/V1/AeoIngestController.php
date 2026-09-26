<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentAeoBotHit;
use App\Models\ContentIntegration;
use App\Models\Website;
use App\Support\Aeo\AiAgents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Where a client's own website tells us which AI crawlers came to visit.
 *
 * Two doors, because the two reporters authenticate differently:
 *
 *   - the WordPress plugin holds a Sanctum token whose tokenable is the
 *     Website, so it posts through the existing api/v1 plugin group and
 *     {@see botHits} just reads the resolved website;
 *   - the PHP kit has no token — it only ever held the HMAC signing secret we
 *     minted into its config.php — so {@see kitBotHits} verifies a signature
 *     over the raw body against that integration's secret, exactly as the
 *     kit's own receiver.php verifies ours.
 *
 * Both are idempotent: a reporter that cannot confirm delivery keeps its
 * buffer and sends the same day again, so ingest upserts on
 * (website, bot, day) and takes the larger count rather than adding.
 */
class AeoIngestController extends Controller
{
    /** Guard against a runaway reporter flooding us with one giant batch. */
    private const MAX_ROWS = 500;

    /**
     * POST /api/v1/aeo/bot-hits  (WordPress plugin, Sanctum website token)
     * body: { days: [{ date: "2026-09-26", bot: "GPTBot", hits: 42, pages: 12, sample_path: "/x" }] }
     */
    public function botHits(Request $request): JsonResponse
    {
        $website = $request->attributes->get('api_website');
        abort_unless($website instanceof Website, 500, 'Website context missing');

        return $this->store($website, $request, ContentAeoBotHit::REPORTER_PLUGIN);
    }

    /**
     * POST /api/v1/aeo/kit/bot-hits  (PHP kit, HMAC-signed)
     * headers: X-Serfix-Kit: <integration id>, X-Serfix-Signature: sha256=<hmac>
     */
    public function kitBotHits(Request $request): JsonResponse
    {
        $integrationId = (string) $request->header('X-Serfix-Kit', '');
        $signature = (string) $request->header('X-Serfix-Signature', '');
        if ($integrationId === '' || $signature === '') {
            return response()->json(['ok' => false, 'error' => 'Missing kit headers.'], 401);
        }

        $integration = ContentIntegration::query()
            ->where('platform', ContentIntegration::PLATFORM_WEBHOOK)
            ->whereKey($integrationId)
            ->first();
        $secret = (string) (((array) ($integration?->credentials?->toArray() ?? []))['secret'] ?? '');
        if ($integration === null || strlen($secret) < 32) {
            // Same answer as a bad signature: a caller must not be able to
            // probe which integration ids exist.
            return response()->json(['ok' => false, 'error' => 'Invalid signature.'], 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['ok' => false, 'error' => 'Invalid signature.'], 401);
        }

        $website = $integration->website;
        if ($website === null) {
            return response()->json(['ok' => false, 'error' => 'Invalid signature.'], 401);
        }

        return $this->store($website, $request, ContentAeoBotHit::REPORTER_KIT);
    }

    private function store(Website $website, Request $request, string $reporter): JsonResponse
    {
        $data = $request->validate([
            'days' => 'required|array|max:'.self::MAX_ROWS,
            'days.*.date' => 'required|date',
            'days.*.bot' => 'required|string|max:120',
            'days.*.hits' => 'required|integer|min:1|max:1000000',
            'days.*.pages' => 'nullable|integer|min:0|max:1000000',
            'days.*.sample_path' => 'nullable|string|max:600',
        ]);

        $stored = 0;
        $unknown = [];
        $floor = now()->subDays(90)->startOfDay();

        foreach ($data['days'] as $row) {
            // The reporter sends whatever the User-Agent said; we decide which
            // agent that is, so the bot vocabulary lives in one place here and
            // an old plugin release can never write an id we do not know.
            $bot = AiAgents::matchUserAgent((string) $row['bot']);
            if ($bot === null) {
                $unknown[] = Str::limit((string) $row['bot'], 60);

                continue;
            }

            try {
                $day = Carbon::parse((string) $row['date'])->startOfDay();
            } catch (\Throwable) {
                continue;
            }
            // A clock-skewed or mischievous reporter must not be able to write
            // future days into a history chart, or backfill a year of it.
            if ($day->isFuture() || $day->lt($floor)) {
                continue;
            }

            $hits = (int) $row['hits'];
            $pages = (int) ($row['pages'] ?? 0);
            $existing = ContentAeoBotHit::query()
                ->where('website_id', $website->id)
                ->where('bot', $bot)
                ->whereDate('hit_on', $day->toDateString())
                ->first();

            if ($existing === null) {
                ContentAeoBotHit::create([
                    'website_id' => $website->id,
                    'bot' => $bot,
                    'hit_on' => $day->toDateString(),
                    'hits' => $hits,
                    'pages' => $pages,
                    'sample_path' => $this->path($row['sample_path'] ?? null),
                    'reporter' => $reporter,
                ]);
            } else {
                // Re-send of a day we already have: the reporter's buffer is
                // cumulative, so the larger number is the true one. Adding
                // would double-count every retry.
                $existing->forceFill([
                    'hits' => max($existing->hits, $hits),
                    'pages' => max($existing->pages, $pages),
                    'sample_path' => $this->path($row['sample_path'] ?? null) ?: $existing->sample_path,
                    'reporter' => $reporter,
                ])->save();
            }
            $stored++;
        }

        if ($unknown !== []) {
            // Not an error: new AI crawlers appear constantly, and the ones we
            // do not know yet are exactly what should drive the next update to
            // AiAgents. Worth a log line, never a failed request.
            Log::info('aeo.unknown_agent_reported', [
                'website_id' => $website->id,
                'agents' => array_slice(array_unique($unknown), 0, 10),
            ]);
        }

        return response()->json(['ok' => true, 'stored' => $stored]);
    }

    private function path(?string $path): ?string
    {
        $path = trim((string) $path);

        return $path === '' ? null : Str::limit($path, 590, '');
    }
}
