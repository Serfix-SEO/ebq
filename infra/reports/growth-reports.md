# Growth reports (email) & anomaly detection

Two scheduled, per-website fan-outs: the daily **growth report email** and the
daily **traffic-drop alert**. Both read the same synced sources and resolve
recipients the same way (`Website::getReportRecipientUsers()`), then push mail.

## Growth report email

### Flow

```
cron 08:00  routes/console.php → ebq:send-reports
  └─ SendGrowthReports::handle                    app/Console/Commands/SendGrowthReports.php
       Website::chunkById(100) → per site:
       ├─ ReportDataService::reportReadiness(site)         (GSC/GA degradation)
       │    ├─ !any  → skip (no usable GA or GSC data)     [counts sitesSkippedNoData]
       │    └─ !ga || !gsc → degraded but still send        [counts sitesDegraded]
       ├─ date = readiness['date']  (last-safe GSC day, GA fallback)
       ├─ recipients = getReportRecipientUsers() ; empty → skip
       └─ per recipient: ReportMailDispatcher::send(recipient, site, date, date, 'daily')
            └─ build GrowthReportMail (branding pre-resolved) + route by transport
```

`reportReadiness` snaps the window to the **last fully-synced GSC day** (lag-aware,
so a partial day never reads as a regression) — see
[insights.md](./insights.md#gsc-lag-aware-dates--gagsc-degradation). The command is
a `daily()` cron in `routes/console.php` at 08:00, after `ebq:sync-daily-data`.

### Transport routing — `ReportMailDispatcher`

`app/Services/Reports/ReportMailDispatcher.php` is the single send entry point. It
resolves branding (`ReportBrandingResolver`) and transport (`MailTransportResolver`)
off the **website owner's plan**, then routes:

| Resolved transport | Path |
|---|---|
| `null` (no per-tenant route) | `Mail::to()->queue($mailable)` — Laravel's default mailer/queue |
| `smtp` | render mailable, send via custom `EsmtpTransport` (`DynamicMailerFactory`) |
| `gmail` | render → raw Symfony `Email`, `GmailMailSender` (Gmail API) |
| `outlook` | render → raw Symfony `Email`, `OutlookMailSender` (Graph API) |

**Why OAuth/SMTP sends are NOT queued via Laravel's mailer**: a queue worker would
need the per-tenant OAuth/SMTP config. Instead the dispatcher sends inline (the
command processes one site at a time, rate-limited by `chunkById`); only the
`null` path uses the mailer queue. `buildSymfonyEmailFor` (:107) mirrors what
Laravel's mailer does — subject/replyTo from `envelope()`, body from `render()`,
attachments via `attachWith(pathStrategy, dataStrategy)`. On send it
`markVerified()`; on failure it persists `last_error` and rethrows.

### Branding (white-label) — `ReportBrandingResolver`

`for(user, website)` resolution order (first hit wins): plan flag
`report_whitelabel` off → **EBQ default**; else per-website override row; else
per-user default row; else EBQ default. Saved rows are **preserved** when the flag
is off, so a downgrade keeps config and re-enabling lights it back up.
`ReportBranding::ebqDefault()` returns "EBQ" so the default path is byte-identical
to pre-white-label behavior.

### The mailable & PDF — `GrowthReportMail`

`app/Mail/GrowthReportMail.php`. The constructor calls
`ReportDataService::generate()` for the `$report` payload and slices the top 5 of
cannibalization / striking-distance / indexing-fails into `$insights`. Subject is
`{branding.company_name} {Type} Report — {domain} ({dates})`; custom headers carry
user/website ids. The **PDF attachment** (`ReportPdfRenderer`,
`emails.growth-report-pdf` via barryvdh/laravel-dompdf, A4 portrait) is **always**
attached, branded or not — when white-label is off it renders with EBQ default
branding so the recipient still gets a saveable artifact.

> **Gotcha**: callers MUST pre-resolve the window via `lastSafeReportDate` — the
> mailable does *not* retry it in its constructor, because throwing from a queued
> mailable's constructor would abort the surrounding `chunkById` batch, and
> silently substituting a fallback date would mask sync failures.

> **Dependency gotcha**: Cashier only pulls in `dompdf/dompdf` (invoices); the
> Laravel `Pdf` facade wrapper (`barryvdh/laravel-dompdf`) is NOT transitive and
> must stay in `composer.lock`, or `ReportPdfRenderer` throws "Class … Facade\Pdf
> not found".

### Manual send — `Reports/ReportGenerator`

The Reports tab (`app/Livewire/Reports/ReportGenerator.php`) previews
(`generate()`) and sends via the same `ReportMailDispatcher`. Send is rate-limited
to **5 attempts/hour per user** (`RateLimiter`). Presets snap end-date to
yesterday and start-date by report type (daily/weekly-7/monthly-30/custom).

## Anomaly detection — traffic-drop alerts

### Flow

```
cron 07:30  ebq:detect-traffic-drops
  └─ DetectTrafficDrops (command) → Website::chunkById(100)
       └─ dispatch DetectTrafficDrops (job, queue=SYNC)   one per website
            └─ TrafficAnomalyDetector::detect(websiteId)
                 ├─ has_anomaly? no  → return
                 ├─ alerted in last 24h (last_traffic_drop_alert_at)? → return (dedupe)
                 └─ notify each recipient: TrafficDropAlert ; stamp last_traffic_drop_alert_at
```

### The detector — `TrafficAnomalyDetector`

`app/Services/TrafficAnomalyDetector.php`. Compares **yesterday** against a
baseline of the prior **28 days** across three metrics:

| Metric | Source table | `higherIsBetter` | relative drop | minBaseline |
|---|---|---|---|---|
| `clicks` | `search_console_data` (SUM clicks) | true | 0.35 | 50.0 |
| `sessions` | `analytics_data` (SUM sessions) | true | 0.35 | 50.0 |
| `avg_rank_position` | `rank_tracking_snapshots` (AVG position, active kws) | false | 0.25 | 1.0 |

`scoreSeries` (:111) fires `triggered` only when **both** conditions hold:
- the metric crosses the **fixed relative threshold** (prevents noise on
  tiny-volume sites), AND
- it is **≥ 2σ** worse than the baseline mean (prevents false positives on
  naturally volatile sites).

Guards: needs ≥7 baseline days and `mean ≥ minBaseline` or it returns
un-triggered (avoids dividing by a tiny baseline). A **perfectly flat** baseline
(zero variance, z undefined) falls back to relative-only — the best available
signal. `has_anomaly` is true if any one metric triggers. Rank uses
`higherIsBetter=false` (a *rising* position number = worse).

### Alert & dedupe

`DetectTrafficDrops` job (queue `SYNC`) dedupes on `Website.last_traffic_drop_alert_at`
within **24h** (`DEDUPE_HOURS`) so a multi-day slump alerts once. `TrafficDropAlert`
(`app/Notifications/TrafficDropAlert.php`, `via=mail`) lists each *triggered*
metric with current vs baseline, % change, and z-score, linking to the dashboard.

## `GenerateAiInsights` — placeholder

`app/Jobs/GenerateAiInsights.php` is currently a **stub**: it inserts one
`AiInsight` row with a hardcoded placeholder summary (queue `DEFAULT`). Real
declining-page AI summaries are not implemented yet — flagged here so it isn't
mistaken for live functionality.

## Key files

- `app/Console/Commands/SendGrowthReports.php`, `app/Console/Commands/DetectTrafficDrops.php`
- `app/Services/Reports/{ReportMailDispatcher,ReportBrandingResolver,ReportPdfRenderer}.php`
- `app/Mail/GrowthReportMail.php`, `app/Livewire/Reports/ReportGenerator.php`
- `app/Jobs/{DetectTrafficDrops,GenerateAiInsights}.php`, `app/Services/TrafficAnomalyDetector.php`
- `app/Notifications/TrafficDropAlert.php`
- Schedule: `routes/console.php` (`07:30` detect, `08:00` reports)
</content>
