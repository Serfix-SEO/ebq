<?php

namespace App\Http\Controllers;

use App\Models\AiWriterPrompt;
use App\Models\Website;
use App\Models\WriterProject;
use App\Services\AiWriter\CustomPromptGuard;
use App\Services\WriterProjectService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Dashboard-side AI Writer wizard. The multi-step blog-post lifecycle
 * (topic → brief → strategy → images → summary → review) lives only in
 * the WP plugin's React bundle; this controller re-exposes the same
 * WriterProjectService flow to the ebq.io dashboard, resolving the
 * current Website from the session instead of a bearer token (mirroring
 * AiStudioController). Every state mutation flows through
 * WriterProjectService so credit accounting + shape normalization stay
 * in one place — exactly like Api\V1\WriterProjectController.
 *
 * Two WP-runtime steps have no dashboard analogue and are handled in the
 * Blade view, not here: media-library uploads (wp.media) and the
 * save-to-WordPress draft (wp/v2/posts). The generated HTML is persisted
 * on the project regardless, so the dashboard offers preview / copy /
 * download / mark-complete instead.
 */
class AiStudioWriterController extends Controller
{
    public function __construct(
        private readonly WriterProjectService $service,
        private readonly CustomPromptGuard $promptGuard,
    ) {
    }

    /**
     * GET /ai-studio/blog-post-wizard — the single-page wizard shell.
     */
    public function page(Request $request): View|RedirectResponse
    {
        $website = $this->currentWebsite($request);
        if (! $website instanceof Website) {
            return redirect()
                ->route('websites.index')
                ->with('status', 'Pick a website to use the Blog Post Wizard.');
        }

        return view('ai-studio.wizard', [
            'website' => $website,
            'aiWriterEnabled' => $website->effectiveFeatureFlags()['ai_writer'] ?? false,
            'tierGate' => $website->featureGateInfo('ai_writer'),
            'workspaceDomain' => $this->workspaceDomain($website),
        ]);
    }

    /* ───────────────── writer projects ───────────────── */

