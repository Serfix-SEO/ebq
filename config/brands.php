<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Protected brand domains
    |--------------------------------------------------------------------------
    |
    | Websites a random sign-up cannot possibly own and cannot publish to.
    | Content Autopilot writes articles FOR a site and pushes them INTO its CMS;
    | pointing it at a national telecom produces branded customer-service
    | articles that can never ship. `du.ae` did exactly that on 2026-08-16 —
    | 36 topics and 4 finished articles ("How to Pay Your du Bill Online") on a
    | free account with no publish integration and a homepage that timed out.
    |
    | DELIBERATELY SEPARATE from App\Support\GiantDomains. That list feeds
    | competitor discovery, where du.ae is a perfectly valid competitor for
    | another telecom — adding brands there would silently degrade competitor
    | research for every client.
    |
    | Scope rule: list brands nobody could plausibly sign up as. Regional
    | mid-size businesses stay OUT — a small UAE agency, clinic or shop is
    | exactly our customer. When in doubt, leave it out; the authority signal
    | in MajorBrands catches the rest.
    |
    | Matching is exact-or-subdomain, so `shop.emirates.com` is covered by
    | `emirates.com`. ccTLD siblings need their own entry.
    |
    | Admins can extend or override at runtime without a deploy:
    |   Setting::set('signup.blocked_brand_domains', "foo.com\nbar.ae")
    |   Setting::set('signup.allowed_brand_domains', "emirates.com")
    |
    */

    'protected' => [

        // ── Telecom / utilities — GCC ───────────────────────────────────
        'du.ae', 'etisalat.ae', 'etisalat.com', 'eand.com', 'virginmobile.ae',
        'stc.com.sa', 'stc.com.bh', 'stc.com.kw', 'mobily.com.sa', 'zain.com',
        'ooredoo.com', 'ooredoo.qa', 'ooredoo.om', 'omantel.om', 'batelco.com',
        'vivakuwait.com.kw', 'dewa.gov.ae', 'sewa.gov.ae', 'addc.ae', 'empower.ae',

        // ── Telecom — global ───────────────────────────────────────────
        'verizon.com', 'att.com', 't-mobile.com', 'sprint.com',
        'vodafone.com', 'vodafone.co.uk', 'orange.com', 'telefonica.com',
        'bt.com', 'ee.co.uk', 'o2.co.uk', 'three.co.uk', 'virginmedia.com',
        'jio.com', 'airtel.in', 'bsnl.co.in', 'telstra.com.au', 'rogers.com',

        // ── Airlines / travel ──────────────────────────────────────────
        'emirates.com', 'etihad.com', 'flydubai.com', 'airarabia.com',
        'qatarairways.com', 'saudia.com', 'gulfair.com', 'kuwaitairways.com',
        'omanair.com', 'britishairways.com', 'lufthansa.com', 'airfrance.com',
        'klm.com', 'delta.com', 'united.com', 'aa.com', 'southwest.com',
        'singaporeair.com', 'cathaypacific.com', 'turkishairlines.com',
        'ryanair.com', 'easyjet.com', 'dubaiairports.ae', 'visitdubai.com',

        // ── Banking / finance / payments ───────────────────────────────
        'emiratesnbd.com', 'adcb.com', 'fab.ae', 'mashreq.com', 'dib.ae',
        'adib.ae', 'rakbank.ae', 'cbd.ae', 'sib.ae', 'ajmanbank.ae', 'nbf.ae',
        'hsbc.com', 'barclays.co.uk', 'standardchartered.com', 'santander.com',
        'jpmorgan.com', 'chase.com', 'bankofamerica.com', 'wellsfargo.com',
        'citi.com', 'goldmansachs.com', 'deutsche-bank.de',
        'paypal.com', 'visa.com', 'mastercard.com', 'americanexpress.com',
        'stripe.com', 'wise.com', 'revolut.com',

        // ── Energy / industry ──────────────────────────────────────────
        'adnoc.ae', 'aramco.com', 'shell.com', 'bp.com', 'exxonmobil.com',
        'chevron.com', 'totalenergies.com', 'siemens.com', 'ge.com',
        'schneider-electric.com', 'honeywell.com', 'caterpillar.com',

        // ── UAE conglomerates / real estate / retail groups ────────────
        'emaar.com', 'nakheel.com', 'damacproperties.com', 'aldar.com',
        'majidalfuttaim.com', 'alfuttaim.com', 'landmarkgroup.com',
        'chalhoub.com', 'emiratesgroup.com', 'dpworld.com', 'dp-world.com',
        'jumeirah.com', 'rotana.com', 'atlantis.com',
        'carrefouruae.com', 'luluhypermarket.com', 'sharafdg.com',
        'centrepointstores.com', 'emax.ae', 'jumbo.ae',

        // ── Tech / hardware (platforms live in GiantDomains) ───────────
        'ibm.com', 'oracle.com', 'sap.com', 'salesforce.com', 'intel.com',
        'nvidia.com', 'amd.com', 'cisco.com', 'dell.com', 'hp.com',
        'lenovo.com', 'sony.com', 'lg.com', 'huawei.com', 'xiaomi.com',
        'nokia.com', 'motorola.com', 'panasonic.com', 'philips.com',

        // ── Automotive ─────────────────────────────────────────────────
        'toyota.com', 'honda.com', 'ford.com', 'bmw.com', 'mercedes-benz.com',
        'audi.com', 'volkswagen.com', 'nissan-global.com', 'hyundai.com',
        'kia.com', 'tesla.com', 'porsche.com', 'lexus.com', 'chevrolet.com',
        'jeep.com', 'landrover.com', 'jaguar.com', 'ferrari.com',
        'lamborghini.com', 'volvocars.com', 'mazda.com', 'subaru.com',

        // ── FMCG / food / fashion ──────────────────────────────────────
        'cocacola.com', 'pepsico.com', 'nestle.com', 'unilever.com', 'pg.com',
        'mondelezinternational.com', 'kraftheinz.com', 'danone.com',
        'mcdonalds.com', 'kfc.com', 'starbucks.com', 'burgerking.com',
        'subway.com', 'dominos.com', 'pizzahut.com', 'timhortons.com',
        'nike.com', 'adidas.com', 'puma.com', 'underarmour.com', 'zara.com',
        'hm.com', 'uniqlo.com', 'gap.com', 'levi.com', 'gucci.com',
        'louisvuitton.com', 'chanel.com', 'dior.com', 'rolex.com', 'sephora.com',

        // ── Pharma / healthcare ────────────────────────────────────────
        'pfizer.com', 'novartis.com', 'roche.com', 'jnj.com', 'gsk.com',
        'astrazeneca.com', 'bayer.com', 'merck.com', 'sanofi.com', 'abbott.com',

        // ── Logistics ──────────────────────────────────────────────────
        'dhl.com', 'fedex.com', 'ups.com', 'aramex.com', 'maersk.com',
        'emiratespost.ae', 'usps.com', 'royalmail.com',

        // ── Hospitality ────────────────────────────────────────────────
        'marriott.com', 'hilton.com', 'hyatt.com', 'ihg.com', 'accor.com',
        'radissonhotels.com', 'wyndhamhotels.com',

        // ── Consulting / professional services ─────────────────────────
        'deloitte.com', 'pwc.com', 'ey.com', 'kpmg.com', 'mckinsey.com',
        'bcg.com', 'bain.com', 'accenture.com',
    ],

    /*
    | Public-sector suffixes. A government or military site is never a Content
    | Autopilot customer, and there is no curated list long enough to cover
    | every country's ministries. `.edu` is deliberately ABSENT — a small
    | college or training provider is a plausible customer.
    */
    'public_sector_suffixes' => [
        '.gov', '.mil', '.gov.ae', '.gov.uk', '.gov.sa', '.gov.qa', '.gov.kw',
        '.gov.bh', '.gov.om', '.gov.in', '.gov.pk', '.gov.au', '.govt.nz',
        '.gob.mx', '.gouv.fr', '.go.jp', '.go.kr',
    ],

    /*
    | Zero-API-cost recall net: a domain already enriched in `domain_metrics`
    | at this scale is a global brand, whatever the curated list says. Both
    | thresholds must be met, and both are set high enough that no small or
    | mid-size business reaches them (a healthy niche site sits far below).
    | Never triggers a fetch — if we have no cached row, the check is skipped.
    */
    'authority_signal' => [
        'enabled' => true,
        'min_moz_da' => 80,
        'min_referring_domains' => 50_000,
    ],

];
