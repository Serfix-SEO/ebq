<x-layouts.app>
    @php
        /**
         * @var \App\Models\Plan $plan
         * @var bool             $isNew
         */
        $featuresText = is_array($plan->features) ? implode("\n", $plan->features) : '';
        $action = $isNew ? route('admin.plans.store') : route('admin.plans.update', $plan);
    @endphp

    <div class="space-y-6 max-w-3xl">
        <div class="flex items-center gap-2 text-xs text-slate-500">
            <a href="{{ route('admin.plans.index') }}" class="hover:underline">Plans</a>
            <span>›</span>
            <span>{{ $isNew ? 'New' : $plan->name }}</span>
        </div>

        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $isNew ? 'New plan' : 'Edit plan: '.$plan->name }}</h1>
            <p class="text-sm text-slate-500 mt-1">
                @if ($isNew)
                    Add a new pricing tier. After creating it, paste the Stripe price ID you generated for this product to enable checkout.
                @else
                    Update plan pricing, Stripe price IDs, trial days, or feature bullets. Changes propagate within 15 minutes (the public pricing API caches at edge for that long).
                @endif
            </p>
        </div>

        @if ($errors->any())
            <div class="rounded border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                <ul class="list-disc pl-5 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Slug — read-only after creation. Drives Stripe webhook routing + public API + WP plugin wizard. --}}
                <div class="sm:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Slug</label>
                    @if ($isNew)
                        <input type="text" name="slug" value="{{ old('slug', $plan->slug) }}" required
                               pattern="[a-z0-9_-]+"
                               class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono"
                               placeholder="pro" />
                        <p class="text-[11px] text-slate-500 mt-1">Lowercase, hyphens/underscores only. <strong>Cannot be changed</strong> later.</p>
                    @else
                        <input type="text" value="{{ $plan->slug }}" disabled readonly
                               class="w-full rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-500" />
                        <p class="text-[11px] text-slate-500 mt-1">Slug is immutable. Renaming would orphan running subscriptions and break Stripe webhook routing.</p>
                    @endif
                </div>

                <div class="sm:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Display order</label>
                    <input type="number" name="display_order" value="{{ old('display_order', $plan->display_order) }}" required
                           min="0" max="9999"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm" />
                    <p class="text-[11px] text-slate-500 mt-1">Left-to-right card order on /pricing. Lower = earlier.</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Name</label>
                    <input type="text" name="name" value="{{ old('name', $plan->name) }}" required maxlength="64"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                           placeholder="Pro" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Tagline (optional)</label>
                    <input type="text" name="tagline" value="{{ old('tagline', $plan->tagline) }}" maxlength="191"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                           placeholder="For agencies and growth teams." />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Monthly price (USD)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-2 flex items-center text-sm text-slate-400">$</span>
                        <input type="number" name="price_monthly_usd" value="{{ old('price_monthly_usd', $plan->price_monthly_usd) }}" required
                               min="0" max="99999"
                               class="w-full rounded border border-slate-300 pl-6 pr-3 py-2 text-sm font-mono" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Yearly price (USD, optional)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-2 flex items-center text-sm text-slate-400">$</span>
                        <input type="number" name="price_yearly_usd" value="{{ old('price_yearly_usd', $plan->price_yearly_usd) }}"
                               min="0" max="999999"
                               class="w-full rounded border border-slate-300 pl-6 pr-3 py-2 text-sm font-mono" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Trial days</label>
                    <input type="number" name="trial_days" value="{{ old('trial_days', $plan->trial_days) }}" required
                           min="0" max="365"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono" />
                    <p class="text-[11px] text-slate-500 mt-1">0 = no trial. Cashier passes this to Stripe.</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Max websites</label>
                    <input type="number" name="max_websites" value="{{ old('max_websites', $plan->max_websites) }}"
                           min="0" max="999"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono"
                           placeholder="leave blank for unlimited" />
                    <p class="text-[11px] text-slate-500 mt-1">
                        Leave blank for <strong>unlimited</strong>. The user's website count is enforced when they try to add a website; existing sites past the limit are <strong>frozen</strong> (read-only on EBQ and the WP plugin), not deleted, so a downgrade never destroys data.
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Stripe price ID (yearly)</label>
                <input type="text" name="stripe_price_id_yearly" value="{{ old('stripe_price_id_yearly', $plan->stripe_price_id_yearly) }}"
                       pattern="price_.*"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono"
                       placeholder="price_1AbCd…" />
                <p class="text-[11px] text-slate-500 mt-1">
                    EBQ only sells yearly subscriptions. Paste the Stripe yearly price ID — without it, the plan card on /pricing
                    grays out and the WordPress wizard hides the CTA. Monthly price above is display-only ("$X/mo, billed yearly").
                </p>
            </div>

            {{-- Hidden so PlanController::update() still receives the field even though we're hiding the input.
                 The monthly Stripe price ID is now legacy: never used to mint a subscription, but kept on the
                 row for historical Stripe Dashboard linkage. --}}
            <input type="hidden" name="stripe_price_id_monthly" value="{{ old('stripe_price_id_monthly', $plan->stripe_price_id_monthly) }}" />

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">Marketing bullets (one per line)</label>
                <textarea name="features" rows="8"
                          class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono"
                          placeholder="Everything in Pro&#10;5 websites, 250 tracked keywords&#10;500 page audits / month">{{ old('features', $featuresText) }}</textarea>
                <p class="text-[11px] text-slate-500 mt-1">
                    Free-text bullet points shown on /pricing and inside the WP plugin's setup wizard pricing step.
                    Display copy only — the <strong>Plugin features</strong> grid below is the authoritative entitlement matrix.
                </p>
            </div>

            {{-- Plugin entitlement matrix. Authoritative source of "which
                 of the 8 plugin feature flags does this plan unlock?".
                 The WP plugin reads this map through InjectFeatureFlags
                 middleware; per-website overrides can only narrow it,
                 never widen it. --}}
            @php
                $planFeatures = $plan->featureMap();
                $featureLabels = [
                    'chatbot'          => ['Chatbot', 'Floating "EBQ Assistant" panel inside the WordPress editor.'],
                    'ai_writer'        => ['AI Writer', 'Multi-step "Generate full draft from focus keyword" tool.'],
                    'ai_inline'        => ['AI inline edits', 'Block toolbar "EBQ AI" menu + // slash commands.'],
                    'live_audit'       => ['Live audit', 'Real-time SEO + readability scoring with GSC signals.'],
                    'hq'               => ['EBQ HQ admin page', 'Top-level "EBQ HQ" admin page with rank tracker, performance, pages, AI Studio.'],
                    'redirects'        => ['Redirects manager', '"EBQ Redirects" admin page with CSV import/export.'],
                    'dashboard_widget' => ['Dashboard widget', 'WP Dashboard "EBQ summary" widget on the home screen.'],
                    'post_column'      => ['Post list column', '"EBQ score" column in the wp-admin posts list.'],
                    'report_whitelabel' => ['Report whitelabel', 'Branded report emails + PDF attachment, plus Gmail/Outlook/SMTP send-as. Off = EBQ default branding and mailer.'],
                ];
            @endphp
            <div class="border-t border-slate-200 pt-4 space-y-3">
                <div>
                    <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wide">Plugin features</h3>
                    <p class="text-[11px] text-slate-500 mt-1">
                        Tick every feature this plan unlocks. <strong>Off here = off everywhere</strong>: per-site overrides
                        in <a href="{{ route('admin.website-features.index') }}" class="underline">Website features</a>
                        can still disable a flag this plan allows, but cannot enable a flag this plan disallows.
                    </p>
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($featureLabels as $key => [$label, $help])
                        @php
                            $checked = (bool) old('plan_features.'.$key, $planFeatures[$key] ?? false);
                        @endphp
                        <label class="flex items-start gap-2 rounded border border-slate-200 px-3 py-2 hover:border-slate-300">
                            <input type="checkbox" name="plan_features[{{ $key }}]" value="on" @checked($checked)
                                   class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600">
                            <span class="text-sm leading-tight">
                                <span class="font-medium text-slate-800">{{ $label }}</span>
                                <span class="block text-[11px] text-slate-500 mt-0.5">{{ $help }}</span>
                                <code class="mt-1 inline-block text-[10px] text-slate-400">{{ $key }}</code>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Per-plan API caps. Leave a field blank for unlimited.
                 Each input maps to a leaf in `plans.api_limits` JSON. --}}
            @php
                $apiLimits = is_array($plan->api_limits ?? null) ? $plan->api_limits : [];
                $ke  = (int) ($apiLimits['keywords_everywhere']['monthly_credits'] ?? 0) ?: '';
                $ser = (int) ($apiLimits['serper']['monthly_calls']                ?? 0) ?: '';
                $mis = (int) ($apiLimits['mistral']['monthly_tokens']              ?? 0) ?: '';
                $rt  = (int) ($apiLimits['rank_tracker']['max_active_keywords']    ?? 0) ?: '';
            @endphp
            <div class="border-t border-slate-200 pt-4 space-y-3">
                <div>
                    <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wide">API limits</h3>
                    <p class="text-[11px] text-slate-500 mt-1">
                        Per-user monthly caps. Leave blank for unlimited. Monthly windows reset on each user's
                        subscription anchor day. Rank tracker is a hard ceiling on active tracked keywords; users
                        must pause or remove keywords to free slots.
                    </p>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-700">Keywords Everywhere — credits / month</span>
                        <input type="number" min="0" name="api_limits[keywords_everywhere][monthly_credits]"
                               value="{{ old('api_limits.keywords_everywhere.monthly_credits', $ke) }}"
                               placeholder="Unlimited"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-700">Serper — calls / month</span>
                        <input type="number" min="0" name="api_limits[serper][monthly_calls]"
                               value="{{ old('api_limits.serper.monthly_calls', $ser) }}"
                               placeholder="Unlimited"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-700">Mistral — tokens / month (input + output)</span>
                        <input type="number" min="0" name="api_limits[mistral][monthly_tokens]"
                               value="{{ old('api_limits.mistral.monthly_tokens', $mis) }}"
                               placeholder="Unlimited"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-700">Rank tracker — max active keywords</span>
                        <input type="number" min="0" name="api_limits[rank_tracker][max_active_keywords]"
                               value="{{ old('api_limits.rank_tracker.max_active_keywords', $rt) }}"
                               placeholder="Unlimited"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-6 border-t border-slate-200 pt-4">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', (bool) $plan->is_active))
                           class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                    <span>Active <span class="text-xs text-slate-500">(visible on /pricing &amp; in the wizard)</span></span>
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="is_highlighted" value="1" @checked(old('is_highlighted', (bool) $plan->is_highlighted))
                           class="h-4 w-4 rounded border-slate-300 text-violet-600">
                    <span>Highlighted <span class="text-xs text-slate-500">(featured ring + "Most popular" badge)</span></span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('admin.plans.index') }}"
                   class="text-sm font-medium px-3 py-2 rounded-md text-slate-600 hover:bg-slate-100">
                    Cancel
                </a>
                <button type="submit"
                        class="text-sm font-medium px-4 py-2 rounded-md bg-indigo-600 text-white hover:bg-indigo-500">
                    {{ $isNew ? 'Create plan' : 'Save changes' }}
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
