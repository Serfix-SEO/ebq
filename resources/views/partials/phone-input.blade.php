@php
    $codes = \App\Support\DialCodes::all();
    $sel = old('dial_code', '+1');
    $uid = 'ph'.\Illuminate\Support\Str::random(6);
    $selRow = collect($codes)->firstWhere('dial', $sel) ?? $codes[0];
@endphp
<div class="flex gap-2">
    <div class="relative w-28 flex-none" data-phone-cc="{{ $uid }}">
        <input type="hidden" name="dial_code" value="{{ $sel }}" id="{{ $uid }}_code">
        <button type="button" id="{{ $uid }}_btn"
            class="flex w-full items-center justify-between gap-1 rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            <span id="{{ $uid }}_lbl">{{ $selRow['flag'] }} {{ $selRow['dial'] }}</span>
            <svg class="h-4 w-4 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="{{ $uid }}_panel" class="absolute left-0 z-20 mt-1 hidden w-64 rounded-lg border border-slate-200 bg-white shadow-lg">
            <input type="text" id="{{ $uid }}_search" placeholder="Search country" autocomplete="off"
                class="w-full rounded-t-lg border-b border-slate-200 px-3 py-2 text-sm focus:outline-none">
            <ul id="{{ $uid }}_list" class="max-h-56 overflow-auto py-1">
                @foreach ($codes as $c)
                    <li>
                        <button type="button" data-dial="{{ $c['dial'] }}" data-label="{{ $c['flag'] }} {{ $c['dial'] }}" data-search="{{ strtolower($c['name']).' '.$c['dial'] }}"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-slate-50">
                            <span class="flex-none">{{ $c['flag'] }}</span>
                            <span class="flex-1 truncate text-slate-700">{{ $c['name'] }}</span>
                            <span class="flex-none text-slate-400">{{ $c['dial'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    <input type="tel" name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel-national"
        placeholder="Phone number (optional)"
        class="block w-full flex-1 rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
</div>
@error('phone')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
@error('dial_code')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
<script>
(function () {
    var root = document.querySelector('[data-phone-cc="{{ $uid }}"]');
    if (!root) return;
    var btn = document.getElementById('{{ $uid }}_btn');
    var panel = document.getElementById('{{ $uid }}_panel');
    var search = document.getElementById('{{ $uid }}_search');
    var code = document.getElementById('{{ $uid }}_code');
    var lbl = document.getElementById('{{ $uid }}_lbl');
    var items = Array.prototype.slice.call(document.querySelectorAll('#{{ $uid }}_list button'));

    function open() { panel.classList.remove('hidden'); search.value = ''; filter(''); search.focus(); }
    function close() { panel.classList.add('hidden'); }
    function filter(q) {
        q = q.toLowerCase();
        items.forEach(function (it) {
            it.parentElement.style.display = it.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
        });
    }
    btn.addEventListener('click', function (e) { e.stopPropagation(); panel.classList.contains('hidden') ? open() : close(); });
    search.addEventListener('input', function () { filter(search.value); });
    items.forEach(function (it) {
        it.addEventListener('click', function () {
            code.value = it.getAttribute('data-dial');
            lbl.textContent = it.getAttribute('data-label');
            close();
        });
    });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });
})();
</script>
