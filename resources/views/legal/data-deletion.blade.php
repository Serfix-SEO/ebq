<x-marketing.page
    title="Data Deletion — Serfix"
    description="How to request deletion of your data from Serfix, including data connected through Facebook, X and Pinterest social integrations."
>
    <article class="bg-white">
        <header class="border-b border-slate-200">
            <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Legal') }}</p>
                <h1 class="mt-3 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">{{ __('Data Deletion Instructions') }}</h1>
                <p class="mt-3 text-sm text-slate-500">{{ __('Last updated') }}: {{ \Illuminate\Support\Carbon::create(2026, 9, 7)->format('F j, Y') }}</p>
                <p class="mt-6 text-[16px] leading-7 text-slate-600">
                    You can remove your data from Serfix at any time. This page explains what we store, how to disconnect a social account, and how to request full deletion.
                </p>
            </div>
        </header>

        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="prose prose-slate max-w-none prose-headings:tracking-tight prose-h2:mt-12 prose-h2:text-xl prose-h2:font-semibold prose-h2:text-slate-900 prose-p:text-slate-600 prose-li:text-slate-600 prose-strong:text-slate-900 prose-a:text-slate-900 prose-a:underline-offset-2">

                <h2>1. What we store from connected social accounts</h2>
                <p>When you connect a Facebook Page, X account or Pinterest account to Serfix's social auto-share, we store only what is needed to publish on your behalf:</p>
                <ul>
                    <li>An access token for the connected account or Page (stored encrypted).</li>
                    <li>The account or Page name and identifier, so you can see what's connected.</li>
                    <li>A record of which of your own articles were shared and when.</li>
                </ul>
                <p>We do not read your feed, messages, followers or any other profile data, and we never post anything except the article links you configured Serfix to share.</p>

                <h2>2. Disconnect a social account (instant)</h2>
                <p>In your Serfix dashboard, open <strong>Content → Social sharing</strong> and click <strong>Disconnect</strong> on the account. This immediately and permanently deletes the stored access token and account identifiers from our systems. You can also revoke Serfix from the platform's own settings (for Facebook: <em>Settings &amp; privacy → Business integrations</em>) — posting stops either way.</p>

                <h2>3. Delete your Serfix account and all data</h2>
                <p>To delete your entire Serfix account — including websites, articles, reports, and any connected social account data — email <a href="mailto:privacy@serfix.io">privacy@serfix.io</a> from the email address on the account with the subject "Delete my account". We will delete your data within 30 days and confirm by email. Data we are legally required to keep (e.g. invoices for tax law) is retained only as long as the law requires.</p>

                <h2>4. Facebook data deletion requests</h2>
                <p>If you arrived here from Facebook's "Data Deletion Instructions" link: disconnecting the Facebook Page in <strong>Content → Social sharing</strong> (§2) removes everything Serfix stored from Facebook. For a full account deletion, use §3. If you have any trouble, email <a href="mailto:privacy@serfix.io">privacy@serfix.io</a> and we'll handle it for you.</p>

                <h2>5. Questions</h2>
                <p>Anything unclear, or want confirmation of a deletion? Contact <a href="mailto:privacy@serfix.io">privacy@serfix.io</a>. Our broader data practices are described in the <a href="{{ route('privacy-policy') }}">Privacy Policy</a>.</p>
            </div>
        </div>
    </article>
</x-marketing.page>