    public function index(Request $request): JsonResponse
    {
        $website = $this->requireWebsite($request);
        if ($gate = $this->gate($website)) {
            return $gate;
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $statusFilter = (string) $request->query('status', 'all');

        $query = WriterProject::query()
            ->where('website_id', $website->id)
            ->orderByDesc('updated_at');

        if ($statusFilter === 'active') {
            $query->where('step', '!=', WriterProject::STEP_COMPLETED);
        } elseif ($statusFilter === 'completed') {
            $query->where('step', WriterProject::STEP_COMPLETED);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (WriterProject $p) => $this->summary($p))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $website = $this->requireWebsite($request);
        if ($gate = $this->gate($website)) {
            return $gate;
        }

        $data = $request->validate([
            'focus_keyword' => 'required|string|min:2|max:200',
            'title' => 'nullable|string|max:300',
            'additional_keywords' => 'nullable|array|max:20',
            'additional_keywords.*' => 'string|max:200',
            'lsi_keywords' => 'nullable|array|max:30',
            'lsi_keywords.*' => 'string|max:200',
            'country' => 'nullable|string|size:2',
            'language' => 'nullable|string|min:2|max:8',
            'tone' => 'nullable|string|max:32',
            'audience' => 'nullable|string|max:32',
            'custom_prompt' => 'nullable|string|min:5|max:2000',
        ]);

        if (! empty($data['custom_prompt'])) {
            $verdict = $this->promptGuard->check((string) $data['custom_prompt']);
            if (($verdict['ok'] ?? false) !== true) {
                return $this->promptRejected($verdict);
            }
        }

        $project = $this->service->create($website, Auth::id(), $data);

        return response()->json(['project' => $this->full($project)], 201);
    }

    public function show(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return response()->json(['project' => $this->full($project)]);
    }

    public function update(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->validate([
            'title' => 'nullable|string|max:300',
            'focus_keyword' => 'nullable|string|min:2|max:200',
            'additional_keywords' => 'nullable|array|max:20',
            'additional_keywords.*' => 'string|max:200',
            'lsi_keywords' => 'nullable|array|max:30',
            'lsi_keywords.*' => 'string|max:200',
            'country' => 'nullable|string|size:2',
            'language' => 'nullable|string|min:2|max:8',
            'tone' => 'nullable|string|max:32',
            'audience' => 'nullable|string|max:32',
            'custom_prompt' => 'nullable|string|max:2000',
            'step' => ['nullable', Rule::in(WriterProject::STEPS)],
            'brief' => 'nullable|array',
            'images' => 'nullable|array|max:20',
            'images.*.url' => 'required_with:images|string|max:2048',
            'images.*.thumbnail_url' => 'nullable|string|max:2048',
            'images.*.source' => ['nullable', Rule::in(['serper', 'upload'])],
            'images.*.alt' => 'nullable|string|max:200',
            'images.*.caption' => 'nullable|string|max:280',
            'images.*.assigned_h2' => 'nullable|string|max:200',
            'images.*.width' => 'nullable|integer|min:0|max:20000',
            'images.*.height' => 'nullable|integer|min:0|max:20000',
            'seo_titles' => 'nullable|array',
            'seo_titles.*' => 'string|max:200',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:320',
            'meta_descriptions' => 'nullable|array',
            'meta_descriptions.*' => 'string|max:320',
            'og_title' => 'nullable|string|max:200',
            'og_description' => 'nullable|string|max:320',
            'faqs' => 'nullable|array',
            'faqs.*.question' => 'required_with:faqs|string|max:300',
            'faqs.*.answer' => 'required_with:faqs|string|max:2000',
            'keyword_suggestions' => 'nullable|array',
            'keyword_suggestions.*' => 'string|max:200',
            'link_suggestions' => 'nullable|array',
            'selected_links' => 'nullable|array',
            'selected_links.internal' => 'nullable|array|max:50',
            'selected_links.internal.*.anchor' => 'required_with:selected_links.internal|string|max:200',
            'selected_links.internal.*.url' => 'required_with:selected_links.internal|url|max:2048',
            'selected_links.internal.*.manual' => 'nullable|boolean',
            'selected_links.external' => 'nullable|array|max:50',
            'selected_links.external.*.anchor' => 'required_with:selected_links.external|string|max:200',
            'selected_links.external.*.url' => 'required_with:selected_links.external|url|max:2048',
            'selected_links.external.*.manual' => 'nullable|boolean',
            'wp_post_id' => 'nullable|integer|min:1',
        ]);

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $project->title = $data['title'];
        }
        if (array_key_exists('focus_keyword', $data) && $data['focus_keyword'] !== null) {
            $project->focus_keyword = $data['focus_keyword'];
        }
        if (array_key_exists('additional_keywords', $data) && $data['additional_keywords'] !== null) {
            $project->additional_keywords = array_values(array_filter(array_map('strval', $data['additional_keywords'])));
        }
        if (array_key_exists('lsi_keywords', $data) && $data['lsi_keywords'] !== null) {
            $project->lsi_keywords = array_values(array_filter(array_map(
                static fn ($k) => trim((string) $k),
                $data['lsi_keywords']
            ), static fn ($k) => $k !== ''));
        }
        foreach (['country', 'language', 'tone', 'audience'] as $localeField) {
            if (array_key_exists($localeField, $data) && $data[$localeField] !== null) {
                $project->{$localeField} = $data[$localeField];
            }
        }
        if (array_key_exists('custom_prompt', $data)) {
            $incoming = is_string($data['custom_prompt']) ? trim($data['custom_prompt']) : '';
            if ($incoming === '') {
                $project->custom_prompt = null;
            } elseif ($incoming !== (string) ($project->custom_prompt ?? '')) {
                $verdict = $this->promptGuard->check($incoming);
                if (($verdict['ok'] ?? false) !== true) {
                    return $this->promptRejected($verdict);
                }
                $project->custom_prompt = $incoming;
            }
        }
        foreach (['meta_title', 'meta_description', 'og_title', 'og_description'] as $metaField) {
            if (array_key_exists($metaField, $data) && $data[$metaField] !== null) {
                $project->{$metaField} = $data[$metaField];
            }
        }
        foreach (['seo_titles', 'meta_descriptions', 'faqs', 'keyword_suggestions', 'link_suggestions'] as $jsonField) {
            if (array_key_exists($jsonField, $data) && $data[$jsonField] !== null) {
                $project->{$jsonField} = $data[$jsonField];
            }
        }
        if (array_key_exists('selected_links', $data) && $data['selected_links'] !== null) {
            $raw = $data['selected_links'];
            $project->selected_links = [
                'internal' => array_values(array_map(static fn (array $l) => [
                    'anchor' => (string) $l['anchor'],
                    'url'    => (string) $l['url'],
                    'manual' => (bool) ($l['manual'] ?? false),
                ], (array) ($raw['internal'] ?? []))),
                'external' => array_values(array_map(static fn (array $l) => [
                    'anchor' => (string) $l['anchor'],
                    'url'    => (string) $l['url'],
                    'manual' => (bool) ($l['manual'] ?? false),
                ], (array) ($raw['external'] ?? []))),
            ];
        }
        if (array_key_exists('step', $data) && $data['step'] !== null) {
            $project->step = $data['step'];
        }
        if (array_key_exists('brief', $data) && $data['brief'] !== null) {
            $project->brief = $data['brief'];
        }
        if (array_key_exists('wp_post_id', $data) && $data['wp_post_id'] !== null) {
            $project->wp_post_id = (int) $data['wp_post_id'];
        }
        $project->save();

        if (array_key_exists('images', $data) && $data['images'] !== null) {
            $project = $this->service->updateImages($project, $data['images']);
        }

        return response()->json(['project' => $this->full($project->refresh())]);
    }

