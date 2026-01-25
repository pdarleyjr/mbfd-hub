#!/bin/bash
set -e
cd /root/mbfd-hub

# Create backup before deploy
BACKUP_FILE="backups/mbfd_hub_$(date +%Y%m%d_%H%M%S).sql"
mkdir -p backups
docker compose exec -T pgsql pg_dump -U mbfd_user mbfd_hub > "$BACKUP_FILE"
echo "Backup created: $BACKUP_FILE"

# Pull latest changes and reset to origin (deterministic deploy)
git fetch origin
git reset --hard origin/main
git clean -fd
echo "Repository reset to origin/main"

# Rebuild and restart containers
docker compose up -d --build
echo "Containers rebuilt and restarted"

# Run Laravel commands
docker compose exec -T laravel.test php artisan migrate --force
docker compose exec -T laravel.test php artisan config:cache
docker compose exec -T laravel.test php artisan route:cache
docker compose exec -T laravel.test php artisan view:cache
echo "Laravel optimizations complete"

# Build daily-checkout frontend
cd resources/js/daily-checkout
npm ci
npm run build
cd /root/mbfd-hub
echo "Daily-checkout frontend built"

# Purge Cloudflare cache
echo "Purging Cloudflare cache..."
curl -X POST "https://api.cloudflare.com/client/v4/zones/d462d29a7b0f4c6ba0ed9790e0fd8dbb/purge_cache" \
  -H "Authorization: Bearer U6XGuhQXd5JwIrkuIprFiXA_OvyCqd6ZQeLs_cmZ" \
  -H "Content-Type: application/json" \
  --data '{"files":["https://support.darleyplex.com/daily/index.html","https://support.darleyplex.com/daily/","https://support.darleyplex.com/__version"]}'

# Basic smoke test
echo "Running smoke tests..."
sleep 5
curl -sf https://support.darleyplex.com/__version | jq . || echo "Version endpoint check failed"
curl -sf https://support.darleyplex.com/daily/ -o /dev/null && echo "Daily checkout accessible" || echo "Daily checkout check failed"

echo "Deploy complete!"
