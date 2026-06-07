{{-- Step 4 — Images (optional). Serper image search; selections placed
     under the best-matching H2 at generation time. The WP plugin's
     media-library upload has no dashboard analogue and is omitted. --}}
<div x-show="surface() === 'images'" class="space-y-5">
    <div>
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Add images (optional)</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pick up to 6 images. We'll position each under the section it best matches when the post is generated. Skip if you don't need any.</p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input type="text" x-model="im.query" @keydown.enter.prevent="imageSearch()" placeholder="Search image keywords…"
            class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        <button type="button" @click="imageSearch()" :disabled="im.searching || im.query.trim().length < 2" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" x-text="im.searching ? 'Searching…' : 'Search'"></button>
        <span class="text-xs text-slate-500 dark:text-slate-400">
            <span x-text="im.selected.length"></span>/<span x-text="MAX_IMAGES"></span> selected
            <span x-show="im.savingDirty"> · Saving…</span>
        </span>
    </div>

    <template x-if="im.error"><div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="im.error"></div></template>

    <div x-show="im.results.length > 0" class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
        <template x-for="img in im.results" :key="img.url">
            <button type="button" @click="toggleImage(img)" class="relative aspect-square overflow-hidden rounded-lg border-2 transition" :class="imgSelected(img.url) ? 'border-indigo-500' : 'border-transparent hover:border-slate-300'">
                <img :src="img.thumbnail_url || img.url" :alt="img.title || ''" loading="lazy" class="h-full w-full object-cover">
                <span x-show="imgSelected(img.url)" class="absolute right-1 top-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-xs text-white">✓</span>
            </button>
        </template>
    </div>
    <p x-show="im.results.length === 0 && !im.searching" class="rounded-lg bg-slate-50 p-4 text-center text-xs text-slate-400 dark:bg-slate-800/60">No image results yet — try a different keyword.</p>

    <div x-show="im.selected.length > 0">
        <h4 class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Selected images</h4>
        <ul class="space-y-2">
            <template x-for="(img, i) in im.selected" :key="img.url + i">
                <li class="flex flex-col gap-3 rounded-lg border border-slate-200 p-3 sm:flex-row sm:items-start dark:border-slate-800">
                    <img :src="img.thumbnail_url || img.url" :alt="img.alt || ''" class="h-16 w-16 flex-none rounded object-cover">
                    <div class="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-3">
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Alt text
                            <input type="text" :value="img.alt || ''" @input="imgUpdateField(i, 'alt', $event.target.value)" class="mt-0.5 w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        </label>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Caption
                            <input type="text" :value="img.caption || ''" @input="imgUpdateField(i, 'caption', $event.target.value)" class="mt-0.5 w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        </label>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Place under section
                            <select :value="img.assigned_h2 || ''" @change="imgUpdateField(i, 'assigned_h2', $event.target.value || null)" class="mt-0.5 w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                <option value="">Auto (best match)</option>
                                <template x-for="(h2, j) in briefSectionTitles()" :key="j"><option :value="h2" x-text="h2"></option></template>
                            </select>
                        </label>
                    </div>
                    <button type="button" @click="imgRemove(i)" class="self-start rounded p-1 text-slate-400 hover:text-red-500" title="Remove">✕</button>
                </li>
            </template>
        </ul>
    </div>

    <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
        <button type="button" @click="goToStep('strategy')" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400">← Back</button>
        <div class="flex items-center gap-2">
            <button type="button" @click="goToStep('summary')" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Skip</button>
            <button type="button" @click="goToStep('summary')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Next: review →</button>
        </div>
    </div>
</div>
