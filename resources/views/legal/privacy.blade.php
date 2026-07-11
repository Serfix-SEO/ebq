<x-marketing.page
    title="Privacy Policy — Serfix"
    description="How Serfix collects, uses, and protects personal data, including Google user data accessed via Search Console, Analytics, and the Indexing API."
>
    <article class="bg-white">
        <header class="border-b border-slate-200">
            <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Legal') }}</p>
                <h1 class="mt-3 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">{{ __('Privacy Policy') }}</h1>
                <p class="mt-3 text-sm text-slate-500">{{ __('Last updated') }}: {{ \Illuminate\Support\Carbon::create(2026, 4, 30)->format('F j, Y') }}</p>
                <p class="mt-6 text-[16px] leading-7 text-slate-600">
                    This policy explains what information Serfix collects, why we process it, how we share and protect it, and how you can exercise your privacy rights. It applies to the Serfix web application at serfix.io and the Serfix SEO WordPress plugin when connected to a Serfix workspace.
                </p>
            </div>
        </header>

        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
            {{-- prose-code:break-all: OAuth-scope URLs in <code> have no break
     opportunities and forced sideways scroll on phones. --}}
<div class="prose prose-slate max-w-none prose-headings:tracking-tight prose-h2:mt-12 prose-h2:text-xl prose-h2:font-semibold prose-h2:text-slate-900 prose-h3:mt-8 prose-h3:text-base prose-h3:font-semibold prose-h3:text-slate-900 prose-p:text-slate-600 prose-li:text-slate-600 prose-a:text-slate-900 prose-a:underline-offset-2 prose-code:break-all">

                <h2>1. Data we collect</h2>

                <h3>1.1 Account data</h3>
                <ul>
                    <li>Name and email address you provide on registration.</li>
                    <li>Encrypted password hash (or, if you sign in with Google, your Google account email and Google user ID for authentication only).</li>
                    <li>Workspace metadata you create: website domains, team members, plan tier, settings.</li>
                </ul>

                <h3>1.2 Google user data</h3>
                <p>
                    When you connect a Google account, Serfix accesses Google user data using the OAuth scopes listed below. We request these scopes only after you click "Connect Google" and review Google's consent screen. You can revoke access at any time from <a href="https://myaccount.google.com/permissions" rel="noopener noreferrer">your Google account permissions page</a>; once revoked, Serfix stops fetching new data and removes the stored OAuth tokens.
                </p>
                <ul>
                    <li>
                        <strong>Google Search Console</strong> &mdash; scope <code>https://www.googleapis.com/auth/webmasters.readonly</code>.
                        Read-only access to: site list and site verification status; search analytics (queries, pages, clicks, impressions, click-through rate, average position) for properties you select; URL inspection / indexing status; sitemap submission state; mobile-usability and Core Web Vitals reports exposed via the API.
                    </li>
                    <li>
                        <strong>Google Analytics 4</strong> &mdash; scope <code>https://www.googleapis.com/auth/analytics.readonly</code>.
                        Read-only access to: GA4 property list and property metadata; aggregated reporting data (sessions, users, engaged sessions, conversions, traffic source/medium, landing page) for properties you select. Serfix does not access individual visitor profiles, user-level identifiers, or any data that can identify an end visitor.
                    </li>
                    <li>
                        <strong>Google Indexing API</strong> &mdash; scope <code>https://www.googleapis.com/auth/indexing</code>.
                        Permission to submit "URL_UPDATED" / "URL_DELETED" notifications for URLs on properties you've verified, so Serfix can request re-crawl after content changes (the "Quick-submit" feature on Pro plans). Serfix does not read any data via this scope; it only sends notifications.
                    </li>
                    <li>
                        <strong>Google account profile</strong> &mdash; basic email and unique user ID returned during OAuth, used to link the Google connection to your Serfix account.
                    </li>
                </ul>

                <h3>1.3 Operational telemetry</h3>
                <ul>
                    <li>IP address, user-agent string, and request timestamps in server access logs.</li>
                    <li>Application performance metrics (response times, error counts) for diagnostics.</li>
                    <li>Audit log of administrative actions (settings changes, billing changes, member invites).</li>
                </ul>

                <h3>1.4 Billing metadata</h3>
                <ul>
                    <li>Plan tier, subscription status, trial-end date, last-four card digits, card brand. We do <strong>not</strong> store full payment card numbers or CVV; payment is processed by Stripe under Stripe's privacy policy.</li>
                </ul>

                <h2>2. How we use data</h2>

                <h3>2.1 How we use Google user data</h3>
                <p>
                    Serfix's use of Google user data follows the <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener noreferrer">Google API Services User Data Policy</a>, including its <strong>Limited Use</strong> requirements. The specific purposes are:
                </p>
                <ul>
                    <li>
                        <strong>Search Console data</strong> is used to: render performance dashboards (clicks, impressions, CTR, position) inside your Serfix workspace and inside the connected WordPress plugin's editor sidebar; compute SEO recommendations (which queries you rank for, which pages drop, which keywords have the most opportunity); detect ranking drops and traffic anomalies for alert emails you've enabled; produce per-post insights surfaced when you edit a page in WordPress.
                    </li>
                    <li>
                        <strong>Analytics 4 data</strong> is used to: render traffic and conversion dashboards in your Serfix workspace; cross-reference Search Console queries with downstream conversion metrics in unified reports; populate scheduled email reports.
                    </li>
                    <li>
                        <strong>Indexing API</strong> is used solely to submit URL notifications when you click "Quick-submit" on a post or run a bulk submit in the Serfix admin. No other operations are performed against this scope.
                    </li>
                    <li>
                        <strong>Google profile data</strong> (email, user ID) is used only to identify which Google account is linked to which Serfix account and to display the connected email in your settings.
                    </li>
                </ul>
                <p>
                    Serfix does <strong>not</strong> use Google user data for any of the following:
                </p>
                <ul>
                    <li>Selling, renting, or transferring it to data brokers or information resellers.</li>
                    <li>Advertising, ad targeting, or audience profiling.</li>
                    <li>Training, fine-tuning, or otherwise developing general-purpose AI or machine learning models. (AI features in Serfix that touch your content, such as the AI snippet rewriter, send only the post excerpt and your focus keyphrase to the model. They do not include Search Console or Analytics data, and the model provider is contractually prohibited from training on the input.)</li>
                    <li>Determining creditworthiness or eligibility for any financial or insurance product.</li>
                    <li>Any purpose unrelated to providing or improving user-visible Serfix features.</li>
                </ul>

                <h3>2.2 How we use other data</h3>
                <ul>
                    <li>Account data is used to authenticate you, scope access to workspaces and websites you own, and contact you about service-impacting events.</li>
                    <li>Operational telemetry is used to operate, secure, and debug the service.</li>
                    <li>Billing metadata is used to bill the correct plan, send receipts, and process refunds when applicable.</li>
                    <li>Aggregated, non-identifying usage statistics are used to improve the product (for example, which features are most-used). Aggregates never include Google user data.</li>
                </ul>

                <h2>3. How we share data</h2>

                <h3>3.1 Google user data</h3>
                <p>
                    Serfix does not share Google user data with any third party except in the following narrowly-scoped cases, all of which are required to operate the service:
                </p>
                <ul>
                    <li>
                        <strong>Hetzner Online GmbH</strong> (cloud hosting infrastructure, EU). Serfix runs on Hetzner Cloud servers; cached Google data and the application database live on this infrastructure under Hetzner's <a href="https://www.hetzner.com/legal/privacy-policy/" rel="noopener noreferrer">data-protection terms</a> and DPA. Hetzner provides infrastructure only and does not access stored data for its own purposes.
                    </li>
                    <li>
                        <strong>Error monitoring</strong> may receive a stack trace if a request involving Google data fails. Stack traces are scrubbed of secrets and personally identifiable information before transmission, and the monitoring vendor processes them strictly to alert us about errors.
                    </li>
                    <li>
                        <strong>Other end users you authorise</strong>: if you invite a teammate to your Serfix workspace, they will see the Google-derived dashboards for the websites you grant them access to. You control invitations and can revoke them at any time.
                    </li>
                </ul>
                <p>
                    Serfix does not share Google user data with advertisers, data brokers, AI training providers, analytics vendors, or any party for monetisation, marketing, or product-development purposes outside our own service.
                </p>

                <h3>3.2 Other data</h3>
                <ul>
                    <li><strong>Stripe</strong> (payments) processes plan upgrades, charges, and invoices. We send Stripe your name, email, plan, and billing address; Stripe returns subscription status and last-four card digits. See <a href="https://stripe.com/privacy" rel="noopener noreferrer">Stripe's privacy policy</a>.</li>
                    <li><strong>Transactional email provider</strong> handles password resets, billing receipts, and report emails on our instructions.</li>
                    <li><strong>Legal disclosure</strong> &mdash; we may disclose data when required by valid legal process, to defend our rights, or to protect users from imminent harm.</li>
                </ul>
                <p>All sub-processors process data on our written instructions, under confidentiality obligations, and only to provide their narrowly-scoped service.</p>

                <h2>4. How we store and protect data</h2>

                <h3>4.1 Storage location</h3>
                <ul>
                    <li>Application servers and the primary database are hosted on Hetzner Cloud infrastructure in EU datacenters (Germany / Finland).</li>
                    <li>Google user data (cached Search Console / Analytics responses, OAuth refresh tokens) is stored alongside your Serfix workspace on the same infrastructure.</li>
                </ul>

                <h3>4.2 Encryption</h3>
                <ul>
                    <li><strong>In transit:</strong> all traffic between your browser, the WordPress plugin, Serfix servers, and Google's APIs is encrypted with TLS 1.2 or higher.</li>
                    <li><strong>At rest:</strong> database volumes are encrypted at the disk level. OAuth refresh tokens are additionally encrypted at the application layer using a per-installation key, so they cannot be read directly from a database backup.</li>
                </ul>

                <h3>4.3 Access controls</h3>
                <ul>
                    <li>Production access is restricted to a small number of operators with two-factor authentication and audited SSH access.</li>
                    <li>Application-level authorization scopes data by workspace and website membership: a user only ever sees Google data for sites they own or have been invited to.</li>
                    <li>The WordPress plugin uses a per-website API token, scoped to that site's data only.</li>
                </ul>

                <h3>4.4 Retention</h3>
                <ul>
                    <li>OAuth refresh tokens are retained while your Google connection is active. They are deleted within 7 days of you revoking access in your Google account or disconnecting in Serfix.</li>
                    <li>Cached Search Console and Analytics responses are retained while your Serfix account is active and refreshed on a rolling basis. They are deleted within 30 days of account closure.</li>
                    <li>Account data is retained while your account is active. On account closure, account data is deleted or anonymised within 30 days, except where retention is required for legal, fraud-prevention, billing, or accounting obligations.</li>
                    <li>Audit logs and request logs are retained up to 90 days, then rotated.</li>
                </ul>

                <h3>4.5 Deletion requests</h3>
                <p>
                    You can disconnect Google access at any time from <a href="https://myaccount.google.com/permissions" rel="noopener noreferrer">your Google account permissions page</a> or from the Settings page inside Serfix. To request deletion of your Serfix account and all associated data, email <a href="mailto:privacy@serfix.io">privacy@serfix.io</a>; we will confirm completion within 30 days.
                </p>

                <h2>5. Limited Use disclosure</h2>
                <p>
                    Serfix's use and transfer to any other app of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy#additional_requirements_for_specific_api_scopes" rel="noopener noreferrer">Google API Services User Data Policy</a>, including the <strong>Limited Use requirements</strong>. We affirm:
                </p>
                <ul>
                    <li>We use Google user data only to provide or improve user-facing features that are prominent in the Serfix product experience.</li>
                    <li>We do not transfer Google user data except as necessary to provide or improve those features, comply with applicable law, or as part of a merger, acquisition, or sale of assets with notice to users.</li>
                    <li>We do not use Google user data to serve advertisements, including remarketing, personalised, or interest-based advertising.</li>
                    <li>We do not allow humans to read Google user data unless we have your affirmative agreement for specific messages, it is necessary for security purposes (such as investigating abuse), to comply with applicable law, or the data has been aggregated and anonymised.</li>
                </ul>

                <h2>6. Your rights</h2>
                <p>You can request access, correction, deletion, restriction, or portability of your personal data by contacting <a href="mailto:privacy@serfix.io">privacy@serfix.io</a>. We respond within 30 days. EU/UK residents have rights under the GDPR; California residents have rights under the CCPA. We honour these rights for all users globally.</p>

                <h2>7. Children</h2>
                <p>Serfix is not directed to children under 16 and we do not knowingly collect their data.</p>

                <h2>8. Changes to this policy</h2>
                <p>We may update this Privacy Policy to reflect legal, product, or security changes. Material changes will be posted on this page with an updated effective date and, where required, communicated to active users by email.</p>

                <h2>9. Contact</h2>
                <p>Privacy requests and questions: <a href="mailto:privacy@serfix.io">privacy@serfix.io</a>. Data controller: Serfix.</p>
            </div>
        </div>
    </article>
</x-marketing.page>
