<?php

namespace App\Livewire\Admin;

use App\Models\Proxy;
use App\Services\Crawler\ProxyPool;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Admin → Proxies. Manage the crawler's anti-blocking proxy pool: add/import
 * proxies (any common format), toggle/delete, and test one against ipify to see
 * its exit IP. The pool also merges proxylist.txt at runtime; "Import from file"
 * pulls those into the DB so the worker box reads them over the shared database.
 *
 * "Retest all" (client-driven, see proxy-manager.blade.php) calls `test()` with
 * `deleteOnFail = true` for every row — a deliberate bulk sweep, so a failure
 * there deletes the proxy on the spot instead of just decaying its fail_count.
 * This is intentionally different from the background `ebq:proxy-list-refresh`
 * job, which never deletes/deactivates on a failed synthetic check.
 */
class ProxyManager extends Component
{
    public string $bulkInput = '';

    public ?string $notice = null;

    /** Per-proxy test results: [proxyId => 'ok: 1.2.3.4' | 'failed: ...']. */
    public array $testResults = [];

    public function mount(): void
    {
        abort_unless((bool) Auth::user()?->is_admin, 403);
    }

    private function pool(): ProxyPool
    {
        return app(ProxyPool::class);
    }

    public function addProxies(): void
    {
        abort_unless((bool) Auth::user()?->is_admin, 403);

        $pool = $this->pool();
        $added = $skipped = 0;
        foreach (preg_split('/[\r\n]+/', $this->bulkInput) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $url = $pool->normalise($line);
            if ($url === null) {
                $skipped++;

                continue;
            }
            Proxy::updateOrCreate(
                ['url_hash' => Proxy::hashUrl($url)],
                ['url' => $url, 'label' => 'admin', 'active' => true],
            );
            $added++;
        }
        $this->reset('bulkInput');
        Cache::forget('crawler:proxypool:urls');
        $this->notice = "Added/updated {$added} proxies".($skipped ? ", skipped {$skipped} unparseable line(s)." : '.');
    }

    public function toggle(string $id): void
    {
        abort_unless((bool) Auth::user()?->is_admin, 403);
        $p = Proxy::find($id);
        if ($p) {
            $p->update(['active' => ! $p->active, 'fail_count' => 0]);
            Cache::forget('crawler:proxypool:urls');
        }
    }

    public function delete(string $id): void
    {
        abort_unless((bool) Auth::user()?->is_admin, 403);
        Proxy::where('id', $id)->delete();
        Cache::forget('crawler:proxypool:urls');
        $this->notice = 'Proxy deleted.';
    }

    /** Manual trigger for `ebq:proxy-list-refresh` — the scheduled auto-import is OFF by
     *  default (`CRAWLER_PROXY_AUTO_IMPORT`); this queues a one-off run on demand. */
    public function importNow(): void
    {
        abort_unless((bool) Auth::user()?->is_admin, 403);
        \App\Jobs\RunProxyListRefreshJob::dispatch();
        $this->notice = 'Import queued — refresh this page in a bit to see new proxies.';
    }

    /**
     * @param  bool  $deleteOnFail  Historical name — since 2026-08-20 NO automatic
     *                               path deletes a proxy (paid credentials; a
     *                               transient hiccup must never destroy them).
     *                               "Retest all" now just refreshes health counters;
     *                               removing a proxy is the explicit delete button only.
     */
    public function test(string $id, bool $deleteOnFail = false): void
    {
        abort_unless((bool) Auth::user()?->is_admin, 403);
        $p = Proxy::find($id);
        if (! $p) {
            return;
        }
        $ok = false;
        try {
            $ip = trim((string) Http::timeout(15)->withOptions(['proxy' => $p->url])->get('https://api.ipify.org')->body());
            $ok = (bool) filter_var($ip, FILTER_VALIDATE_IP);
            $this->testResults[$id] = $ok ? 'ok: exit IP '.$ip : 'unexpected: '.mb_substr($ip, 0, 40);
        } catch (\Throwable $e) {
            $this->testResults[$id] = 'failed: '.mb_substr($e->getMessage(), 0, 60);
        }

        if ($ok) {
            $p->update(['last_ok_at' => now(), 'last_used_at' => now(), 'fail_count' => 0]);
        } else {
            $p->increment('fail_count');
        }
        Cache::forget('crawler:proxypool:urls');
    }

    public function render()
    {
        return view('livewire.admin.proxy-manager', [
            'proxies' => Proxy::orderByDesc('active')->orderBy('id')->get(),
            'poolEnabled' => $this->pool()->enabled(),
            'poolMode' => config('crawler.proxy.mode'),
            'activeCount' => Proxy::where('active', true)->count(),
            'autoImportEnabled' => (bool) config('crawler.proxy.auto_import'),
        ]);
    }
}