    public function destroy(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $project->delete();

        return response()->json(['ok' => true]);
    }

    public function generateBrief(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        @set_time_limit(360);
        $result = $this->service->generateBrief($project, $this->currentWebsite($request));

        if (($result['ok'] ?? false) !== true) {
            return response()->json([
                'ok'      => false,
                'error'   => (string) ($result['error'] ?? 'brief_generation_failed'),
                'message' => (string) ($result['message'] ?? 'Brief generation failed.'),
                'project' => $this->full($result['project']),
            ]);
        }

        return response()->json(['project' => $this->full($result['project'])]);
    }

    public function briefChat(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->validate(['message' => 'required|string|min:1|max:2000']);

        @set_time_limit(180);
        $project = $this->service->applyBriefChat($project, (string) $data['message']);

        return response()->json(['project' => $this->full($project)]);
    }

    public function searchImages(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->validate([
            'query' => 'nullable|string|max:200',
            'num' => 'nullable|integer|min:1|max:40',
        ]);

        $images = $this->service->searchImages(
            $project,
            $data['query'] ?? null,
            (int) ($data['num'] ?? 16),
        );

        return response()->json([
            'images' => $images,
            'project' => $this->full($project->refresh()),
        ]);
    }

    public function strategy(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->validate([
            'only' => 'nullable|array',
            'only.*' => ['string', Rule::in(['seo_titles', 'meta', 'faqs', 'keyword_suggestions', 'link_suggestions'])],
        ]);

        @set_time_limit(360);
        $only = is_array($data['only'] ?? null) ? $data['only'] : null;
        $project = $this->service->generateStrategy($project, $this->currentWebsite($request), $only);

        return response()->json(['project' => $this->full($project)]);
    }

    public function generate(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        @set_time_limit(360);
        $project = $this->service->generate($project, $this->currentWebsite($request));

        return response()->json(['project' => $this->full($project)]);
    }

    public function credits(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return response()->json([
            'project_id' => $project->external_id,
            'credits_used' => $this->service->totalCreditsForProject($project),
            'credits_used_cached' => (int) $project->credits_used,
        ]);
    }

    /* ───────────────── prompt library ───────────────── */

    public function promptsIndex(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);

