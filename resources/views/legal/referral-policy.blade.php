<x-marketing.page
    title="Referral Program Policy — Serfix"
    description="How the Serfix refer-and-earn program works: qualifying referrals, the 50% reward credit, custom referral IDs, and the program rules."
>
    <article class="bg-white">
        <header class="border-b border-slate-200">
            <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Legal') }}</p>
                <h1 class="mt-3 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">{{ __('Referral Program Policy') }}</h1>
                <p class="mt-3 text-sm text-slate-500">{{ __('Last updated') }}: {{ \Illuminate\Support\Carbon::create(2026, 8, 23)->format('F j, Y') }}</p>
                <p class="mt-6 text-[16px] leading-7 text-slate-600">
                    Share Serfix with someone who'll love it, and we'll thank you with 50% off your next bill.
                    Here is exactly how the program works — plainly, with no fine print doing the heavy lifting.
                </p>
            </div>
        </header>

        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="prose prose-slate max-w-none prose-headings:tracking-tight prose-h2:mt-12 prose-h2:text-xl prose-h2:font-semibold prose-h2:text-slate-900 prose-p:text-slate-600 prose-li:text-slate-600 prose-strong:text-slate-900 prose-a:text-slate-900 prose-a:underline-offset-2">

                <h2>1. The program in one paragraph</h2>
                <p>Every Serfix account has a personal referral link (find yours on the <strong>Refer &amp; earn</strong> page). When someone signs up through your link and later pays their <strong>first full subscription bill</strong>, we automatically add a credit worth <strong>50% of your own base subscription price</strong> to your account. The credit is applied to your next invoice. There is no limit on how many people you can refer — every successful referral earns another credit, and credits stack.</p>

                <h2>2. Eligibility</h2>
                <ul>
                    <li>Anyone with a Serfix account can share a referral link.</li>
                    <li>The referred person must be a <strong>new customer</strong> — a person or business that does not already have a Serfix account.</li>
                    <li>You cannot refer yourself, and multiple accounts operated by the same person or organization do not qualify as referrals of one another.</li>
                </ul>

                <h2>3. When a referral qualifies</h2>
                <ul>
                    <li>The person must sign up through your referral link. Attribution uses a browser cookie that lasts <strong>60 days</strong> from the last click, so the signup does not need to happen in the same visit.</li>
                    <li>If someone clicks more than one referral link, the <strong>most recent</strong> link before signup gets the credit.</li>
                    <li>The reward triggers when the referred account pays its <strong>first full subscription bill</strong>. Discounted introductory periods (for example a reduced first month) do not count — the reward follows the first invoice paid at the regular price. A yearly subscription qualifies with its first payment.</li>
                    <li>Each referred account can earn its referrer <strong>one</strong> reward, ever.</li>
                </ul>

                <h2>4. The reward</h2>
                <ul>
                    <li>The reward is an <strong>account credit equal to 50% of your base subscription price</strong> (monthly plan → half a month's base price; yearly plan → half the yearly base price).</li>
                    <li>It is calculated on the <strong>base subscription only</strong> — additional-website add-ons are not part of the calculation and are billed normally.</li>
                    <li>The credit is applied <strong>automatically</strong> to your next invoice. If credits exceed an invoice, the remainder rolls to the following one.</li>
                    <li>If you don't have an active paid subscription when the reward lands, the credit waits on your account and applies to your first future invoice.</li>
                    <li>Credits are not cash: they cannot be withdrawn, transferred to another account, or exchanged, and they have no monetary value outside Serfix billing.</li>
                </ul>

                <h2>5. Referral IDs</h2>
                <ul>
                    <li>You can customize your referral ID on the Refer &amp; earn page. IDs are first-come, first-served.</li>
                    <li>Changing your ID immediately deactivates links shared under the old one.</li>
                    <li>IDs must not impersonate another person or brand, infringe trademarks, or contain offensive language. We may reclaim IDs that do.</li>
                </ul>

                <h2>6. Fair play</h2>
                <p>The program exists to reward genuine recommendations. The following forfeit pending and future rewards and may lead to removal from the program or account suspension under our <a href="{{ route('terms-conditions') }}">Terms &amp; Conditions</a>:</p>
                <ul>
                    <li>Self-referral in any form, including referring accounts you control or creating accounts to farm rewards.</li>
                    <li>Spam — bulk unsolicited email or messages, forum/comment flooding, or posting the link anywhere that presents it as an official Serfix offer.</li>
                    <li>Misleading claims about Serfix, its pricing, or the program.</li>
                    <li>Paid advertising that bids on Serfix brand terms or impersonates Serfix.</li>
                </ul>
                <p>Rewards obtained through fraud (including referrals whose payments are charged back or refunded) may be reversed.</p>

                <h2>7. Changes and termination of the program</h2>
                <p>We may change, suspend, or end the referral program at any time. Credits already earned under the rules in force at the time remain on your account and are honored. Material changes to these rules take effect when this page is updated — the "Last updated" date above always tells you the current version.</p>

                <h2>8. Cookies and privacy</h2>
                <p>Referral attribution uses a single first-party cookie (<code>ebq_ref</code>) that stores the referral ID for 60 days. It contains no personal data about the visitor. Referrers see only a masked form of a referred account's email address. Everything else is covered by our <a href="{{ route('privacy-policy') }}">Privacy Policy</a>.</p>

                <h2>9. Taxes</h2>
                <p>Credits reduce what you owe us; where the law treats rewards as taxable to you, reporting them is your responsibility.</p>

                <h2>10. Contact</h2>
                <p>Questions about the program or a referral you believe should have qualified: <a href="mailto:billing@serfix.io">billing@serfix.io</a>. We're happy to check individual cases.</p>
            </div>
        </div>
    </article>
</x-marketing.page>
