#!/bin/bash

echo "🚀 Deploying Station Inventory Form Fixes..."

# SSH to VPS and execute commands
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170 << 'ENDSSH'

# Navigate to project directory
cd /root/mbfd-hub

# Pull latest changes
echo "📥 Pulling latest changes from Git..."
git pull origin main

# Run migrations
echo "🗄️  Running database migrations..."
docker-compose exec -T laravel.test php artisan migrate --force

# Clear caches
echo "🧹 Clearing caches..."
docker-compose exec -T laravel.test php artisan cache:clear
docker-compose exec -T laravel.test php artisan config:clear
docker-compose exec -T laravel.test php artisan route:clear
docker-compose exec -T laravel.test php artisan view:clear

# Build React app
echo "⚛️  Building React app..."
cd resources/js/daily-checkout
npm install
npm run build

# Copy build to public
echo "📦 Copying React build to public..."
cd /root/mbfd-hub
rm -rf public/daily/*
cp -r resources/js/daily-checkout/dist/* public/daily/

# Restart services
echo "♻️  Restarting services..."
docker-compose restart laravel.test

echo "✅ Deployment complete!"

ENDSSH

echo "🎉 Station Inventory Form fixes deployed successfully!"
