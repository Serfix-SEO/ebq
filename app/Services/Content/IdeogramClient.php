<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ideogram v3 image generation for Content Autopilot.
 *
 * POST {base}/ideogram-v3/generate  (header `Api-Key`), body:
 *   prompt, aspect_ratio (e.g. 16x9|1x1|3x2), rendering_speed
 *   (FLASH|TURBO|DEFAULT|QUALITY), style_type (AUTO|GENERAL|REALISTIC|DESIGN),
 *   negative_prompt, num_images, seed.
 * Response: { data: [{ url, prompt, resolution, is_image_safe, seed }] }.
 *
 * IMPORTANT: returned URLs EXPIRE — callers must download() immediately and
 * persist bytes themselves. Never store an Ideogram URL.
 *
 * Failure philosophy mirrors LlmClient: never throw — return ok:false with an
 * error code so the pipeline degrades to "article without images".
 * Spend metering is the CALLER's job (the job knows sandbox/test context);
 * costPerImage() exposes the price table.
 */
class IdeogramClient
{
    /** USD per image by rendering speed (v3 pricing, 2026-07). */
    private const COST = [
        'FLASH' => 0.03,
        'TURBO' => 0.03,
        'DEFAULT' => 0.06,
        'QUALITY' => 0.09,
    ];

    public function isConfigured(): bool
    {
        $key = config('services.ideogram.key');

        return is_string($key) && trim($key) !== '';
    }

    public function costPerImage(string $renderingSpeed): float
    {
        return self::COST[strtoupper($renderingSpeed)] ?? self::COST['DEFAULT'];
    }

    /**
     * Generate image(s).
     *
     * @param  array{aspect_ratio?:string, rendering_speed?:string, style_type?:string,
     *               negative_prompt?:string, num_images?:int, seed?:int}  $options
     * @return array{ok:bool, images?:list<array{url:string, seed:?int, resolution:?string, is_image_safe:bool}>,
     *               cost_usd?:float, error?:string}
     */
    public function generate(string $prompt, array $options = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'ideogram_api_key_missing'];
        }

        $speed = strtoupper((string) ($options['rendering_speed'] ?? 'TURBO'));
        $numImages = max(1, min(4, (int) ($options['num_images'] ?? 1)));

        $body = array_filter([
            'prompt' => $prompt,
            'aspect_ratio' => $options['aspect_ratio'] ?? '16x9',
            'rendering_speed' => $speed,
            'style_type' => $options['style_type'] ?? 'AUTO',
            'negative_prompt' => $options['negative_prompt'] ?? null,
            'num_images' => $numImages,
            'seed' => $options['seed'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $response = Http::withHeaders(['Api-Key' => config('services.ideogram.key')])
                ->timeout((int) config('services.ideogram.timeout', 90))
                ->retry(2, 500, throw: false)
                ->post(rtrim((string) config('services.ideogram.base_url'), '/').'/ideogram-v3/generate', $body);
        } catch (\Throwable $e) {
            Log::warning('ideogram.network_error', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'ideogram_network_error'];
        }

        if (! $response->successful()) {
            Log::warning('ideogram.http_error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return ['ok' => false, 'error' => 'ideogram_http_'.$response->status()];
        }

        $images = [];
        foreach ((array) $response->json('data', []) as $row) {
            if (! is_array($row) || ! isset($row['url'])) {
                continue;
            }
            $images[] = [
                'url' => (string) $row['url'],
                'seed' => isset($row['seed']) ? (int) $row['seed'] : null,
                'resolution' => isset($row['resolution']) ? (string) $row['resolution'] : null,
                'is_image_safe' => (bool) ($row['is_image_safe'] ?? true),
            ];
        }

        if ($images === []) {
            return ['ok' => false, 'error' => 'ideogram_empty_response'];
        }

        return [
            'ok' => true,
            'images' => $images,
            'cost_usd' => round($this->costPerImage($speed) * count($images), 4),
        ];
    }

    /**
     * Download a (short-lived) generated image URL. Returns raw bytes or null.
     */
    public function download(string $url): ?string
    {
        try {
            $response = Http::timeout(60)->retry(2, 500, throw: false)->get($url);
        } catch (\Throwable $e) {
            Log::warning('ideogram.download_error', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || $response->body() === '') {
            Log::warning('ideogram.download_failed', ['status' => $response->status()]);

            return null;
        }

        return $response->body();
    }
}