        $prompts = AiWriterPrompt::query()
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $prompts->map(fn (AiWriterPrompt $p) => $this->shapePrompt($p))->all(),
        ]);
    }

    public function promptsStore(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);

        $data = $request->validate([
            'title' => 'required|string|min:2|max:120',
            'body'  => 'required|string|min:5|max:2000',
        ]);

        $body = self::stripDashes(trim((string) $data['body']));
        $title = self::stripDashes(trim((string) $data['title']));

        $verdict = $this->promptGuard->check($body);
        if (($verdict['ok'] ?? false) !== true) {
            return $this->promptRejected($verdict);
        }

        $prompt = AiWriterPrompt::create([
            'user_id' => $userId,
            'title'   => $title,
            'body'    => $body,
        ]);

        return response()->json(['prompt' => $this->shapePrompt($prompt)], 201);
    }

    public function promptsDestroy(Request $request, string $externalId): JsonResponse
    {
        $userId = $this->requireUserId($request);

        $prompt = AiWriterPrompt::query()
            ->where('user_id', $userId)
            ->where('external_id', $externalId)
            ->first();

        if (! $prompt instanceof AiWriterPrompt) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $prompt->delete();

        return response()->json(['ok' => true]);
    }

    /* ───────────────── helpers ───────────────── */

    private function currentWebsite(Request $request): ?Website
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $id = (int) session('current_website_id', 0);
        if ($id <= 0) {
            return null;
        }
        if (! $user->canViewWebsiteId($id)) {
            return null;
        }
        return Website::find($id);
    }

    /**
     * Website context for a JSON endpoint, or a 422 when missing. Callers
     * short-circuit on the JsonResponse return.
     */
    private function requireWebsite(Request $request): Website|JsonResponse
    {
        $website = $this->currentWebsite($request);
        if (! $website instanceof Website) {
            return response()->json([
                'ok' => false,
                'error' => 'no_website',
                'message' => 'Select a website before using the wizard.',
            ], 422);
        }
        return $website;
    }

    private function requireUserId(Request $request): int
    {
        return (int) (Auth::id() ?? abort(403, 'Not authenticated'));
    }

    /**
     * Pro/feature gate. Returns a 402 JsonResponse when locked, null when
     * the site can use the wizard.
     */
    private function gate(Website $website): ?JsonResponse
    {
        $info = $website->featureGateInfo('ai_writer');
        if ($info === null) {
            return null;
        }
        return response()->json($info + [
            'ok' => false,
            'message' => 'The Blog Post Wizard is available on Pro. Upgrade to unlock.',
        ], 402);
    }

    /**
     * Resolve a project for the current website (Pro-gated), or a
     * JsonResponse (no_website / tier_required / not_found) the caller
     * returns as-is.
     */
    private function resolve(Request $request, string $externalId): WriterProject|JsonResponse
    {
        $website = $this->requireWebsite($request);
        if ($website instanceof JsonResponse) {
            return $website;
        }
        if ($gate = $this->gate($website)) {
            return $gate;
        }

        $project = WriterProject::query()
            ->where('website_id', $website->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $project instanceof WriterProject) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return $project;
    }

    private function promptRejected(array $verdict): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'error'   => 'prompt_rejected',
            'message' => (string) ($verdict['reason'] ?? 'This prompt isn\'t related to AI writing.'),
        ], 422);
    }

    /**
     * Best-effort site domain for the manual-link internal/external
     * classifier in the Strategy step (mirrors the plugin's
     * `workspaceDomain` config value).
     */
    private function workspaceDomain(Website $website): string
    {
        $raw = (string) ($website->domain ?? '');
        if ($raw === '') {
            return '';
        }
        // `domain` may be stored bare ("example.com") or as a full URL;
        // normalise to a bare host so the link classifier can compare it.
        $host = parse_url($raw, PHP_URL_HOST) ?: $raw;
        $host = preg_replace('#^https?://#', '', strtolower($host)) ?? $host;
        $host = preg_replace('#/.*$#', '', $host) ?? $host;
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WriterProject $p): array
    {
        return [
            'id' => $p->external_id,
            'title' => $p->title,
            'focus_keyword' => $p->focus_keyword,
            'step' => $p->step,
            'credits_used' => (int) $p->credits_used,
            'wp_post_id' => $p->wp_post_id,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function full(WriterProject $p): array
    {
        return [
            'id' => $p->external_id,
            'title' => $p->title,
            'focus_keyword' => $p->focus_keyword,
            'additional_keywords' => is_array($p->additional_keywords) ? $p->additional_keywords : [],
            'lsi_keywords' => is_array($p->lsi_keywords) ? $p->lsi_keywords : [],
            'country' => $p->country,
            'language' => $p->language,
            'tone' => $p->tone,
            'audience' => $p->audience,
            'custom_prompt' => (string) ($p->custom_prompt ?? ''),
            'step' => $p->step,
            'brief' => is_array($p->brief) ? $p->brief : null,
            'chat_history' => is_array($p->chat_history) ? $p->chat_history : [],
            'images' => is_array($p->images) ? $p->images : [],
            'seo_titles' => is_array($p->seo_titles) ? $p->seo_titles : [],
            'meta_title' => (string) ($p->meta_title ?? ''),
            'meta_description' => (string) ($p->meta_description ?? ''),
            'meta_descriptions' => is_array($p->meta_descriptions) ? $p->meta_descriptions : [],
            'og_title' => (string) ($p->og_title ?? ''),
            'og_description' => (string) ($p->og_description ?? ''),
            'faqs' => is_array($p->faqs) ? $p->faqs : [],
            'keyword_suggestions' => is_array($p->keyword_suggestions) ? $p->keyword_suggestions : [],
            'link_suggestions' => is_array($p->link_suggestions) ? $p->link_suggestions : [],
            'selected_links' => is_array($p->selected_links) ? $p->selected_links : ['internal' => [], 'external' => []],
            'generated_html' => (string) ($p->generated_html ?? ''),
            'wp_post_id' => $p->wp_post_id,
            'credits_used' => (int) $p->credits_used,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shapePrompt(AiWriterPrompt $p): array
    {
        return [
            'id'         => $p->external_id,
            'title'      => $p->title,
            'body'       => $p->body,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * Mirror of AiWriterService::stripDashes — em / en dashes and the
     * double-hyphen are AI tells and are banned from anything we store.
     */
    private static function stripDashes(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $s = str_replace(["\u{2014}", "\u{2013}", '--'], ' ', $s);
        return preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
    }
}
