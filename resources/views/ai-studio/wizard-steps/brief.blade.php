{{-- Step 2 — Brief. Editable outline tree + AI chat refinement. --}}
<div x-show="surface() === 'brief'" class="space-y-5">
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        {{-- Outline editor --}}
        <div>
            <div class="flex items-center justify-between">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Content brief</h3>
                <div class="flex items-center gap-2">
                    <span x-show="b.savingDirty" class="text-[11px] text-slate-400">Saving…</span>
                    <button type="button" @click="briefRegenerate()" class="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Regenerate</button>
                </div>
            </div>

            <div class="mt-3">
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">H1</label>
                <input type="text" :value="b.brief.h1" @input="briefSetH1($event.target.value)" placeholder="Suggested H1 — uses the project title if empty"
                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>

            <ul class="mt-4 space-y-3">
                <template x-for="(s, i) in b.brief.sections" :key="i">
                    <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 flex-none items-center justify-center rounded-full bg-indigo-100 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300" x-text="i + 1"></span>
                            <input type="text" :value="s.h2" @input="briefRename(i, $event.target.value)"
                                class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm font-medium text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <div class="flex items-center gap-0.5 text-slate-400">
                                <button type="button" @click="briefMove(i, -1)" :disabled="i === 0" class="rounded p-1 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" title="Move up">↑</button>
                                <button type="button" @click="briefMove(i, 1)" :disabled="i === b.brief.sections.length - 1" class="rounded p-1 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" title="Move down">↓</button>
                                <button type="button" @click="briefRemove(i)" class="rounded p-1 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/30" title="Remove">✕</button>
                            </div>
                        </div>
                        <ul class="mt-2 space-y-1 pl-7" x-show="s.subtopics.length > 0">
                            <template x-for="(sub, j) in s.subtopics" :key="j">
                                <li class="flex items-center gap-1.5">
                                    <input type="text" :value="sub" @input="briefRenameSub(i, j, $event.target.value)"
                                        class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                    <button type="button" @click="briefRemoveSub(i, j)" class="rounded p-1 text-slate-400 hover:text-red-500">✕</button>
                                </li>
                            </template>
                        </ul>
                        <button type="button" @click="briefAddSub(i)" class="mt-2 ml-7 text-[11px] font-semibold text-indigo-600 hover:underline dark:text-indigo-400">+ Add subtopic</button>
                    </li>
                </template>
            </ul>

            <button type="button" @click="briefAddSection()" class="mt-3 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800">+ Add section</button>

            <div class="mt-4 space-y-2" x-show="b.brief.paa.length > 0 || b.brief.gaps.length > 0">
                <details open x-show="b.brief.paa.length > 0" class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <summary class="cursor-pointer font-semibold text-slate-700 dark:text-slate-200">People also ask (<span x-text="b.brief.paa.length"></span>)</summary>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600 dark:text-slate-300"><template x-for="(q, i) in b.brief.paa" :key="i"><li x-text="q"></li></template></ul>
                </details>
                <details x-show="b.brief.gaps.length > 0" class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <summary class="cursor-pointer font-semibold text-slate-700 dark:text-slate-200">Topical gaps vs. top SERP (<span x-text="b.brief.gaps.length"></span>)</summary>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600 dark:text-slate-300"><template x-for="(g, i) in b.brief.gaps" :key="i"><li x-text="g"></li></template></ul>
                </details>
            </div>
        </div>

        {{-- AI chat --}}
        <aside class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-800/40">
            <div class="mb-2">
                <strong class="text-sm text-slate-800 dark:text-slate-100">Edit with AI</strong>
                <span class="block text-[11px] text-slate-500 dark:text-slate-400">Ask in plain English</span>
            </div>
            <ol class="mb-2 max-h-80 space-y-2 overflow-y-auto">
                <template x-if="b.chatHistory.length === 0">
                    <li class="rounded-lg bg-white p-2 text-[11px] text-slate-400 dark:bg-slate-900">Try: "drop the supplements section", "rename section 3 to dosage and timing", or "add a section comparing powder vs shake".</li>
                </template>
                <template x-for="(m, i) in b.chatHistory" :key="i">
                    <li class="flex gap-2 text-xs" :class="m.role === 'user' ? 'justify-end' : ''">
                        <span class="rounded-lg px-2.5 py-1.5" :class="m.role === 'assistant' ? 'bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-200' : 'bg-indigo-600 text-white'" x-text="m.content"></span>
                    </li>
                </template>
            </ol>
            <div class="flex items-end gap-1.5">
                <textarea x-model="b.chatInput" @keydown.enter.prevent="briefSendChat()" rows="2" :disabled="b.chatBusy" placeholder="Tell the AI how to refine the brief…"
                    class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                <button type="button" @click="briefSendChat()" :disabled="b.chatBusy || b.chatInput.trim().length === 0"
                    class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" x-text="b.chatBusy ? '…' : 'Send'"></button>
            </div>
        </aside>
    </div>

    <template x-if="b.error"><div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="b.error"></div></template>

    <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
        <button type="button" @click="goToStep('topic')" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400">← Back</button>
        <button type="button" @click="goToStep('strategy')" :disabled="b.brief.sections.length === 0" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700">Next: strategy →</button>
    </div>
</div>
