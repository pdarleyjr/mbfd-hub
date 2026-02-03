#!/bin/bash

# Deploy Capital Projects Tab Fix
# This script will:
# 1. Copy files to VPS
# 2. Run migrations  
# 3. Clear caches

echo "🚀 Deploying Capital Projects Tab Fix..."

# VPS details
VPS_IP="145.223.73.170"
VPS_USER="root"
SSH_KEY="C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker"
APP_DIR="/var/www/mbfd-support-hub"

echo "📦 Copying migration file to VPS..."
scp -i "$SSH_KEY" \
    "database/migrations/2026_02_03_000001_fix_capital_projects_schema.php" \
    "$VPS_USER@$VPS_IP:$APP_DIR/database/migrations/"

echo "📦 Copying updated RelationManager to VPS..."
scp -i "$SSH_KEY" \
    "app/Filament/Resources/StationResource/RelationManagers/CapitalProjectsRelationManager.php" \
    "$VPS_USER@$VPS_IP:$APP_DIR/app/Filament/Resources/StationResource/RelationManagers/"

echo "🔧 Running migration and clearing caches on VPS..."
ssh -i "$SSH_KEY" "$VPS_USER@$VPS_IP" << 'EOF'
cd /var/www/mbfd-support-hub

# Run migration
docker-compose exec -T laravel.test php artisan migrate --force

# Clear all caches
docker-compose exec -T laravel.test php artisan cache:clear
docker-compose exec -T laravel.test php artisan config:clear
docker-compose exec -T laravel.test php artisan view:clear
docker-compose exec -T laravel.test php artisan route:clear

# Optimize
docker-compose exec -T laravel.test php artisan optimize

echo "✅ Deployment complete!"
EOF

echo ""
echo "✅ Capital Projects fix deployed successfully!"
echo ""
echo "🧪 Test by navigating to:"
echo "   Admin → Stations → [select station] → Capital Projects tab"
