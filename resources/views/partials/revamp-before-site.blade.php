{{-- Realistic dated "before" mini-site. Shared by the hero background (desktop)
     and the inline mobile preview so they always match. --}}
<div class="overflow-hidden rounded-xl border border-slate-300 bg-white text-left shadow-2xl">
    <div class="flex items-center gap-1 border-b border-slate-300 bg-slate-200 px-2.5 py-1.5">
        <span class="h-2 w-2 rounded-full bg-slate-400"></span>
        <span class="h-2 w-2 rounded-full bg-slate-400"></span>
        <span class="h-2 w-2 rounded-full bg-slate-400"></span>
        <span class="ml-1 rounded bg-white px-1.5 py-0.5 text-[6px] text-slate-400">smithplumbing.co</span>
    </div>
    <div class="bg-white">
        <div class="flex items-center justify-between border-b border-slate-200 px-3 py-1.5">
            <div>
                <p class="font-serif text-[11px] font-bold text-sky-900">{{ __('Smith & Co Plumbing') }}</p>
                <p class="text-[5px] uppercase tracking-wide text-slate-400">{{ __('Est. 1998 · Gas Safe Registered') }}</p>
            </div>
            <p class="text-[8px] font-bold text-slate-600">0123 456 789</p>
        </div>
        <div class="flex bg-gradient-to-b from-sky-700 to-sky-800 text-[6px] font-semibold text-sky-50">
            @foreach ([__('Home'), __('About'), __('Services'), __('Gallery'), __('Contact')] as $t)
                <span class="border-r border-sky-600/60 px-2 py-1">{{ $t }}</span>
            @endforeach
        </div>
        <div class="relative flex h-14 items-center justify-center bg-gradient-to-br from-slate-500 to-slate-700">
            <svg class="h-4 w-4 text-white/25" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M1.5 6a2.25 2.25 0 012.25-2.25h16.5A2.25 2.25 0 0122.5 6v12a2.25 2.25 0 01-2.25 2.25H3.75A2.25 2.25 0 011.5 18V6zM3 16.06V18c0 .414.336.75.75.75h16.5A.75.75 0 0021 18v-1.94l-2.69-2.689a1.5 1.5 0 00-2.12 0l-.88.879.97.97a.75.75 0 11-1.06 1.06l-5.16-5.159a1.5 1.5 0 00-2.12 0L3 16.061zm10.125-7.81a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0z" clip-rule="evenodd" /></svg>
            <div class="absolute inset-x-0 bottom-0 bg-black/60 px-2 py-0.5">
                <p class="text-[6px] font-bold text-white">{{ __('Quality Plumbing You Can Trust') }}</p>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-2 px-3 py-2">
            <div class="col-span-2">
                <p class="text-[7px] font-bold text-sky-900">{{ __('Welcome to our website') }}</p>
                <div class="mt-1 space-y-1">
                    <div class="h-1 w-full rounded-full bg-slate-200"></div>
                    <div class="h-1 w-full rounded-full bg-slate-200"></div>
                    <div class="h-1 w-5/6 rounded-full bg-slate-200"></div>
                    <div class="h-1 w-11/12 rounded-full bg-slate-200"></div>
                </div>
            </div>
            <div class="col-span-1 rounded border border-slate-200 bg-slate-50 p-1.5">
                <p class="text-[5.5px] font-bold text-slate-600">{{ __('Why Choose Us?') }}</p>
                <div class="mt-1 space-y-0.5">
                    <div class="h-0.5 w-full rounded-full bg-slate-200"></div>
                    <div class="h-0.5 w-full rounded-full bg-slate-200"></div>
                    <div class="h-0.5 w-3/4 rounded-full bg-slate-200"></div>
                </div>
                <p class="mt-1 text-[5.5px] text-sky-700 underline">{{ __('Get a Quote »') }}</p>
            </div>
        </div>
        <div class="bg-slate-700 px-3 py-1"><p class="text-[5px] text-slate-300">{{ __('© 2012 Smith & Co Plumbing Ltd · Manchester') }}</p></div>
    </div>
</div>
