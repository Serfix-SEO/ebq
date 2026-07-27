#!/bin/bash
# RETIRED 2026-07-27 — the staging box (10.0.0.4) was repurposed into the
# self-hosted Firecrawl render server. There is no staging environment anymore.
# See infra/reference/firecrawl-server.md. Deploys now go straight to prod
# (box A 10.0.0.2 + box B 10.0.0.3).
echo "⛔ deploy-staging.sh is retired — 10.0.0.4 is now the Firecrawl server."
echo "   See infra/reference/firecrawl-server.md"
exit 1
