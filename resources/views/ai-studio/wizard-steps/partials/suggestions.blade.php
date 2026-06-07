{{-- AI-suggestion popover for a meta field.
     $field = project field to write (e.g. 'meta_title')
     $key   = suggestions array on the project (e.g. 'seo_titles') --}}
<div class="mt-1.5" x-show="suggestionsFor('{{ $key }}').length > 0">
    <button type="button" @click="toggleSugg('{{ $key }}')" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">
        💡 <span x-text="suggestionsFor('{{ $key }}').length + ' suggestions'"></span>
        <span x-text="s.suggOpen['{{ $key }}'] ? '▴' : '▾'"></span>
    </button>
    <div x-show="s.suggOpen['{{ $key }}']" @click.outside="s.suggOpen['{{ $key }}'] = false" class="mt-1 rounded-lg border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <p class="px-1 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Pick one to replace the field</p>
        <ul class="space-y-0.5">
            <template x-for="(sg, i) in suggestionsFor('{{ $key }}')" :key="i">
                <li>
                    <button type="button" @click="pickSuggestion('{{ $field }}', sg)" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs transition hover:bg-slate-50 dark:hover:bg-slate-700"
                        :class="sg === (s.drafts['{{ $field }}'] || '').trim() ? 'bg-indigo-50 dark:bg-indigo-500/10' : ''">
                        <span class="inline-flex h-4 w-4 flex-none items-center justify-center rounded-full bg-slate-100 text-[9px] font-semibold text-slate-500 dark:bg-slate-700" x-text="i + 1"></span>
                        <span class="min-w-0 flex-1 text-slate-700 dark:text-slate-200" x-text="sg"></span>
                        <span class="text-[10px] text-slate-400" x-text="sg.length"></span>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</div>
