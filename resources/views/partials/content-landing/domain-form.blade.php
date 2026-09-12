{{-- Domain capture — the page's only conversion action, rendered twice (hero
     and closing CTA).

     The reCAPTCHA widget renders on the HERO form only ($withRecaptcha).
     Google's api.js binds `.g-recaptcha` divs in document order and a second
     widget on the same page needs an explicit programmatic render; a plain
     duplicate div silently fails to bind, so the closing form would post an
     empty token and the visitor would be bounced with a validation error they
     cannot fix. When reCAPTCHA is on, the closing section links to #start
     instead of rendering a second form (see cta.blade.php). --}}
@php
    $withRecaptcha = $withRecaptcha ?? false;
    $buttonLabel = $buttonLabel ?? __('Analyze My Website');
    $formClass = $formClass ?? '';
    $centered = $centered ?? false;
@endphp
<form method="POST" action="{{ route('content.onboarding.begin') }}" class="{{ $formClass }}">
    @csrf
    <div class="flex flex-col gap-2.5 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/5 sm:flex-row sm:items-center sm:rounded-full sm:p-2">
        <div class="flex flex-1 items-center gap-2.5 ps-3">
            <svg class="h-5 w-5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8" /></svg>
            <input type="text" name="domain" value="{{ old('domain') }}" placeholder="{{ __('Enter your website') }}" aria-label="{{ __('Your website') }}"
                required inputmode="url" autocomplete="url"
                class="w-full border-0 bg-transparent py-3 text-[15px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" />
        </div>
        <button type="submit" class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-orange-500/40">
            {{ $buttonLabel }}
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
        </button>
    </div>
    @error('domain') <p class="mt-2.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
    @error('g-recaptcha-response') <p class="mt-2.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
    @if ($recaptcha && $withRecaptcha)
        <div class="mt-4 flex {{ $centered ? 'justify-center' : 'justify-center lg:justify-start' }}"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
    @endif
</form>
