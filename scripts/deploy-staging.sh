#!/bin/bash
# Deploy the CURRENT working tree of box A to the staging box (10.0.0.4).
#
#   sudo bash scripts/deploy-staging.sh
#
# Staging is fully isolated (own MariaDB/Redis, log mail, sandbox DataForSEO,
# no Stripe/fleet/LLM keys) — see infra/reference/staging.md. This script
# NEVER touches staging's .env (hand-maintained there; secrets differ from
# prod on purpose).
set -euo pipefail

STAGING=10.0.0.4
KEY=/root/.ssh/id_ed25519_worker
SSH="ssh -i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10"

echo "── rsync code → $STAGING"
rsync -az -e "$SSH" \
  --exclude .git --exclude node_modules --exclude .env --exclude .env.worker \
  --exclude storage/logs --exclude storage/framework/cache \
  --exclude storage/framework/sessions --exclude storage/framework/views \
  --exclude bootstrap/cache --exclude storage/app/cc-domain-ranks.sqlite \
  /var/www/ebq/ "root@$STAGING:/var/www/ebq/"

echo "── migrate + reload runtime"
$SSH "root@$STAGING" '
  php /var/www/ebq/artisan config:clear >/dev/null
  php /var/www/ebq/artisan migrate --force
  php /var/www/ebq/artisan horizon:terminate >/dev/null 2>&1 || true
  systemctl restart php8.3-fpm
  chown -R www-data:www-data /var/www/ebq/storage /var/www/ebq/bootstrap/cache
'
echo "── done: https://staging.serfix.io (basic auth)"
