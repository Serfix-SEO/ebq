# Staging environment — RETIRED 2026-07-27

The staging box (`10.0.0.4`, Hetzner id 151872820) was **repurposed into the self-hosted
Firecrawl render server**. See **[firecrawl-server.md](./firecrawl-server.md)**.

There is **no staging environment anymore**. Consequences:
- `scripts/deploy-staging.sh` is retired (neutered to a no-op).
- `staging.serfix.io` no longer serves the app (DNS record can be removed in Cloudflare;
  the box has no web server now).
- The "staging-first" deploy workflow no longer applies — deploys go straight to prod
  (box A `10.0.0.2` + box B `10.0.0.3`), which had already become the working pattern.
- `config/horizon.php` still defines a `staging` env pool; it is inert (no box runs
  `APP_ENV=staging`) and left in place harmlessly.

Historical detail on the old staging stack lives in git history prior to this commit.
