<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WriterProject;
use App\Services\AiWriter\CustomPromptGuard;
use App\Services\WriterProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI Writer wizard API — multi-step project lifecycle: topic → brief →
 * images → summary → completed. The WP plugin's React wizard talks to
 * this controller via the EBQ_Rest_Proxy.
 *
 * Pro tier only (mirrors the existing /ai-writer guard). Every state
 * mutation flows through WriterProjectService so credit accounting and
 * shape normalization stay in one place.
 */
class WriterProjectController extends Controller
{
    public function __construct(
        private readonly WriterProjectService $service,
        private readonly CustomPromptGuard $promptGuard,
    ) {
    }

    /**
     * GET /api/v1/hq/writer-projects?status=active|completed&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $this->requirePro($website);

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(50, $perPage));

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

    /**
     * POST /api/v1/hq/writer-projects
     * body: { focus_keyword, additional_keywords?[], title? }
     */
    public function store(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $this->requirePro($website);

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
                return response()->json([
                    'ok'      => false,
                    'error'   => 'prompt_rejected',
                    'message' => (string) ($verdict['reason'] ?? 'This prompt isn\'t related to AI writing.'),
                ], 422);
            }
        }

        $project = $this->service->create($website, $request->user()?->id, $data);

        return response()->json([
            'project' => $this->full($project),
        ], 201);
    }

    /**
     * GET /api/v1/hq/writer-projects/{externalId}
     */
    public function show(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);

        return response()->json([
            'project' => $this->full($project),
        ]);
    }

    /**
     * PATCH /api/v1/hq/writer-projects/{externalId}
     * body: { title?, focus_keyword?, additional_keywords?[], step?, brief?, images?, wp_post_id? }
     *
     * Free-form patch — the wizard PATCHes after each user action so
     * leaving and resuming picks up the exact same state. Generation
     * has its own endpoint (POST /generate).
     */
    public function update(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);

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
            // Strategy outputs — user can override individual fields
            // (pick a different SEO title from the suggestions, edit
            // the meta description, etc.) without regenerating the bundle.
            'seo_titles' => 'nullable|array',
            'seo_titles.*' => 'string|max:200',
            'h1' => 'nullable|string|max:200',
            'h1_suggestions' => 'nullable|array',
            'h1_suggestions.*' => 'string|max:200',
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
            // User's curated picks from `link_suggestions` plus any
            // manually-typed entries. Internal vs external is a UX
            // distinction only — both reach the writer as `<a>` targets.
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
                // Explicit clear — the wizard sends '' when the user
                // removes their custom prompt.
                $project->custom_prompt = null;
            } elseif ($incoming !== (string) ($project->custom_prompt ?? '')) {
                $verdict = $this->promptGuard->check($incoming);
                if (($verdict['ok'] ?? false) !== true) {
                    return response()->json([
                        'ok'      => false,
                        'error'   => 'prompt_rejected',
                        'message' => (string) ($verdict['reason'] ?? 'This prompt isn\'t related to AI writing.'),
                    ], 422);
                }
                $project->custom_prompt = $incoming;
            }
        }
        foreach (['h1', 'meta_title', 'meta_description', 'og_title', 'og_description'] as $metaField) {
            if (array_key_exists($metaField, $data) && $data[$metaField] !== null) {
                $project->{$metaField} = $data[$metaField];
            }
        }
        foreach (['seo_titles', 'h1_suggestions', 'meta_descriptions', 'faqs', 'keyword_suggestions', 'link_suggestions'] as $jsonField) {
            if (array_key_exists($jsonField, $data) && $data[$jsonField] !== null) {
                $project->{$jsonField} = $data[$jsonField];
            }
        }
        // Normalise selected_links into the canonical {internal:[], external:[]}
        // shape so downstream code never has to defend against partial input.
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
            $project->wp_post_id = (string) $data['wp_post_id'];
        }
        $project->save();

        if (array_key_exists('images', $data) && $data['images'] !== null) {
            $project = $this->service->updateImages($project, $data['images']);
        }

        return response()->json([
            'project' => $this->full($project->refresh()),
        ]);
    }

    /**
     * DELETE /api/v1/hq/writer-projects/{externalId}
     * Soft delete — the row stays for billing audits.
     */
    public function destroy(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        $project->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/hq/writer-projects/{externalId}/brief
     * Generate (or regenerate) the topic brief for step 2.
     */
    public function generateBrief(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        $website = $this->website($request);

        $result = $this->service->generateBrief($project, $website);

        if (($result['ok'] ?? false) !== true) {
            // 200 so the WP REST proxy and @wordpress/api-fetch pass the
            // structured body through; the frontend reads `error` /
            // `message` to display the actual reason instead of a
            // silently-empty brief screen.
            return response()->json([
                'ok'      => false,
                'error'   => (string) ($result['error'] ?? 'brief_generation_failed'),
                'message' => (string) ($result['message'] ?? 'Brief generation failed.'),
                'project' => $this->full($result['project']),
            ]);
        }

        return response()->json([
            'project' => $this->full($result['project']),
        ]);
    }

    /**
     * POST /api/v1/hq/writer-projects/{externalId}/brief/chat
     * body: { message }
     */
    public function briefChat(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);

        $data = $request->validate([
            'message' => 'required|string|min:1|max:2000',
        ]);

        $project = $this->service->applyBriefChat($project, (string) $data['message']);

        return response()->json([
            'project' => $this->full($project),
        ]);
    }

    /**
     * POST /api/v1/hq/writer-projects/{externalId}/images/search
     * body: { query?, num? }
     */
    public function searchImages(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);

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

    /**
     * POST /api/v1/hq/writer-projects/{externalId}/strategy
     * body: { only?: ["seo_titles", "meta", "faqs", "keyword_suggestions", "link_suggestions"] }
     *
     * Run the Strategy step's bundle. Without `only`, every output is
     * generated (or regenerated). With `only`, just the listed
     * outputs are refreshed — used by the per-card "Regenerate" buttons.
     */
    public function strategy(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        $website = $this->website($request);

        $data = $request->validate([
            'only' => 'nullable|array',
            'only.*' => ['string', Rule::in(['seo_titles', 'h1', 'lsi', 'meta', 'faqs', 'keyword_suggestions', 'link_suggestions'])],
        ]);

        $only = is_array($data['only'] ?? null) ? $data['only'] : null;
        $project = $this->service->generateStrategy($project, $website, $only);

        return response()->json([
            'project' => $this->full($project),
        ]);
    }

    /**
     * POST /api/v1/hq/writer-projects/{externalId}/generate
     * Final generation — strict-mode writer + image placement.
     *
     * Plugin ≥2.0.12 sends `async=1` and polls generateStatus() — the
     * 2–4 minute LLM call would otherwise hold a wp_remote_post open for
     * its full duration, which shared WP hosts routinely kill. The
     * blocking path stays for older installed plugin versions.
     */
    public function generate(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);
        $website = $this->website($request);

        if ($request->boolean('async')) {
            if (! in_array($project->generation_status, [WriterProject::GEN_QUEUED, WriterProject::GEN_RUNNING], true)) {
                $project->generation_status = WriterProject::GEN_QUEUED;
                $project->generation_error = null;
                $project->generation_started_at = null;
                $project->save();
                \App\Jobs\GenerateWriterDraftJob::dispatch($project->id, (string) $website->id);
            }

            return response()->json(['generation' => $this->generationState($project)], 202);
        }

        $project = $this->service->generate($project, $website);

        return response()->json([
            'project' => $this->full($project),
        ]);
    }

    /**
     * GET /api/v1/hq/writer-projects/{externalId}/generate-status
     * Poll target for the async flow; carries the full project once done.
     */
    public function generateStatus(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);

        $this->service->failStaleGeneration($project);

        $out = ['generation' => $this->generationState($project)];
        if ($project->generation_status === WriterProject::GEN_DONE) {
            $out['project'] = $this->full($project);
        }

        return response()->json($out);
    }

    /** @return array{status:string,error:?string,started_at:?string} */
    private function generationState(WriterProject $project): array
    {
        return [
            'status' => (string) ($project->generation_status ?? WriterProject::GEN_IDLE),
            'error' => $project->generation_error,
            'started_at' => $project->generation_started_at?->toIso8601String(),
        ];
    }

    /**
     * GET /api/v1/hq/writer-projects/{externalId}/credits
     * Live sum from client_activities (segregated by provider).
     */
    public function credits(Request $request, string $externalId): JsonResponse
    {
        $project = $this->resolve($request, $externalId);

        return response()->json([
            'project_id' => $project->external_id,
            'credits_used' => $this->service->totalCreditsForProject($project),
            'credits_used_cached' => (int) $project->credits_used,
        ]);
    }

    /* ───────────────── helpers ───────────────── */

    private function website(Request $request): Website
    {
        $w = $request->attributes->get('api_website');
        abort_unless($w instanceof Website, 500, 'Website context missing');
        return $w;
    }

    private function requirePro(Website $website): void
    {
        $gate = $website->featureGateInfo('ai_writer');
        if ($gate !== null) {
            abort(response()->json($gate + [
                'message' => 'AI Writer is available on Pro. Upgrade to unlock.',
            ], 402));
        }
    }

    private function resolve(Request $request, string $externalId): WriterProject
    {
        $website = $this->website($request);
        $this->requirePro($website);

        $project = WriterProject::query()
            ->where('website_id', $website->id)
            ->where('external_id', $externalId)
            ->first();

        abort_unless($project instanceof WriterProject, 404, 'Project not found');
        return $project;
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
            'h1' => (string) ($p->h1 ?? ''),
            'h1_suggestions' => is_array($p->h1_suggestions) ? $p->h1_suggestions : [],
            'lsi_suggestions' => is_array($p->lsi_suggestions) ? $p->lsi_suggestions : [],
            'keyword_data' => is_array($p->keyword_data) ? $p->keyword_data : (object) [],
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
            'generated_h1' => (string) ($p->generated_h1 ?? ''),
            'generation_meta' => is_array($p->generation_meta) ? $p->generation_meta : null,
            'generation_status' => (string) ($p->generation_status ?? WriterProject::GEN_IDLE),
            'generation_error' => $p->generation_error,
            'wp_post_id' => $p->wp_post_id,
            'credits_used' => (int) $p->credits_used,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
