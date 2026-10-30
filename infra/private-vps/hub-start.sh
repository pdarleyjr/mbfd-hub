#!/bin/bash
set -euo pipefail
# Never consume a copied production .env/cache in this synthetic fixture.
test "${APP_ENV:-}" = staging
test ! -f /var/www/html/.env
test ! -f /var/www/html/bootstrap/cache/config.php
export DB_PASSWORD="$(cat /run/secrets/hub_db)"
export APP_KEY="$(cat /run/secrets/hub_key)"
export REVERB_APP_KEY="$(cat /run/secrets/reverb_key)"
export REVERB_APP_SECRET="$(cat /run/secrets/reverb_secret)"
if [ "$#" -gt 0 ]; then exec "$@"; fi
exec /usr/bin/supervisord -c /staging/supervisord.conf
