{{-- Inline edit-access form used for both members and pending invitations. --}}
<div class="mt-3 rounded-lg border border-orange-200 bg-orange-50/50 p-3 dark:border-orange-500/30 dark:bg-orange-500/5">
    <div class="mb-3 flex items-center justify-between">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-orange-700 dark:text-orange-300">Edit access</div>
        <button type="button" wire:click="cancelEdit" class="text-[10px] text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Close</button>
    </div>

    <div class="mb-3">
        <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Role</label>
        <select wire:model.live="editRole" class="h-8 w-full max-w-xs rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="member">Member (custom access)</option>
            <option value="admin">Admin (full access)</option>
        </select>
    </div>

    @if ($editRole === 'member')
        <div class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
            <div class="mb-2 flex items-center justify-between">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Feature access</div>
                <div class="flex items-center gap-2 text-[10px]">
                    <button type="button" wire:click="$set('editPermissions', {{ json_encode(array_fill_keys(array_keys($features), true)) }})" class="text-orange-600 hover:underline">Select all</button>
                    <span class="text-slate-300">·</span>
                    <button type="button" wire:click="$set('editPermissions', {{ json_encode(array_fill_keys(array_keys($features), false)) }})" class="text-slate-500 hover:underline">Clear</button>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as $key => $feature)
                    <label class="flex cursor-pointer items-start gap-2 rounded-md border border-transparent p-2 transition hover:border-slate-200 hover:bg-slate-50 dark:hover:border-slate-700 dark:hover:bg-slate-800/60">
                        <input type="checkbox" wire:model="editPermissions.{{ $key }}" class="mt-0.5 rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
                        <div class="min-w-0">
                            <div class="text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $feature['label'] }}</div>
                            <div class="text-[10px] text-slate-500 dark:text-slate-400">{{ $feature['description'] }}</div>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>
    @else
        <p class="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
            <svg class="h-3.5 w-3.5 text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            Admins have full access to every feature and can manage the team.
        </p>
    @endif

    <div class="mt-3 flex items-center justify-end gap-2">
        <button type="button" wire:click="cancelEdit"
            class="h-8 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">Cancel</button>
        <button type="button" wire:click="saveEdit"
            wire:loading.attr="disabled"
            wire:target="saveEdit"
            class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm hover:bg-orange-700 disabled:opacity-60">
            <svg wire:loading wire:target="saveEdit" class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
            Save changes
        </button>
    </div>
</div>
