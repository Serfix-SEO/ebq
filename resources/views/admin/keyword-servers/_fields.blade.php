@php
    /** @var \App\Models\KeywordApiServer|null $server */
    $isEdit = $server !== null;
@endphp
<div class="grid gap-3 md:grid-cols-2">
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">Name</span>
        <input type="text" name="name" required value="{{ old('name', $server->name ?? '') }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">Base URL (IP / host)</span>
        <input type="url" name="base_url" required placeholder="http://1.2.3.4:3000" value="{{ old('base_url', $server->base_url ?? '') }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">API key {{ $isEdit ? '(leave blank to keep current)' : '' }}</span>
        <input type="text" name="api_key" autocomplete="off" {{ $isEdit ? '' : 'required' }} placeholder="{{ $isEdit ? '••••••••' : '' }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">Webhook secret {{ $isEdit ? '(leave blank to keep current)' : '' }}</span>
        <input type="text" name="webhook_secret" autocomplete="off" {{ $isEdit ? '' : 'required' }} placeholder="{{ $isEdit ? '••••••••' : '' }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">Default location</span>
        <input type="text" name="default_location" value="{{ old('default_location', $server->default_location ?? 'United States') }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">Default language</span>
        <input type="text" name="default_language" value="{{ old('default_language', $server->default_language ?? 'English') }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-medium">Weight (higher = preferred on ties)</span>
        <input type="number" name="weight" min="1" max="1000" value="{{ old('weight', $server->weight ?? 1) }}"
               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
    </label>
    <label class="flex items-center gap-2 self-end text-xs text-slate-700">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $server->is_active ?? true))
               class="rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
        <span class="font-medium">Active (eligible for load balancing)</span>
    </label>
</div>
@foreach (['name','base_url','api_key','webhook_secret','default_location','default_language','weight'] as $f)
    @error($f) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
@endforeach
