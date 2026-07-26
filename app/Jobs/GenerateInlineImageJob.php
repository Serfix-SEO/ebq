<?php

namespace App\Jobs;

use App\Models\ContentImage;
use App\Services\Content\IdeogramClient;
use App\Services\Content\IdeogramSpendMeter;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * On-demand single inline image for the article editor. The reviewer clicks
 * "AI generate" in the WYSIWYG toolbar; this generates ONE image via Ideogram,
 * stores it on the content images disk, and flips the pre-created ContentImage
 * row to GENERATED. The editor polls (pollInlineImage) and places the figure
 * itself — this job NEVER edits article HTML.
 *
 * tries=1: images bill real money, so a retry would double-charge; any failure
 * just marks the row FAILED and the editor surfaces a friendly notice. Runs on
 * the CONTENT queue / redis-long connection, identical to GenerateContentImagesJob.
 */
class GenerateInlineImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $imageId, public string $prompt)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return $this->imageId;
    }

    public function handle(IdeogramClient $ideogram, IdeogramSpendMeter $meter): void
    {
        $image = ContentImage::query()->find($this->imageId);
        if ($image === null || $image->status !== ContentImage::STATUS_PENDING) {
            return; // gone or already handled
        }

        if (! ContentAutopilotConfig::imagesEnabled() || ! $ideogram->isConfigured() || $meter->exhausted()) {
            $image->forceFill(['status' => ContentImage::STATUS_FAILED])->save();

            return;
        }

        $speed = ContentAutopilotConfig::renderingSpeed();
        $style = ContentAutopilotConfig::styleType();

        $result = $ideogram->generate($this->prompt, [
            'aspect_ratio' => '16x9',
            'rendering_speed' => $speed,
            'style_type' => $style,
            'num_images' => 1,
        ]);
        if (! ($result['ok'] ?? false) || empty($result['images'][0]['url'])) {
            $image->forceFill(['status' => ContentImage::STATUS_FAILED])->save();

            return;
        }
        $meter->add((float) ($result['cost_usd'] ?? $ideogram->costPerImage($speed)));

        $bytes = $ideogram->download((string) $result['images'][0]['url']);
        if ($bytes === null || $bytes === '') {
            $image->forceFill(['status' => ContentImage::STATUS_FAILED])->save();

            return;
        }

        $filename = Str::ulid()->toBase32().'.png';
        $path = 'content/images/'.$filename;
        Storage::disk(ContentImage::disk())->put($path, $bytes, 'public');

        $image->forceFill([
            'disk_path' => $path,
            'filename' => $filename,
            'bytes' => strlen($bytes),
            'params' => array_merge((array) $image->params, [
                'source' => 'editor-generate',
                'rendering_speed' => $speed,
                'style_type' => $style,
                'aspect_ratio' => '16x9',
                'seed' => $result['images'][0]['seed'] ?? null,
            ]),
            'cost_usd' => (float) ($result['cost_usd'] ?? 0),
            'status' => ContentImage::STATUS_GENERATED,
        ])->save();

        Log::info('content_autopilot.inline_image_generated', ['image_id' => $image->id]);
    }

    public function failed(\Throwable $e): void
    {
        ContentImage::query()->whereKey($this->imageId)
            ->where('status', ContentImage::STATUS_PENDING)
            ->update(['status' => ContentImage::STATUS_FAILED]);
    }
}
