# Staging environment (built 2026-07-17)

One Hetzner cloud box mirroring the whole app in miniature, so changes get QA'd
WITHOUT touching production. Prod boxes: web A `10.0.0.2` + worker B `10.0.0.3`.

## The box

| | |
|---|---|
| Server | `ebq-staging` (Hetzner cx33, 4 vCPU / 8 GB, fsn1), id 151872820 |
| IPs | private `10.0.0.4` (same 10.0.0.0/24 network), public 178.105.24.246 (unused — traffic goes via box A) |
| Stack | Ubuntu 24.04, Apache + php8.3-fpm, local MariaDB (`ebq_staging` db/user), local Redis (`REDIS_PREFIX=ebq_staging_`) |
| Processes | supervisor: `ebq-horizon` (APP_ENV=staging pools: web×2, crawl×2, heavy×1 — see `config/horizon.php` `staging` env) + `ebq-schedule`. **No fleet queue** |
| Access | `ssh -i /root/.ssh/id_ed25519_worker root@10.0.0.4` from box A |
| Web | `https://staging.serfix.io` → DNS A record points DIRECTLY at the staging box's public IP (178.105.24.246; Cloudflare DNS-only). Let's Encrypt cert via certbot (auto-renews), http→https redirect. Basic auth ON THE STAGING BOX (`/etc/apache2/.htpasswd-staging`, user `serfix`). `X-Robots-Tag: noindex`. Public 80/443 opened by dedicated Hetzner firewall `ebq-staging-web` (id 11323177) — the shared worker firewall is untouched. (The original box-A reverse-proxy vhost is retired/disabled.) |
| Login | `admin@staging.serfix.io` (admin; password given to operator at build time) |

## Isolation guarantees (why staging can't disturb prod)

- **Own DB + own Redis** — different creds, different host; no prod connection
  strings exist in staging's `.env`.
- **Mail = REAL sending via prod Postal relay** (changed 2026-07-17 on operator
  request — verification emails needed for QA): `POSTAL_SMTP_HOST=10.0.0.2:25`
  over the private net, sender `"[Staging] Serfix" <noreply@serfix.io>` so every
  staging mail is visibly prefixed. Risk accepted: staging CAN email real
  addresses — only register test accounts with your own inboxes there.
- **DataForSEO forced sandbox** (`DATAFORSEO_FORCE_SANDBOX=true`) + spend cap $1
  — every report call hits the free mock host.
- **Absent on purpose**: Stripe keys (add TEST keys only if billing QA needed),
  Serper, Lighthouse, Keywords Everywhere, Mistral/DeepSeek, Google OAuth,
  Sentry, reCAPTCHA, Postal SMTP, and **HCLOUD_*** (staging must never be able
  to provision fleet boxes). Features degrade gracefully.
- Crawlers: `LINK_CRAWL_ENABLED=false`, proxies off.
- CC sidecar (`cc-domain-ranks.sqlite`, ~7 GB) not copied — `CcDomainRanks`
  returns null and score weights renormalize.

## Workflow

1. Make changes on box A working tree (or a branch).
2. `sudo bash scripts/deploy-staging.sh` — rsyncs code, migrates, restarts
   staging FPM + Horizon. Never touches staging `.env`.
3. QA at `https://staging.serfix.io` (basic auth). Site-explorer lookups return
   DataForSEO sandbox mock data instantly — good test fixtures.
4. When green: commit → push → deploy prod (box A restart + box B rsync per
   `infra/main.md`).

## Gotchas

- `ebq:demo-data` does NOT work on staging: `DemoDataSeeder::DEMO_USER_ID = 1`
  is an int from the pre-ULID era; staging users are ULIDs. Use sandbox
  lookups to generate data instead.
- Same opcache rules as prod: web-visible PHP changes need
  `systemctl restart php8.3-fpm` on the STAGING box (deploy script does it).
- The staging `.env` is hand-maintained ON the box (not in the repo). If you
  add a new required env var, add it there too.
- TLS: DONE (2026-07-17) — DNS `staging` → A 178.105.24.246 (staging box direct),
  certbot cert on the staging box, auto-renew via certbot.timer.
- Monthly cost: ~€16 (cx33). Drop the box anytime; rebuild takes ~30 min with
  this doc.
