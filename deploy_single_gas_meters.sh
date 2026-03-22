#!/bin/bash

# Deploy Single Gas Meters Feature
echo "Deploying Single Gas Meters Feature..."

# SSH into VPS and run commands
ssh -i "C:\Users\Peter Darley\Desktop\Support Services\mbfd-key.pem" root@104.128.90.91 << 'ENDSSH'
cd /var/www/html

# Pull latest changes from git (if using git)
# git pull origin main

# Run migrations
docker compose exec laravel-app php artisan migrate --force

# Clear caches
docker compose exec laravel-app php artisan config:clear
docker compose exec laravel-app php artisan cache:clear
docker compose exec laravel-app php artisan route:clear
docker compose exec laravel-app php artisan view:clear

# Optimize for production
docker compose exec laravel-app php artisan config:cache
docker compose exec laravel-app php artisan route:cache
docker compose exec laravel-app php artisan view:cache

echo "Single Gas Meters feature deployed successfully!"
ENDSSH

echo "Deployment complete!"
