<x-marketing.page
    title="Contact — Serfix"
    description="Contact Serfix for support, demos, and partnership questions."
    active="contact"
>
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-3xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Contact') }}</p>
                <h1 class="mt-4 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                    {{ __('Talk to the Serfix team.') }}
                </h1>
                <p class="mt-5 text-balance text-[17px] leading-8 text-slate-600">
                    {{ __('Questions about setup, pricing, onboarding, or partnerships? We reply quickly and directly.') }}
                </p>
            </div>

            <div class="mx-auto mt-12 grid max-w-4xl gap-6 md:grid-cols-3">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 md:col-span-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Support') }}</p>
                    <h2 class="mt-2 text-lg font-semibold text-slate-900">support@serfix.io</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('For all product questions, billing, technical issues, and troubleshooting.') }}</p>
                    <a href="mailto:support@serfix.io" class="mt-4 inline-flex text-sm font-semibold text-slate-900 underline underline-offset-2">{{ __('Get support') }}</a>
                </article>
            </div>

            <div class="mx-auto mt-12 max-w-4xl rounded-2xl border border-slate-200 bg-slate-50/70 p-8">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Response times') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 text-center">
                        <p class="text-sm font-semibold text-slate-900">&lt; {{ __('1 business day') }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ __('General replies') }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 text-center">
                        <p class="text-sm font-semibold text-slate-900">{{ __('Priority queue') }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Pro and Agency support') }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 text-center">
                        <p class="text-sm font-semibold text-slate-900">{{ __('Weekly demo slots') }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Product walkthroughs') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-marketing.page>
