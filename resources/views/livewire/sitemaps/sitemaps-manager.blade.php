<div class="space-y-5" wire:init="autoSync">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-lg font-bold tracking-tight">Sitemaps</h1>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Add and track the XML sitemaps for this website</p>
        </div>
        <button type="button" wire:click="syncFromGsc" wire:loading.attr="disabled" wire:target="syncFromGsc,autoSync"
            class="inline-flex h-8 items-center gap-1.5 rounded-md bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 disabled:opacity-60 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700">
            <svg wire:loading.remove wire:target="syncFromGsc,autoSync" class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            <svg wire:loading wire:target="syncFromGsc,autoSync" class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Sync from GSC
        </button>
    </div>

    @if ($status)
        <div class="flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
            <svg class="h-3.5 w-3.5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            {{ $status }}
        </div>
    @endif

    @unless ($hasGsc)
        <div class="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <svg class="mt-0.5 h-3.5 w-3.5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
            <span>No Google Search Console source is connected, so sitemaps can't be auto-fetched. You can still add and track sitemaps manually below.</span>
        </div>
    @endunless

    {{-- Add sitemap form --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <form wire:submit="addSitemap" class="flex flex-col gap-2 sm:flex-row sm:items-start">
            <div class="flex-1">
                <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">Sitemap URL</label>
                <input wire:model="newSitemapUrl" type="url" placeholder="https://example.com/sitemap.xml"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800" />
                @error('newSitemapUrl') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="sm:pt-[22px]">
                <button type="submit"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-indigo-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add Sitemap
                </button>
            </div>
        </form>
    </div>

    {{-- Sitemaps table --}}
    @if ($sitemaps->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-200 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Sitemap</th>
                            <th class="px-4 py-2.5 font-medium">Source</th>
                            <th class="px-4 py-2.5 text-right font-medium">URLs (indexed/submitted)</th>
                            <th class="px-4 py-2.5 text-right font-medium">Issues</th>
                            <th class="px-4 py-2.5 font-medium">Last downloaded</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($sitemaps as $sitemap)
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ $sitemap->path }}</span>
                                    @if ($sitemap->is_sitemaps_index)
                                        <span class="ml-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">index</span>
                                    @endif
                                    @if ($sitemap->is_pending)
                                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">pending</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($sitemap->isFromGsc())
                                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Search Console</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Added manually</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    @if ($sitemap->submitted_urls !== null)
                                        {{ number_format((int) $sitemap->indexed_urls) }} / {{ number_format((int) $sitemap->submitted_urls) }}
                                    @else
                                        <span class="text-slate-300 dark:text-slate-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    @if ($sitemap->errors > 0)
                                        <span class="text-red-600 dark:text-red-400">{{ $sitemap->errors }} err</span>
                                    @endif
                                    @if ($sitemap->warnings > 0)
                                        <span class="ml-1 text-amber-600 dark:text-amber-400">{{ $sitemap->warnings }} warn</span>
                                    @endif
                                    @if ($sitemap->errors === 0 && $sitemap->warnings === 0)
                                        <span class="text-slate-300 dark:text-slate-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-500 dark:text-slate-400">
                                    {{ $sitemap->last_downloaded_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <button type="button" wire:click="removeSitemap('{{ $sitemap->id }}')"
                                        wire:confirm="Remove this sitemap from EBQ? (It is not deleted from Google.)"
                                        class="text-slate-400 transition hover:text-red-600 dark:hover:text-red-400" title="Remove">
                                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-slate-200 bg-white px-4 py-10 text-center dark:border-slate-700 dark:bg-slate-900">
            <p class="text-sm text-slate-500 dark:text-slate-400">No sitemaps yet.</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Add one above{{ $hasGsc ? ', or sync from Google Search Console' : '' }}.</p>
        </div>
    @endif
</div>
