<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\AiToolRegistry;
use App\Services\AiToolRunner;
use App\Services\BrandVoiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard-side AI Studio. Mirrors the plugin's HQ AI Studio surface
 * (catalog of 47 tools + Brand Voice + per-tool execution), but resolves
 * the current Website from the session instead of a bearer token so
 * logged-in operators can drive every tool straight from ebq.io.
 *
 * Pro/feature gating is delegated to AiToolRunner and Website's effective
 * flags. The dashboard sidebar entry sits behind the team-level
 * `ai_studio` permission (so member roles can be denied access), while
 * per-tool tier gating lives server-side.
 */
class AiStudioController extends Controller
{
    public function __construct(
        private readonly AiToolRegistry $registry,
        private readonly AiToolRunner $runner,
        private readonly BrandVoiceService $brandVoice,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $website = $this->currentWebsite($request);
        if (! $website instanceof Website) {
            return redirect()
                ->route('websites.index')
                ->with('status', 'Pick a website to use the AI Studio.');
        }

        $catalog = $this->registry->catalog();
        $voice = $this->brandVoice->forWebsite($website);
        $voiceSummary = $this->brandVoice->summaryForPlugin($voice);

        return view('ai-studio.index', [
            'website' => $website,
            'catalog' => $catalog,
            'brandVoice' => $voiceSummary,
            'aiWriterEnabled' => $website->effectiveFeatureFlags()['ai_writer'] ?? false,
            'tierGate' => $website->featureGateInfo('ai_writer'),
        ]);
    }

    public function run(Request $request, string $toolId): JsonResponse
    {
        $website = $this->currentWebsite($request);
        if (! $website instanceof Website) {
            return response()->json([
                'ok' => false,
                'error' => 'no_website',
                'message' => 'Select a website before running a tool.',
            ], 422);
        }

        if (! $this->registry->has($toolId)) {
            return response()->json(['ok' => false, 'error' => 'unknown_tool'], 404);
        }

        $input = $request->all();
        if (! is_array($input)) {
            $input = [];
        }

        @set_time_limit(360);

        $result = $this->runner->run($toolId, $website, Auth::id(), $input);

        $payload = $result->toArray();
        if (! $result->ok && $result->error === 'tier_required') {
            $gate = $website->featureGateInfo('ai_writer');
            if ($gate !== null) {
                $payload['error'] = $gate['error'];
                $payload['tier'] = $gate['tier'];
                $payload['required_tier'] = $gate['required_tier'];
                $payload['feature'] = $gate['feature'];
            }
        }

        $status = $result->ok
            ? 200
            : (in_array($result->error, ['tier_required', 'feature_disabled'], true) ? 402 : 422);

        return response()->json($payload, $status);
    }

    public function brandVoiceUpdate(Request $request): JsonResponse
    {
        $website = $this->currentWebsite($request);
        if (! $website instanceof Website) {
            return response()->json([
                'ok' => false,
                'error' => 'no_website',
                'message' => 'Select a website first.',
            ], 422);
        }

        if ($gate = $website->featureGateInfo('ai_writer')) {
            return response()->json($gate + [
                'message' => 'Brand voice is available on Pro.',
            ], 402);
        }

        $data = $request->validate([
            'samples' => 'required|array|min:1|max:5',
            'samples.*' => 'required|string|min:200|max:50000',
        ]);

        @set_time_limit(180);

        $profile = $this->brandVoice->extract($website, $data['samples']);
        if (! $profile) {
            return response()->json([
                'ok' => false,
                'error' => 'extraction_failed',
                'message' => 'We could not extract a voice from those samples. Try longer or more representative posts.',
            ], 422);
        }

        return response()->json($this->brandVoice->summaryForPlugin($profile));
    }

    public function brandVoiceDestroy(Request $request): JsonResponse
    {
        $website = $this->currentWebsite($request);
        if (! $website instanceof Website) {
            return response()->json([
                'ok' => false,
                'error' => 'no_website',
            ], 422);
        }

        $this->brandVoice->clear($website);
        return response()->json(['ok' => true]);
    }

    private function currentWebsite(Request $request): ?Website
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $id = session('current_website_id');
        if (($id === null || $id === '')) {
            return null;
        }
        if (! $user->canViewWebsiteId($id)) {
            return null;
        }
        return Website::find($id);
    }
}
