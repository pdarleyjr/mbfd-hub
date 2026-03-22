#!/bin/bash
set -e
cd /root/mbfd-hub

# Create backup before deploy
BACKUP_FILE="backups/mbfd_hub_$(date +%Y%m%d_%H%M%S).sql"
mkdir -p backups
docker compose exec -T pgsql pg_dump -U mbfd_user mbfd_hub > "$BACKUP_FILE"
echo "Backup created: $BACKUP_FILE"

# Pull latest changes
git fetch origin
git checkout ${1:-main}
git pull origin ${1:-main}

# Run Laravel commands
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "Deploy complete!"
