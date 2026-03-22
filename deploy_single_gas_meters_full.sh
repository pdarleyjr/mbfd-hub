#!/bin/bash

# Full deployment script for Single Gas Meters Feature
echo "============================================"
echo "Deploying Single Gas Meters Feature"
echo "============================================"

# Define paths
LOCAL_BASE="laravel-app"
REMOTE_PATH="/var/www/html"
SSH_KEY="C:\Users\Peter Darley\Desktop\Support Services\mbfd-key.pem"
VPS_HOST="root@104.128.90.91"

# Copy Model files
echo "Copying Model files..."
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Models/SingleGasMeter.php" "$VPS_HOST:$REMOTE_PATH/app/Models/"
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Models/Apparatus.php" "$VPS_HOST:$REMOTE_PATH/app/Models/"
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Models/Station.php" "$VPS_HOST:$REMOTE_PATH/app/Models/"

# Copy Migration file
echo "Copying Migration file..."
scp -i "$SSH_KEY" "$LOCAL_BASE/database/migrations/2026_02_03_000001_create_single_gas_meters_table.php" "$VPS_HOST:$REMOTE_PATH/database/migrations/"

# Copy Filament Resource files
echo "Copying Filament Resource files..."
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Resources/SingleGasMeterResource.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Resources/"

# Create SingleGasMeterResource directory structure on VPS
echo "Setting up directory structure..."
ssh -i "$SSH_KEY" "$VPS_HOST" "mkdir -p $REMOTE_PATH/app/Filament/Resources/SingleGasMeterResource/Pages"
ssh -i "$SSH_KEY" "$VPS_HOST" "mkdir -p $REMOTE_PATH/app/Filament/Exports"
ssh -i "$SSH_KEY" "$VPS_HOST" "mkdir -p $REMOTE_PATH/app/Filament/Resources/StationResource/RelationManagers"

# Copy Page files
echo "Copying Page files..."
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Resources/SingleGasMeterResource/Pages/ListSingleGasMeters.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Resources/SingleGasMeterResource/Pages/"
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Resources/SingleGasMeterResource/Pages/CreateSingleGasMeter.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Resources/SingleGasMeterResource/Pages/"
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Resources/SingleGasMeterResource/Pages/EditSingleGasMeter.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Resources/SingleGasMeterResource/Pages/"

# Copy Exporter
echo "Copying Exporter..."
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Exports/SingleGasMetersExporter.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Exports/"

# Copy RelationManager
echo "Copying RelationManager..."
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Resources/StationResource/RelationManagers/SingleGasMetersRelationManager.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Resources/StationResource/RelationManagers/"

# Update StationResource
echo "Copying updated StationResource..."
scp -i "$SSH_KEY" "$LOCAL_BASE/app/Filament/Resources/StationResource.php" "$VPS_HOST:$REMOTE_PATH/app/Filament/Resources/"

# Run migrations and clear caches on VPS
echo "Running migrations and clearing caches..."
ssh -i "$SSH_KEY" "$VPS_HOST" << 'ENDSSH'
cd /var/www/html

# Run migrations
docker compose exec -T laravel-app php artisan migrate --force

# Clear all caches
docker compose exec -T laravel-app php artisan config:clear
docker compose exec -T laravel-app php artisan cache:clear
docker compose exec -T laravel-app php artisan route:clear
docker compose exec -T laravel-app php artisan view:clear

# Optimize for production
docker compose exec -T laravel-app php artisan config:cache
docker compose exec -T laravel-app php artisan route:cache
docker compose exec -T laravel-app php artisan view:cache

# Restart the container to ensure all changes are loaded
docker compose restart laravel-app

echo "✓ Single Gas Meters feature deployed successfully!"
ENDSSH

echo ""
echo "============================================"
echo "Deployment Complete!"
echo "============================================"
echo ""
echo "You can now access the Single Gas Meters feature:"
echo "- Navigate to 'Fire Equipment' > 'Single Gas Meters'"
echo "- View meters on Station detail pages"
echo ""
