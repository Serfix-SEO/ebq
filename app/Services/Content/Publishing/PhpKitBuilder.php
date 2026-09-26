<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentImage;
use App\Models\ContentIntegration;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds the downloadable publishing kit for a plain PHP / HTML website.
 *
 * The kit is the receiving half of a webhook integration, written for site
 * owners who are not developers: two folders they upload to their web root and
 * a Verify click. The static files live in resources/snippets/php/; the one
 * generated file is serfix/config.php, which carries THIS integration's
 * signing secret — so the ZIP is a credential and is only ever produced for
 * someone who can manage the website.
 *
 * Nothing about delivery changes: the kit speaks the exact signed payload
 * WebhookDriver already sends, and answers {id, url} so the live-page link,
 * Google indexing and rank tracking work for these sites too.
 */
class PhpKitBuilder
{
    public const FOLDER = 'serfix';

    public const BLOG_PATH = 'articles';

    public const RECEIVER_PATH = '/serfix/receiver.php';

    /** @return string the ZIP's bytes */
    public function build(ContentIntegration $integration): string
    {
        $secret = (string) (((array) ($integration->credentials?->toArray() ?? []))['secret'] ?? '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('The integration has no signing secret to put in the kit.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'serfix-kit-');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not create the kit archive.');
        }

        try {
            $root = resource_path('snippets/php');
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                $zip->addFile($file->getPathname(), $relative);
            }
            $zip->addFromString(self::FOLDER.'/config.php', $this->config($integration, $secret));
            // Empty folders do not survive a ZIP round-trip in every unzip tool,
            // and the receiver creates them itself — but shipping them lets the
            // customer see where images will go.
            $zip->addEmptyDir(self::FOLDER.'/media');
        } finally {
            $zip->close();
        }

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public function filename(ContentIntegration $integration): string
    {
        $domain = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $integration->website?->normalized_domain));

        return 'serfix-kit'.($domain !== '' ? '-'.$domain : '').'.zip';
    }

    /**
     * The generated settings file. Rendered with var_export, never string
     * interpolation, so no value can break out of its PHP literal.
     */
    public function config(ContentIntegration $integration, string $secret): string
    {
        $domain = (string) ($integration->website?->normalized_domain ?? '');

        $values = [
            'secret' => $secret,
            // AI Visibility: where the kit reports which AI crawlers fetched
            // the site, and who it reports as. The same secret signs it, so
            // the kit still holds exactly one credential.
            'integration_id' => (string) $integration->id,
            'ai_report_url' => route('api.v1.aeo.kit.bot-hits'),
            'site_url' => $domain !== '' ? 'https://'.$domain : '',
            'site_name' => $domain,
            'blog_path' => self::BLOG_PATH,
            // 'auto' = use /articles/my-post only once the receiver has proven
            // the host honours articles/.htaccess; true/false force it.
            'pretty_urls' => 'auto',
            'image_hosts' => $this->imageHosts(),
            'tolerance_seconds' => 300,
        ];

        return "<?php\n"
            ."/**\n"
            ." * Serfix publishing kit settings for {$this->comment($domain)}.\n"
            ." *\n"
            ." * 'secret' proves each article really came from Serfix. Keep this file\n"
            ." * private and do not copy it to another website — download a separate kit\n"
            .' * for each site. Generated '.now()->toDateString().".\n"
            ." */\n"
            .'return '.var_export($values, true).";\n";
    }

    /**
     * Where Serfix serves article images from — the only hosts the kit will
     * download from. Taken from the configured image disk, so a storage move
     * only needs a re-download, never a code change in the kit.
     *
     * @return list<string>
     */
    public function imageHosts(): array
    {
        $hosts = [];
        foreach ([
            rescue(fn () => Storage::disk(ContentImage::disk())->url('x.png'), null, false),
            (string) config('app.public_url', config('app.url')),
        ] as $url) {
            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    private function comment(string $value): string
    {
        return str_replace(['*/', "\n", "\r"], '', $value);
    }
}
