<div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Report Recipients</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Choose who receives reports for
            <span class="font-medium text-slate-700 dark:text-slate-300">{{ $website?->domain ?? 'the selected website' }}</span>.
        </p>
    </div>

    <div class="px-5 py-4">
        @if (! $website)
            <p class="text-xs text-slate-400 dark:text-slate-500">Select a website from the top bar first.</p>
        @elseif (! $isOwner)
            <p class="text-xs text-slate-400 dark:text-slate-500">Only the website owner can change recipients.</p>
        @elseif ($team->isEmpty())
            <p class="text-xs text-slate-400 dark:text-slate-500">No team members found.</p>
        @else
            <div class="space-y-2">
                @foreach ($team as $member)
                    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-slate-100 px-3 py-2.5 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                        <input type="checkbox"
                            wire:model.live="selected.{{ $member->id }}"
                            class="h-3.5 w-3.5 rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-800">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium text-slate-900 dark:text-slate-100">
                                {{ $member->name }}
                                @if ($member->id === $website->user_id)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-orange-50 px-1.5 py-px text-[10px] font-semibold text-orange-600 ring-1 ring-orange-500/20 ring-inset dark:bg-orange-500/10 dark:text-orange-400">Owner</span>
                                @endif
                            </p>
                            <p class="truncate text-[11px] text-slate-500 dark:text-slate-400">{{ $member->email }}</p>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="mt-3 flex items-center gap-3">
                <button type="button" wire:click="save"
                    class="inline-flex h-8 items-center rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                    Save Recipients
                </button>
                @if ($saved)
                    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400" wire:transition>Saved</span>
                @endif
            </div>

            <p class="mt-2.5 text-[11px] text-slate-400 dark:text-slate-500">
                If none selected, reports go to the owner.
                Add members on the <a href="{{ route('team.index') }}" class="underline hover:text-slate-600 dark:hover:text-slate-300">Team page</a>.
            </p>
        @endif
    </div>
</div>
