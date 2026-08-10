<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\LifecycleMail;
use App\Models\LifecycleEmailSend;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Lifecycle\LifecycleSegmentResolver;
use App\Support\LifecycleEmailConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * /admin/lifecycle — reporting + controls for the segment-matched lifecycle
 * emails (ebq:send-lifecycle-emails). Live eligible counts per segment,
 * sent/follow-up/converted funnel numbers, the full send log, kill switches,
 * the daily ramp cap, and a test-send that never touches the real funnel.
 */
class LifecycleController extends Controller
{
    public function index(Request $request, LifecycleSegmentResolver $resolver): View
    {
        $segment = (string) $request->query('segment', '');
        $stage = (string) $request->query('stage', '');
        $status = (string) $request->query('status', '');

        $rows = LifecycleEmailSend::query()
            ->with(['user:id,name,email', 'website:id,domain'])
            ->when(in_array($segment, ['1', '2', '3', '4'], true), fn ($q) => $q->where('segment', (int) $segment))
            ->when(in_array($stage, [LifecycleEmailSend::STAGE_INITIAL, LifecycleEmailSend::STAGE_FOLLOWUP], true), fn ($q) => $q->where('stage', $stage))
            ->when(in_array($status, [LifecycleEmailSend::STATUS_SENT, LifecycleEmailSend::STATUS_FAILED], true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        // Funnel aggregates per segment, one grouped query (portable SQL).
        $agg = LifecycleEmailSend::query()
            ->selectRaw("segment, stage, status, count(*) as c, sum(case when converted_at is not null then 1 else 0 end) as conv")
            ->groupBy('segment', 'stage', 'status')
            ->get();

        $stats = [];
        foreach ([1, 2, 3, 4] as $s) {
            $initial = $agg->first(fn ($r) => (int) $r->segment === $s && $r->stage === 'initial' && $r->status === 'sent');
            $followup = $agg->first(fn ($r) => (int) $r->segment === $s && $r->stage === 'followup' && $r->status === 'sent');
            $failed = $agg->filter(fn ($r) => (int) $r->segment === $s && $r->status === 'failed')->sum('c');

            $sent = (int) ($initial->c ?? 0);
            $converted = (int) ($initial->conv ?? 0);

            $stats[$s] = [
                'sent' => $sent,
                'followups' => (int) ($followup->c ?? 0),
                'failed' => (int) $failed,
                'converted' => $converted,
                'conversion' => $sent > 0 ? (int) round($converted * 100 / $sent) : null,
            ];
        }

        return view('admin.lifecycle.index', [
            'rows' => $rows,
            'stats' => $stats,
            'eligible' => $resolver->countsBySegment(),
            'segment' => $segment,
            'stage' => $stage,
            'status' => $status,
            'settings' => [
                'enabled' => LifecycleEmailConfig::enabled(),
                'segments' => collect([1, 2, 3, 4])->mapWithKeys(fn ($s) => [$s => LifecycleEmailConfig::segmentEnabled($s)])->all(),
                'daily_cap' => LifecycleEmailConfig::dailyCap(),
                'min_account_age_days' => LifecycleEmailConfig::minAccountAgeDays(),
                'reply_to' => LifecycleEmailConfig::REPLY_TO_ADDRESS,
            ],
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'daily_cap' => ['required', 'integer', 'between:0,5000'],
            'min_account_age_days' => ['required', 'integer', 'between:0,60'],
        ]);

        Setting::set('lifecycle.enabled', $request->boolean('enabled') ? '1' : '0');
        foreach ([1, 2, 3, 4] as $s) {
            Setting::set('lifecycle.segment.'.$s.'.enabled', $request->boolean('segment_'.$s) ? '1' : '0');
        }
        Setting::set('lifecycle.daily_cap', (string) $data['daily_cap']);
        Setting::set('lifecycle.min_account_age_days', (string) $data['min_account_age_days']);

        return redirect()->route('admin.lifecycle.index')->with('status', 'lifecycle-settings-saved');
    }

    /**
     * Preview any of the 8 emails at an arbitrary address. Renders for the
     * requesting admin (with a demo website for the CTA segments) and writes
     * NO lifecycle_email_sends row — previews never affect the real funnel.
     */
    public function testSend(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'segment' => ['required', 'integer', 'between:1,4'],
            'stage' => ['required', 'in:initial,followup'],
        ]);

        $website = null;
        if (in_array((int) $data['segment'], [3, 4], true)) {
            $website = new Website(['domain' => 'example.com']);
            $website->normalized_domain = 'example.com';
        }

        try {
            Mail::to($data['email'])->send(new LifecycleMail(
                $request->user(),
                (int) $data['segment'],
                $data['stage'],
                $website,
                'https://example.com/unsubscribe-preview',
            ));
        } catch (\Throwable $e) {
            return redirect()->route('admin.lifecycle.index')
                ->with('status', 'lifecycle-test-failed')
                ->with('lifecycle_error', $e->getMessage());
        }

        return redirect()->route('admin.lifecycle.index')->with('status', 'lifecycle-test-sent:'.$data['email']);
    }
}
