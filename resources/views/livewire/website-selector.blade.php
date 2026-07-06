<div>
    @if (count($websites) > 1)
        <div class="relative">
            <select wire:model.live="websiteId"
                class="appearance-none rounded-lg border border-slate-200 bg-white py-2 pl-3 pr-8 text-sm font-medium text-slate-700 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                @foreach ($websites as $site)
                    <option value="{{ $site['id'] }}">{{ $site['domain'] }}</option>
                @endforeach
            </select>
        </div>
    @elseif (count($websites) === 1)
        <div class="flex items-center gap-2">
            <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ $websites[0]['domain'] }}</span>
        </div>
    @else
        <a href="{{ route('websites.index') }}" class="text-sm font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400">+ Add website</a>
    @endif
</div>
