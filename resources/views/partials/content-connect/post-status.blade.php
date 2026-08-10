{{-- Shared publish-mode select for token-based destinations (config.post_status). --}}
<div>
    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('When an article is ready') }}</label>
    <select wire:model="postStatus"
        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        <option value="publish">{{ __('Publish live') }}</option>
        <option value="draft">{{ __('Save as draft') }}</option>
    </select>
</div>
