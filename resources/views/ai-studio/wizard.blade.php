{{--
    EBQ AI Studio — Blog Post Wizard (dashboard surface).

    A faithful Blade + Alpine re-implementation of the WP plugin's React
    AI Writer wizard (src/hq/aiwriter/*). Same multi-step lifecycle —
    topic → brief → strategy → images → summary → review — driven by the
    same WriterProjectService through AiStudioWriterController.

    Two WP-runtime steps have no dashboard analogue and are adapted here:
      • Images: Serper image search only (no wp.media upload button).
      • Review: preview + editable HTML + copy / download / mark-complete
        (no wp/v2/posts draft save, no TinyMCE — the platform has no
        outbound WordPress credentials). The generated HTML is persisted
        on the project either way.
--}}
<x-layouts.app>
    @php
        $featureLocked = ! ($aiWriterEnabled ?? false);
        $tierGate = $tierGate ?? null;
        $endpoints = [
            'list'         => route('ai-studio.writer-projects.index'),
            'create'       => route('ai-studio.writer-projects.store'),
            'project'      => route('ai-studio.writer-projects.show', ['externalId' => '__ID__']),
            'brief'        => route('ai-studio.writer-projects.brief', ['externalId' => '__ID__']),
            'briefChat'    => route('ai-studio.writer-projects.brief.chat', ['externalId' => '__ID__']),
            'imagesSearch' => route('ai-studio.writer-projects.images.search', ['externalId' => '__ID__']),
            'strategy'     => route('ai-studio.writer-projects.strategy', ['externalId' => '__ID__']),
            'generate'     => route('ai-studio.writer-projects.generate', ['externalId' => '__ID__']),
            'credits'      => route('ai-studio.writer-projects.credits', ['externalId' => '__ID__']),
            'promptsList'  => route('ai-studio.prompts.index'),
            'promptsStore' => route('ai-studio.prompts.store'),
            'promptsDestroy' => route('ai-studio.prompts.destroy', ['externalId' => '__ID__']),
        ];
    @endphp

    <script>
    function aiWriter(opts) {
        const STEPS = ['topic', 'brief', 'strategy', 'images', 'summary', 'completed'];
        const STEP_LABELS = { topic: 'Topic', brief: 'Brief', strategy: 'Strategy', images: 'Images', summary: 'Summary', completed: 'Done' };
        const MAX_IMAGES = 6;
        const COUNTRIES = [
            ['us','United States'],['gb','United Kingdom'],['ca','Canada'],['au','Australia'],['in','India'],
            ['ie','Ireland'],['nz','New Zealand'],['sg','Singapore'],['za','South Africa'],['ae','United Arab Emirates'],
            ['sa','Saudi Arabia'],['de','Germany'],['fr','France'],['es','Spain'],['it','Italy'],['nl','Netherlands'],
            ['be','Belgium'],['pt','Portugal'],['br','Brazil'],['mx','Mexico'],['ar','Argentina'],['jp','Japan'],
            ['kr','South Korea'],['tr','Turkey'],['pl','Poland'],['se','Sweden'],['no','Norway'],['dk','Denmark'],
            ['fi','Finland'],['ch','Switzerland'],['at','Austria'],['eg','Egypt'],
        ];
        const LANGUAGES = [
            ['en','English'],['es','Español'],['fr','Français'],['de','Deutsch'],['it','Italiano'],['pt','Português'],
            ['nl','Nederlands'],['sv','Svenska'],['no','Norsk'],['da','Dansk'],['fi','Suomi'],['pl','Polski'],
            ['cs','Čeština'],['el','Ελληνικά'],['tr','Türkçe'],['ro','Română'],['hu','Magyar'],['ru','Русский'],
            ['uk','Українська'],['ar','العربية'],['he','עברית'],['hi','हिन्दी'],['th','ไทย'],['vi','Tiếng Việt'],
            ['id','Bahasa Indonesia'],['ms','Bahasa Melayu'],['ja','日本語'],['ko','한국어'],['zh','中文'],
        ];
        const TONES = ['professional','casual','persuasive','informational','friendly','authoritative','witty','empathetic'];
        const AUDIENCES = [['beginner','Beginner'],['general','General audience'],['intermediate','Intermediate'],['expert','Expert / technical']];

        const cap = (s) => !s ? '' : s.charAt(0).toUpperCase() + s.slice(1);
        const truncate = (s, n) => (s || '').length <= n ? (s || '') : s.slice(0, n).trimEnd() + '…';

        return {
            // ── config ──
            csrf: opts.csrf,
            endpoints: opts.endpoints,
            featureLocked: opts.featureLocked,
            tierGate: opts.tierGate,
            workspaceDomain: opts.workspaceDomain || '',
            studioUrl: opts.studioUrl,
            billingUrl: opts.billingUrl,
            STEPS, STEP_LABELS, MAX_IMAGES, COUNTRIES, LANGUAGES, TONES, AUDIENCES, cap, truncate,

            // ── top-level ──
            view: 'list',          // 'list' | 'wizard'
            project: null,
            loading: false,
            error: '',
            postGenView: 'wizard', // 'wizard' | 'review'
            creditsUsed: 0,
            titleEditing: false,
            titleDraft: '',

            // ── picker ──
            pk: { items: [], loading: true, filter: 'active', error: '' },

            // ── topic step ──
            t: {
                title: '', focusKw: '', additionalRaw: '', lsiRaw: '',
                country: 'us', language: 'en', tone: 'professional', audience: 'general',
                customPrompt: '', selectedPromptId: '',
                busy: false, busyAction: '', error: '', showSaveModal: false,
                saveTitle: '', saveBusy: false, saveError: '',
            },
            prompts: [],

            // ── brief step ──
            b: { brief: { h1: '', sections: [], paa: [], gaps: [] }, chatHistory: [], chatInput: '', chatBusy: false, savingDirty: false, error: '' },
            briefTimer: null,

            // ── strategy step ──
            s: { busyAll: false, busyCard: {}, error: '', open: { meta: true, faqs: false, keywords: false, links: false }, drafts: {}, suggOpen: {}, manualAnchor: '', manualUrl: '', manualError: '' },

            // ── images step ──
            im: { query: '', results: [], searching: false, selected: [], savingDirty: false, error: '' },

            // ── review step ──
            rv: { mode: 'preview', html: '', saving: false, regenerating: false, error: '', saved: false, copied: false },

            /* ───────────────────────── lifecycle ───────────────────────── */

            init() {
                this.loadPicker();
                this.loadPrompts();
            },

            /* ───────────────────────── http ───────────────────────── */

            async req(method, url, body) {
                try {
                    const res = await fetch(url, {
                        method,
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: body !== undefined ? JSON.stringify(body) : undefined,
                    });
                    let payload = null;
                    try { payload = await res.json(); } catch (e) { /* empty */ }
                    if (!payload) return { ok: false, error: 'network', message: `Server returned ${res.status}.` };
                    return payload;
                } catch (e) {
                    return { ok: false, error: 'network', message: e.message || 'Request failed.' };
                }
            },
            url(key, id) { return id !== undefined ? this.endpoints[key].replace('__ID__', encodeURIComponent(id)) : this.endpoints[key]; },

            /* ───────────────────────── picker ───────────────────────── */

            async loadPicker() {
                this.pk.loading = true;
                this.pk.error = '';
                const u = new URL(this.endpoints.list, window.location.origin);
                u.searchParams.set('status', this.pk.filter);
                u.searchParams.set('per_page', '50');
                const res = await this.req('GET', u.toString());
                this.pk.loading = false;
                if (Array.isArray(res?.data)) this.pk.items = res.data;
                else { this.pk.error = res?.message || 'Could not load projects.'; this.pk.items = []; }
            },
            setFilter(f) { this.pk.filter = f; this.loadPicker(); },
            async removeProject(id) {
                if (!window.confirm('Delete this project? This can\'t be undone.')) return;
                await this.req('DELETE', this.url('project', id));
                this.loadPicker();
            },
            pickerEmpty() { return !this.pk.loading && this.pk.items.length === 0; },

            /* ───────────────────────── navigation ───────────────────────── */

            startNew() {
                this.project = null;
                this.view = 'wizard';
                this.postGenView = 'wizard';
                this.error = '';
                this.resetTopicBuffers(null);
            },
            exitToList() {
                this.view = 'list';
                this.project = null;
                this.postGenView = 'wizard';
                this.error = '';
                this.loadPicker();
            },
            async openProject(id) {
                this.loading = true;
                const res = await this.req('GET', this.url('project', id));
                this.loading = false;
                if (res?.project) {
                    this.setProject(res.project);
                    this.view = 'wizard';
                    this.postGenView = (res.project.generated_html || res.project.step === 'completed') ? 'review' : 'wizard';
                    this.error = '';
                    this.refreshCredits(id);
                } else {
                    this.error = res?.message || 'Could not load that project.';
                }
            },

            // Assigns the project and re-seeds every step's local buffers.
            setProject(p) {
                if (!p) return;
                this.project = p;
                this.creditsUsed = Number(p.credits_used || 0);
                this.resetTopicBuffers(p);
                this.b.brief = this.normalizeBrief(p.brief);
                this.b.chatHistory = Array.isArray(p.chat_history) ? p.chat_history : [];
                this.im.selected = Array.isArray(p.images) ? p.images : [];
                this.im.query = p.focus_keyword || '';
                this.syncStrategyDrafts(p);
                this.rv.html = p.generated_html || '';
            },
            onProjectChange(p) { if (p?.id) this.setProject(p); },

            step() { return this.project?.step || 'topic'; },
            // Which step panel to render — mirrors AiWriterTab: once the
            // article exists and we're in post-generation mode, the Review
            // surface replaces the Summary recap.
            surface() {
                if (this.postGenView === 'review' && (this.project?.generated_html || this.step() === 'completed')) return 'review';
                const st = this.step();
                if (st === 'topic' || !this.project) return 'topic';
                return st;
            },
            stepIndex(step) { const i = STEPS.indexOf(step); return i < 0 ? 0 : i; },
            currentIdx() { const st = this.step(); return this.stepIndex(st === 'completed' ? 'summary' : st); },
            visibleSteps() { return STEPS.filter((s) => s !== 'completed'); },
            stepReached(i) { return i <= this.currentIdx(); },

            async goToStep(step) {
                if (!this.project?.id) return;
                this.postGenView = 'wizard';
                if (step === 'strategy') this.s.error = '';
                const res = await this.req('PATCH', this.url('project', this.project.id), { step });
                if (res?.project) this.setProject(res.project);
                if (step === 'strategy') this.maybeAutoStrategy();
                if (step === 'images') this.maybeAutoImageSearch();
            },

            headerTitle() {
                if (this.view !== 'wizard') return 'Blog Post Wizard';
                return this.project ? (this.project.title || 'Untitled draft') : 'New blog post';
            },

            /* ───────────────────────── editable title ───────────────────────── */

            beginTitleEdit() { this.titleDraft = this.project?.title || ''; this.titleEditing = true; this.$nextTick(() => this.$refs.titleInput?.focus()); },
            async commitTitle() {
                this.titleEditing = false;
                const next = (this.titleDraft || '').trim();
                if (!this.project?.id || !next || next === this.project.title) return;
                const res = await this.req('PATCH', this.url('project', this.project.id), { title: next });
                if (res?.project) this.setProject(res.project);
            },

            /* ───────────────────────── credits ───────────────────────── */

            async refreshCredits(id) {
                const res = await this.req('GET', this.url('credits', id));
                if (typeof res?.credits_used === 'number') this.creditsUsed = res.credits_used;
            },

            /* ════════════════════════ STEP 1 — TOPIC ════════════════════════ */

            resetTopicBuffers(p) {
                this.t.title = p?.title || '';
                this.t.focusKw = p?.focus_keyword || '';
                this.t.additionalRaw = Array.isArray(p?.additional_keywords) ? p.additional_keywords.join(', ') : '';
                this.t.lsiRaw = Array.isArray(p?.lsi_keywords) ? p.lsi_keywords.join(', ') : '';
                this.t.country = p?.country || 'us';
                this.t.language = p?.language || 'en';
                this.t.tone = p?.tone || 'professional';
                this.t.audience = p?.audience || 'general';
                this.t.customPrompt = (typeof p?.custom_prompt === 'string') ? p.custom_prompt : '';
                this.t.selectedPromptId = '';
                this.t.error = '';
                // Try to re-link a saved prompt for the resumed project.
                if (this.t.customPrompt) {
                    const match = this.prompts.find((x) => (x.body || '').trim() === this.t.customPrompt.trim());
                    if (match) this.t.selectedPromptId = match.id;
                }
            },
            topicAdditionalKws() { return this.t.additionalRaw.split(',').map((s) => s.trim()).filter(Boolean); },
            topicLsiKws() { return this.t.lsiRaw.split(',').map((s) => s.trim()).filter(Boolean); },
            hasExistingBrief() {
                const b = this.project?.brief;
                return !!(b && Array.isArray(b.sections) && b.sections.length > 0);
            },
            arrEq(a, b) { return a.length === b.length && a.every((v, i) => v === b[i]); },
            topicInputsUnchanged() {
                const p = this.project;
                if (!p?.id) return false;
                return (p.title || '').trim() === this.t.title.trim()
                    && (p.focus_keyword || '').trim() === this.t.focusKw.trim()
                    && this.arrEq(Array.isArray(p.additional_keywords) ? p.additional_keywords : [], this.topicAdditionalKws())
                    && this.arrEq(Array.isArray(p.lsi_keywords) ? p.lsi_keywords : [], this.topicLsiKws())
                    && (p.custom_prompt || '').trim() === this.t.customPrompt.trim();
            },
            briefInputsUnchanged() {
                const p = this.project;
                if (!p?.id) return false;
                return (p.focus_keyword || '').trim() === this.t.focusKw.trim()
                    && this.arrEq(Array.isArray(p.additional_keywords) ? p.additional_keywords : [], this.topicAdditionalKws())
                    && (p.country || '') === this.t.country;
            },
            topicCanSubmit() { return this.t.focusKw.trim().length >= 2 && !this.t.busy; },
            topicContinueLabel() {
                if (!this.hasExistingBrief()) return 'Create topic brief →';
                return this.briefInputsUnchanged() ? 'Continue with existing brief →' : 'Save changes & rebuild brief →';
            },
            topicPayload() {
                const payload = {
                    focus_keyword: this.t.focusKw.trim(),
                    additional_keywords: this.topicAdditionalKws(),
                    lsi_keywords: this.topicLsiKws(),
                    country: this.t.country,
                    language: this.t.language,
                    tone: this.t.tone,
                    audience: this.t.audience,
                };
                const cleanTitle = this.t.title.trim();
                if (cleanTitle) payload.title = cleanTitle;
                const tp = this.t.customPrompt.trim();
                if (tp !== '') payload.custom_prompt = tp;
                else if (this.project?.id && (this.project.custom_prompt || '').trim() !== '') payload.custom_prompt = '';
                return payload;
            },
            // Returns the up-to-date project or throws on prompt rejection.
            async topicEnsureSaved() {
                if (!this.project?.id) {
                    const created = await this.req('POST', this.endpoints.create, this.topicPayload());
                    if (created?.error === 'prompt_rejected') throw new Error(created.message || 'That prompt isn\'t related to AI writing.');
                    if (!created?.project) throw new Error(created?.message || 'Could not create project.');
                    return created.project;
                }
                if (this.topicInputsUnchanged()) return this.project;
                const updated = await this.req('PATCH', this.url('project', this.project.id), this.topicPayload());
                if (updated?.error === 'prompt_rejected') throw new Error(updated.message || 'That prompt isn\'t related to AI writing.');
                return updated?.project || this.project;
            },
            async topicProceed() {
                this.t.busy = true; this.t.busyAction = 'continue'; this.t.error = '';
                try {
                    const p = await this.topicEnsureSaved();
                    this.setProject(p);
                    const needsBrief = !this.hasExistingBrief() || !this.briefInputsUnchanged();
                    if (needsBrief) {
                        const briefRes = await this.req('POST', this.url('brief', p.id));
                        if (briefRes?.ok === false || briefRes?.error) {
                            if (briefRes?.project) this.setProject(briefRes.project);
                            this.t.error = briefRes?.message || 'Could not generate brief.';
                            return;
                        }
                        if (briefRes?.project) this.setProject(briefRes.project);
                        else { this.t.error = briefRes?.message || 'Could not generate brief.'; return; }
                    }
                    this.goToStep('brief');
                } catch (e) {
                    this.t.error = e?.message || 'Network error.';
                } finally {
                    this.t.busy = false; this.t.busyAction = '';
                }
            },
            topicSubmit() {
                if (!this.topicCanSubmit()) return;
                const tp = this.t.customPrompt.trim();
                if (tp !== '' && !this.t.selectedPromptId) { this.openSaveModal(); return; }
                this.topicProceed();
            },
            async topicRegenerateBrief() {
                if (this.t.busy) return;
                const tp = this.t.customPrompt.trim();
                if (tp !== '' && !this.t.selectedPromptId) { this.openSaveModal(); return; }
                this.t.busy = true; this.t.busyAction = 'regenerate'; this.t.error = '';
                try {
                    const p = await this.topicEnsureSaved();
                    this.setProject(p);
                    const briefRes = await this.req('POST', this.url('brief', p.id));
                    if (briefRes?.ok === false || briefRes?.error) {
                        if (briefRes?.project) this.setProject(briefRes.project);
                        this.t.error = briefRes?.message || 'Could not regenerate brief.';
                        return;
                    }
                    if (briefRes?.project) { this.setProject(briefRes.project); this.goToStep('brief'); }
                    else this.t.error = briefRes?.message || 'Could not regenerate brief.';
                } catch (e) {
                    this.t.error = e?.message || 'Network error.';
                } finally {
                    this.t.busy = false; this.t.busyAction = '';
                }
            },

            // Prompt library.
            async loadPrompts() {
                const res = await this.req('GET', this.endpoints.promptsList);
                if (Array.isArray(res?.data)) {
                    this.prompts = res.data;
                    if (this.t.customPrompt && !this.t.selectedPromptId) {
                        const m = this.prompts.find((x) => (x.body || '').trim() === this.t.customPrompt.trim());
                        if (m) this.t.selectedPromptId = m.id;
                    }
                }
            },
            onPromptPick() {
                const id = this.t.selectedPromptId;
                if (!id) return;
                const p = this.prompts.find((x) => x.id === id);
                if (p) this.t.customPrompt = p.body || '';
            },
            onPromptType() {
                if (this.t.selectedPromptId) {
                    const p = this.prompts.find((x) => x.id === this.t.selectedPromptId);
                    if (!p || (p.body || '') !== this.t.customPrompt) this.t.selectedPromptId = '';
                }
            },
            openSaveModal() { this.t.saveTitle = ''; this.t.saveError = ''; this.t.showSaveModal = true; },
            async savePromptAndContinue() {
                const title = this.t.saveTitle.trim();
                if (title.length < 2) { this.t.saveError = 'Give the prompt a short title.'; return; }
                this.t.saveBusy = true; this.t.saveError = '';
                const res = await this.req('POST', this.endpoints.promptsStore, { title, body: this.t.customPrompt.trim() });
                this.t.saveBusy = false;
                if (res?.prompt) {
                    this.prompts = [res.prompt, ...this.prompts.filter((p) => p.id !== res.prompt.id)];
                    this.t.selectedPromptId = res.prompt.id;
                    this.t.showSaveModal = false;
                    this.topicProceed();
                } else {
                    this.t.saveError = res?.message || 'Could not save prompt.';
                }
            },
            skipSaveAndContinue() { this.t.showSaveModal = false; this.topicProceed(); },

            /* ════════════════════════ STEP 2 — BRIEF ════════════════════════ */

            normalizeBrief(brief) {
                const b = (brief && typeof brief === 'object') ? brief : {};
                return {
                    h1: typeof b.h1 === 'string' ? b.h1 : '',
                    sections: Array.isArray(b.sections) ? b.sections.map((s) => ({
                        h2: typeof s?.h2 === 'string' ? s.h2 : '',
                        subtopics: Array.isArray(s?.subtopics) ? s.subtopics.map(String) : [],
                    })).filter((s) => s.h2.trim() !== '' || s.subtopics.length > 0) : [],
                    paa: Array.isArray(b.paa) ? b.paa.filter((s) => typeof s === 'string') : [],
                    gaps: Array.isArray(b.gaps) ? b.gaps.filter((s) => typeof s === 'string') : [],
                };
            },
            queueBriefSave() {
                if (this.briefTimer) clearTimeout(this.briefTimer);
                this.b.savingDirty = true;
                this.briefTimer = setTimeout(async () => {
                    const res = await this.req('PATCH', this.url('project', this.project.id), { brief: JSON.parse(JSON.stringify(this.b.brief)) });
                    this.b.savingDirty = false;
                    if (res?.project) this.project = res.project; // keep buffers; only sync canonical
                }, 500);
            },
            briefSetH1(v) { this.b.brief.h1 = v; this.queueBriefSave(); },
            briefRename(i, v) { this.b.brief.sections[i].h2 = v; this.queueBriefSave(); },
            briefRemove(i) { this.b.brief.sections.splice(i, 1); this.queueBriefSave(); },
            briefMove(i, dir) {
                const j = i + dir;
                if (j < 0 || j >= this.b.brief.sections.length) return;
                const s = this.b.brief.sections;
                [s[i], s[j]] = [s[j], s[i]];
                this.queueBriefSave();
            },
            briefAddSection() { this.b.brief.sections.push({ h2: 'New section', subtopics: [] }); this.queueBriefSave(); },
            briefRenameSub(i, j, v) { this.b.brief.sections[i].subtopics[j] = v; this.queueBriefSave(); },
            briefRemoveSub(i, j) { this.b.brief.sections[i].subtopics.splice(j, 1); this.queueBriefSave(); },
            briefAddSub(i) { this.b.brief.sections[i].subtopics.push(''); this.queueBriefSave(); },
            async briefSendChat() {
                const msg = this.b.chatInput.trim();
                if (!msg || this.b.chatBusy) return;
                this.b.chatBusy = true; this.b.error = '';
                this.b.chatHistory = [...this.b.chatHistory, { role: 'user', content: msg }];
                this.b.chatInput = '';
                const res = await this.req('POST', this.url('briefChat', this.project.id), { message: msg });
                this.b.chatBusy = false;
                if (res?.project) {
                    this.project = res.project;
                    this.b.brief = this.normalizeBrief(res.project.brief);
                    this.b.chatHistory = Array.isArray(res.project.chat_history) ? res.project.chat_history : this.b.chatHistory;
                } else {
                    this.b.error = res?.message || 'Could not apply change.';
                }
            },
            async briefRegenerate() {
                this.b.savingDirty = true;
                const res = await this.req('POST', this.url('brief', this.project.id));
                this.b.savingDirty = false;
                if (res?.project) { this.project = res.project; this.b.brief = this.normalizeBrief(res.project.brief); }
            },

            /* ════════════════════════ STEP 3 — STRATEGY ════════════════════════ */

            strategyHasAny() {
                const p = this.project;
                if (!p) return false;
                const links = (p.link_suggestions && typeof p.link_suggestions === 'object') ? p.link_suggestions : { internal: [], external: [] };
                return (Array.isArray(p.seo_titles) && p.seo_titles.length > 0)
                    || !!p.meta_title || !!p.meta_description
                    || (Array.isArray(p.faqs) && p.faqs.length > 0)
                    || (Array.isArray(p.keyword_suggestions) && p.keyword_suggestions.length > 0)
                    || (Array.isArray(links.internal) && links.internal.length > 0)
                    || (Array.isArray(links.external) && links.external.length > 0);
            },
            maybeAutoStrategy() {
                if (!this.project?.id || this.strategyHasAny() || this.s.busyAll) return;
                this.runStrategy(null);
            },
            syncStrategyDrafts(p) {
                this.s.drafts = {
                    meta_title: String(p?.meta_title || ''),
                    meta_description: String(p?.meta_description || ''),
                    og_title: String(p?.og_title || ''),
                    og_description: String(p?.og_description || ''),
                };
            },
            async runStrategy(only) {
                if (only) this.s.busyCard = { ...this.s.busyCard, [only[0]]: true };
                else this.s.busyAll = true;
                this.s.error = '';
                const res = await this.req('POST', this.url('strategy', this.project.id), only ? { only } : {});
                if (only) this.s.busyCard = { ...this.s.busyCard, [only[0]]: false };
                else this.s.busyAll = false;
                if (res?.project) this.setProject(res.project);
                else this.s.error = res?.message || 'Could not generate strategy.';
            },
            async strategyPatch(fields) {
                const res = await this.req('PATCH', this.url('project', this.project.id), fields);
                if (res?.project) this.setProject(res.project);
            },
            toggleSec(key) { this.s.open[key] = !this.s.open[key]; },
            // meta field helpers
            containsKw(haystack, keyword) {
                if (!keyword) return true;
                const hay = String(haystack || '').toLowerCase();
                const kw = String(keyword || '').toLowerCase();
                if (hay.includes(kw)) return true;
                const tokens = kw.split(/\s+/).filter((t) => t.length > 2);
                if (tokens.length === 0) return false;
                return tokens.every((t) => hay.includes(t));
            },
            metaTone() {
                const tl = (this.s.drafts.meta_title || '').length;
                const dl = (this.s.drafts.meta_description || '').length;
                const kw = String(this.project?.focus_keyword || '').trim();
                const ok = tl >= 50 && tl <= 60 && dl >= 120 && dl <= 158
                    && (kw === '' || this.containsKw(this.s.drafts.meta_title, kw))
                    && (kw === '' || this.containsKw(this.s.drafts.meta_description, kw));
                return ok ? 'good' : 'warn';
            },
            metaFieldLenClass(key, min, max) {
                const len = (this.s.drafts[key] || '').length;
                if (len > max) return 'text-red-600 dark:text-red-400';
                if (min > 0 && len > 0 && len < min) return 'text-amber-600 dark:text-amber-400';
                if (len >= min && len > 0) return 'text-emerald-600 dark:text-emerald-400';
                return 'text-slate-400';
            },
            metaKwMissing(key) {
                const kw = String(this.project?.focus_keyword || '').trim();
                if (kw === '' || (key !== 'meta_title' && key !== 'meta_description')) return false;
                const v = this.s.drafts[key] || '';
                return v.length > 0 && !this.containsKw(v, kw);
            },
            metaBlur(key) {
                const v = this.s.drafts[key];
                if (v !== (this.project[key] || '')) this.strategyPatch({ [key]: v });
            },
            suggestionsFor(key) { return Array.isArray(this.project?.[key]) ? this.project[key] : []; },
            toggleSugg(key) { this.s.suggOpen = { ...this.s.suggOpen, [key]: !this.s.suggOpen[key] }; },
            pickSuggestion(field, value) {
                this.s.drafts[field] = value;
                const p = { [field]: value };
                if (field === 'meta_title') p.title = value;
                this.strategyPatch(p);
                this.s.suggOpen = {};
            },
            // FAQs
            strategyFaqs() { return Array.isArray(this.project?.faqs) ? this.project.faqs : []; },
            removeFaq(i) { this.strategyPatch({ faqs: this.strategyFaqs().filter((_, idx) => idx !== i) }); },
            // keywords
            keywordSuggestions() { return Array.isArray(this.project?.keyword_suggestions) ? this.project.keyword_suggestions : []; },
            additionalKeywords() { return Array.isArray(this.project?.additional_keywords) ? this.project.additional_keywords : []; },
            addKeyword(kw) {
                const cur = this.additionalKeywords();
                if (cur.includes(kw)) return;
                this.strategyPatch({ additional_keywords: [...cur, kw] });
            },
            // links
            linkSuggestions() {
                const l = (this.project?.link_suggestions && typeof this.project.link_suggestions === 'object') ? this.project.link_suggestions : { internal: [], external: [] };
                return { internal: Array.isArray(l.internal) ? l.internal : [], external: Array.isArray(l.external) ? l.external : [] };
            },
            selectedLinks() {
                const l = (this.project?.selected_links && typeof this.project.selected_links === 'object') ? this.project.selected_links : { internal: [], external: [] };
                return { internal: Array.isArray(l.internal) ? l.internal : [], external: Array.isArray(l.external) ? l.external : [] };
            },
            isLinkSelected(kind, url) { return this.selectedLinks()[kind].some((l) => l.url === url); },
            writeSelectedLinks(next) {
                const cur = this.selectedLinks();
                this.strategyPatch({ selected_links: { internal: next.internal ?? cur.internal, external: next.external ?? cur.external } });
            },
            toggleLink(kind, link) {
                const cur = this.selectedLinks()[kind];
                const exists = cur.find((l) => l.url === link.url);
                const next = exists ? cur.filter((l) => l.url !== link.url) : [...cur, { anchor: link.anchor, url: link.url, manual: false }];
                this.writeSelectedLinks({ [kind]: next });
            },
            removeManualLink(kind, url) {
                const cur = this.selectedLinks()[kind];
                this.writeSelectedLinks({ [kind]: cur.filter((l) => !(l.url === url && l.manual === true)) });
            },
            manualOnly(kind) { return this.selectedLinks()[kind].filter((l) => l.manual === true); },
            classifyUrl(url) {
                try {
                    const host = new URL(url).hostname.toLowerCase().replace(/^www\./, '');
                    const site = String(this.workspaceDomain).toLowerCase().replace(/^www\./, '');
                    return site && host === site ? 'internal' : 'external';
                } catch { return null; }
            },
            detectedManual() { return this.s.manualUrl.trim() !== '' ? this.classifyUrl(this.s.manualUrl.trim()) : null; },
            addManualLink() {
                this.s.manualError = '';
                const a = this.s.manualAnchor.trim();
                const u = this.s.manualUrl.trim();
                if (a === '' || u === '' || !/^https?:\/\//i.test(u)) { this.s.manualError = 'Anchor and URL are required. URL must start with http(s)://'; return; }
                const kind = this.classifyUrl(u);
                if (!kind) { this.s.manualError = 'Could not parse that URL.'; return; }
                const cur = this.selectedLinks()[kind];
                if (!cur.some((l) => l.url === u)) this.writeSelectedLinks({ [kind]: [...cur, { anchor: a, url: u, manual: true }] });
                this.s.manualAnchor = ''; this.s.manualUrl = '';
            },
            strategyHeadline() {
                const titles = Array.isArray(this.project?.seo_titles) ? this.project.seo_titles.length : 0;
                const faqs = this.strategyFaqs().length;
                const sel = this.selectedLinks();
                const links = sel.internal.length + sel.external.length;
                return `${titles} titles · ${faqs} FAQs · ${links} links selected`;
            },

            /* ════════════════════════ STEP 4 — IMAGES ════════════════════════ */

            maybeAutoImageSearch() {
                if (this.im.results.length === 0 && (this.im.query || '').trim().length >= 2) this.imageSearch();
            },
            async imageSearch() {
                if (this.im.searching) return;
                const q = this.im.query.trim();
                if (q.length < 2) return;
                this.im.searching = true; this.im.error = '';
                const res = await this.req('POST', this.url('imagesSearch', this.project.id), { query: q, num: 16 });
                this.im.searching = false;
                if (Array.isArray(res?.images)) { this.im.results = res.images; if (res.project) this.project = res.project; }
                else { this.im.results = []; this.im.error = res?.message || 'Image search failed.'; }
            },
            imgSelected(url) { return this.im.selected.some((s) => s.url === url); },
            imgCanAdd() { return this.im.selected.length < MAX_IMAGES; },
            async persistImages(next) {
                this.im.selected = next;
                this.im.savingDirty = true;
                const res = await this.req('PATCH', this.url('project', this.project.id), { images: next });
                this.im.savingDirty = false;
                if (res?.project) this.project = res.project;
            },
            toggleImage(img) {
                if (this.imgSelected(img.url)) { this.persistImages(this.im.selected.filter((s) => s.url !== img.url)); return; }
                if (!this.imgCanAdd()) { this.im.error = 'You can pick up to 6 images.'; return; }
                this.persistImages([...this.im.selected, {
                    source: 'serper', url: img.url, thumbnail_url: img.thumbnail_url || img.url,
                    alt: img.title || '', caption: '', assigned_h2: null, width: img.width || 0, height: img.height || 0,
                }]);
            },
            imgUpdateField(i, field, value) {
                this.persistImages(this.im.selected.map((s, idx) => (idx === i ? { ...s, [field]: value } : s)));
            },
            imgRemove(i) { this.persistImages(this.im.selected.filter((_, idx) => idx !== i)); },
            briefSectionTitles() { return Array.isArray(this.project?.brief?.sections) ? this.project.brief.sections.map((s) => s.h2) : []; },

            /* ════════════════════════ STEP 5 — SUMMARY ════════════════════════ */

            summarySections() { return Array.isArray(this.project?.brief?.sections) ? this.project.brief.sections : []; },
            summaryPaa() { return Array.isArray(this.project?.brief?.paa) ? this.project.brief.paa : []; },
            summaryGaps() { return Array.isArray(this.project?.brief?.gaps) ? this.project.brief.gaps : []; },
            estCredits() {
                const expected = this.summarySections().length + this.summaryPaa().length + this.summaryGaps().length;
                return Math.max(8, expected * 5);
            },
            hasGeneratedHtml() { return typeof this.project?.generated_html === 'string' && this.project.generated_html.trim() !== ''; },
            async generateArticle() {
                if (this.loading) return;
                this.loading = true; this.error = '';
                const res = await this.req('POST', this.url('generate', this.project.id));
                this.loading = false;
                if (res?.project) { this.setProject(res.project); this.postGenView = 'review'; }
                else this.error = res?.message || 'Generation failed.';
            },

            /* ════════════════════════ STEP 6 — REVIEW ════════════════════════ */

            reviewStats() {
                const html = this.rv.html || '';
                const text = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                const words = text === '' ? 0 : text.split(' ').length;
                const count = (re) => (html.match(re) || []).length;
                return {
                    words, headings: count(/<h[1-6]\b/gi), paragraphs: count(/<p\b/gi),
                    images: count(/<img\b/gi), links: count(/<a\s+[^>]*href=/gi),
                    minutes: Math.max(1, Math.round(words / 220)),
                };
            },
            previewHtml() {
                const title = (this.project?.title || '').replace(/[<>]/g, (c) => (c === '<' ? '&lt;' : '&gt;'));
                const heading = title ? `<h1 class="ebq-prev-h1">${title}</h1>` : '';
                return heading + (this.rv.html || '');
            },
            async reviewRegenerate() {
                if (this.rv.saving || this.rv.regenerating) return;
                if (this.reviewStats().words > 0 && !window.confirm('Regenerate the entire article? Your edits will be replaced, but your selected images and brief stay the same.')) return;
                this.rv.regenerating = true; this.rv.error = '';
                const res = await this.req('POST', this.url('generate', this.project.id));
                this.rv.regenerating = false;
                if (res?.project) this.setProject(res.project);
                else this.rv.error = res?.message || 'Could not regenerate.';
            },
            async reviewMarkComplete() {
                if (this.rv.saving) return;
                this.rv.saving = true; this.rv.error = ''; this.rv.saved = false;
                const res = await this.req('PATCH', this.url('project', this.project.id), { step: 'completed' });
                this.rv.saving = false;
                if (res?.project) { this.project = res.project; this.rv.saved = true; }
                else this.rv.error = res?.message || 'Could not save.';
            },
            async reviewCopy() {
                try { await navigator.clipboard.writeText(this.rv.html || ''); this.rv.copied = true; setTimeout(() => { this.rv.copied = false; }, 1500); } catch (e) { /* ignore */ }
            },
            reviewDownload() {
                const blob = new Blob([this.previewHtml()], { type: 'text/html' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = (this.project?.title || 'article').replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '.html';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 1000);
            },

            formatDate(iso) {
                if (!iso) return '';
                try { return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { return iso; }
            },
        };
    }
    </script>

    <div
        class="mx-auto w-full max-w-6xl"
        x-data="aiWriter({
            csrf: @js(csrf_token()),
            endpoints: @js($endpoints),
            featureLocked: @js($featureLocked),
            tierGate: @js($tierGate),
            workspaceDomain: @js($workspaceDomain ?? ''),
            studioUrl: @js(route('ai-studio.index')),
            billingUrl: @js(route('billing.show')),
        })"
        x-cloak
    >
        {{-- Header --}}
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <a href="{{ route('ai-studio.index') }}" class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                    All tools
                </a>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold text-slate-900 dark:text-slate-100" x-text="headerTitle()"></h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Full multi-step blog post — topic, brief, strategy, images, then generate.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <template x-if="view === 'wizard' && project">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300" title="EBQ Content Credits used by this project">
                        <span aria-hidden="true">✦</span>
                        <span x-text="creditsUsed.toLocaleString()"></span>
                        <span>credits</span>
                    </span>
                </template>
                <template x-if="view === 'wizard'">
                    <button type="button" @click="exitToList()" class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">Projects</button>
                </template>
            </div>
        </div>

        {{-- Tier banner --}}
        <template x-if="featureLocked && tierGate">
            <div class="mb-5 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">The Blog Post Wizard is a <span x-text="(tierGate.required_tier || 'Pro')" class="capitalize"></span> feature. Upgrade to generate.</p>
                <a :href="billingUrl" class="inline-flex items-center justify-center rounded-md bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-amber-700">See plans</a>
            </div>
        </template>

        <template x-if="error">
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="error"></div>
        </template>

        {{-- ════════════ PROJECT LIST ════════════ --}}
        <div x-show="view === 'list'">
            <div class="mb-4 flex items-center justify-between">
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-900">
                    <template x-for="f in ['active','completed','all']" :key="f">
                        <button type="button" @click="setFilter(f)" class="rounded-md px-3 py-1.5 capitalize transition" :class="pk.filter === f ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'" x-text="f"></button>
                    </template>
                </div>
                <button type="button" @click="startNew()" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">+ New project</button>
            </div>

            <template x-if="pk.error">
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="pk.error"></div>
            </template>

            <template x-if="pk.loading">
                <p class="rounded-xl border border-dashed border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">Loading projects…</p>
            </template>

            <template x-if="pickerEmpty()">
                <div class="rounded-xl border border-dashed border-slate-200 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
                    <p class="text-sm text-slate-500 dark:text-slate-400">No projects yet.</p>
                    <button type="button" @click="startNew()" class="mt-3 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Start a new project</button>
                </div>
            </template>

            <ul class="space-y-2" x-show="!pk.loading && pk.items.length > 0">
                <template x-for="p in pk.items" :key="p.id">
                    <li class="group flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-indigo-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-500/50">
                        <button type="button" @click="openProject(p.id)" class="min-w-0 flex-1 text-left">
                            <div class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100" x-text="p.title || 'Untitled draft'"></div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold capitalize text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="STEP_LABELS[p.step] || p.step"></span>
                                <span x-text="p.focus_keyword"></span>
                                <span x-text="`${p.credits_used} credits`"></span>
                                <span x-text="formatDate(p.updated_at)"></span>
                            </div>
                        </button>
                        <button type="button" @click="removeProject(p.id)" class="rounded-md p-1.5 text-slate-300 opacity-0 transition hover:bg-red-50 hover:text-red-500 group-hover:opacity-100 dark:hover:bg-red-900/30" title="Delete project">✕</button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- ════════════ WIZARD ════════════ --}}
        <div x-show="view === 'wizard'">
            {{-- Editable title --}}
            <template x-if="project">
                <div class="mb-4">
                    <template x-if="!titleEditing">
                        <button type="button" @click="beginTitleEdit()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-700 hover:text-indigo-600 dark:text-slate-200" title="Click to rename">
                            <span x-text="project.title || 'Untitled draft'"></span>
                            <span class="text-slate-400" aria-hidden="true">✎</span>
                        </button>
                    </template>
                    <template x-if="titleEditing">
                        <input type="text" x-ref="titleInput" x-model="titleDraft" @blur="commitTitle()" @keydown.enter="commitTitle()" @keydown.escape="titleEditing = false"
                            class="w-full max-w-md rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">
                    </template>
                </div>
            </template>

            {{-- Stepper --}}
            <ol class="mb-6 flex flex-wrap items-center gap-1.5">
                <template x-for="(s, i) in visibleSteps()" :key="s">
                    <li class="flex items-center">
                        <button type="button" @click="stepReached(i) && project && goToStep(s)" :disabled="!project || !stepReached(i)"
                            class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed"
                            :class="step() === s ? 'bg-indigo-600 text-white' : (stepReached(i) ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-300' : 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500')">
                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-white/30 text-[10px]" x-text="i + 1"></span>
                            <span x-text="STEP_LABELS[s]"></span>
                        </button>
                        <span x-show="i < visibleSteps().length - 1" class="mx-0.5 text-slate-300 dark:text-slate-600">·</span>
                    </li>
                </template>
            </ol>

            <template x-if="loading">
                <div class="mb-4 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">Working…</div>
            </template>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
                @include('ai-studio.wizard-steps.topic')
                @include('ai-studio.wizard-steps.brief')
                @include('ai-studio.wizard-steps.strategy')
                @include('ai-studio.wizard-steps.images')
                @include('ai-studio.wizard-steps.summary')
                @include('ai-studio.wizard-steps.review')
            </div>
        </div>

        {{-- Save-prompt modal --}}
        <template x-if="t.showSaveModal">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="t.showSaveModal = false">
                <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Save this prompt for next time?</h3>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Give it a title to reuse it across your sites, or skip — it still applies to this project.</p>
                    <input type="text" x-model="t.saveTitle" placeholder="Prompt title" class="mt-3 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <template x-if="t.saveError"><p class="mt-1.5 text-xs text-red-600 dark:text-red-400" x-text="t.saveError"></p></template>
                    <div class="mt-4 flex items-center justify-end gap-2">
                        <button type="button" @click="t.showSaveModal = false" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400">Cancel</button>
                        <button type="button" @click="skipSaveAndContinue()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Don't save</button>
                        <button type="button" @click="savePromptAndContinue()" :disabled="t.saveBusy" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" x-text="t.saveBusy ? 'Saving…' : 'Save & continue'"></button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</x-layouts.app>
