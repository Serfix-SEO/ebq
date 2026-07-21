<?php

namespace Serfix\ContentAi\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Serfix\ContentAi\Models\Article;

/**
 * Copies every image referenced by an article onto the host's own disk and
 * rewrites the HTML to point at the local copy.
 *
 * Why this exists: Content AI ships HTML whose <img src> point at Serfix
 * object storage. Rendering that as-is makes every visitor to every client
 * site fetch from someone else's bucket — their bandwidth bill, and every
 * article breaks the day a file is moved or a lifecycle rule expires it.
 *
 * Failure is always soft: an image we cannot fetch keeps its original src, so
 * a flaky download degrades to a hotlinked image, never a broken one.
 */
class ImageLocalizer
{
    /**
     * @param  array<int, array<string, mixed>>  $declared  Optional images[] from the
     *                                                      payload (richer alt/role than the HTML carries). The v1 wire
     *                                                      format has no such key — we parse the HTML then.
     * @return array{html: string, stored: int}
     */
    public function localize(Article $article, string $html, array $declared = []): array
    {
        if (! config('content-ai.images.localize', true)) {
            return ['html' => $html, 'stored' => 0];
        }

        $meta = $this->indexDeclared($declared);
        $stored = 0;

        foreach ($this->extractSources($html) as $src) {
            $local = $this->store($article, $src, $meta[$src] ?? []);
            if ($local === null) {
                continue; // soft failure — keep the remote src
            }
            $html = str_replace($src, $local, $html);
            $stored++;
        }

        return ['html' => $html, 'stored' => $stored];
    }

    /**
     * @return list<string>
     */
    public function extractSources(string $html): array
    {
        if (! preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $m[1],
            fn (string $src): bool => $this->isFetchable($src)
        )));
    }

    /**
     * SSRF guard. Only https, only hosts on the allow-list — otherwise a
     * compromised or spoofed payload could make this server fetch internal
     * addresses (169.254.169.254, 10.x, localhost) on the attacker's behalf.
     */
    public function isFetchable(string $src): bool
    {
        $parts = parse_url($src);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $allowed = (array) config('content-ai.images.allowed_hosts', []);
        if ($allowed === []) {
            return true;
        }

        $host = strtolower($parts['host']);
        foreach ($allowed as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.'.$candidate))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $meta */
    private function store(Article $article, string $src, array $meta): ?string
    {
        $disk = (string) config('content-ai.images.disk', 'public');
        $hash = hash('sha256', $src);

        // Already have it (a re-delivery of the same article) — reuse, so we
        // neither re-download nor orphan the previous file.
        $existing = $article->images()->where('source_hash', $hash)->first();
        if ($existing !== null && Storage::disk($existing->disk)->exists($existing->path)) {
            return $existing->url();
        }

        try {
            $response = Http::timeout((int) config('content-ai.images.timeout', 30))->get($src);
            if ($response->failed()) {
                return null;
            }
            $bytes = $response->body();
        } catch (\Throwable $e) {
            Log::warning('content-ai.image_download_failed', ['src' => $src, 'error' => $e->getMessage()]);

            return null;
        }

        $max = (int) config('content-ai.images.max_bytes', 12 * 1024 * 1024);
        if ($bytes === '' || strlen($bytes) > $max) {
            return null;
        }

        $path = trim((string) config('content-ai.images.path', 'content-ai/images'), '/')
            .'/'.Str::random(24).'.'.$this->extension($src);

        if (! Storage::disk($disk)->put($path, $bytes, 'public')) {
            return null;
        }

        $article->images()->updateOrCreate(
            ['source_hash' => $hash],
            [
                'source_url' => $src,
                'disk' => $disk,
                'path' => $path,
                'alt_text' => $meta['alt'] ?? null,
                'role' => $meta['role'] ?? 'inline',
                'bytes' => strlen($bytes),
            ]
        );

        return Storage::disk($disk)->url($path);
    }

    private function extension(string $src): string
    {
        $ext = strtolower(pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true) ? $ext : 'png';
    }

    /**
     * @param  array<int, array<string, mixed>>  $declared
     * @return array<string, array<string, mixed>>
     */
    private function indexDeclared(array $declared): array
    {
        $out = [];
        foreach ($declared as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url !== '') {
                $out[$url] = $image;
            }
        }

        return $out;
    }
}
