{{-- Modern, conversion-focused "after" mini-site. Shared by the hero background
     (desktop) and the inline mobile preview so they always match. --}}
<div class="overflow-hidden rounded-xl border border-slate-200 bg-white text-left shadow-2xl">
    <div class="flex items-center gap-1 border-b border-slate-200 bg-slate-100 px-2.5 py-1.5">
        <span class="h-2 w-2 rounded-full bg-slate-300"></span>
        <span class="h-2 w-2 rounded-full bg-slate-300"></span>
        <span class="h-2 w-2 rounded-full bg-slate-300"></span>
        <span class="ml-1 rounded bg-white px-1.5 py-0.5 text-[6px] text-slate-400">smithplumbing.co</span>
    </div>
    <div class="bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2">
            <span class="text-[10px] font-extrabold tracking-tight text-slate-900">Smith&nbsp;&amp;&nbsp;Co<span class="text-orange-600">.</span></span>
            <div class="flex items-center gap-1.5 text-[6px] font-medium text-slate-500">
                <span>Services</span><span>Reviews</span><span>Contact</span>
                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-[6px] font-semibold text-white">Call now</span>
            </div>
        </div>
        <div class="bg-gradient-to-b from-orange-50 to-white px-3 py-3 text-center">
            <span class="text-[5.5px] font-semibold text-emerald-700">★★★★★ 480+ reviews · Licensed</span>
            <p class="mt-1 text-[11px] font-extrabold leading-tight tracking-tight text-slate-900">Emergency Plumber, Fixed Today</p>
            <p class="mx-auto mt-1 max-w-[11rem] text-[6px] leading-relaxed text-slate-600">No call-out fee. A qualified engineer at your door within 60 minutes.</p>
            <div class="mt-2 flex items-center justify-center gap-1.5">
                <span class="rounded bg-slate-900 px-2.5 py-1 text-[6px] font-semibold text-white">Get a Free Quote</span>
                <span class="rounded border border-slate-300 px-2.5 py-1 text-[6px] font-semibold text-slate-800">Call now</span>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-1.5 px-3 pb-2.5">
            @foreach (['24/7 Emergency', 'Fixed Price', '12-Mo Guarantee'] as $c)
                <div class="rounded border border-slate-200 bg-white p-1 text-center shadow-sm">
                    <div class="mx-auto h-2 w-2 rounded-full bg-orange-600"></div>
                    <p class="mt-0.5 text-[5px] font-bold text-slate-700">{{ $c }}</p>
                </div>
            @endforeach
        </div>
    </div>
</div>
