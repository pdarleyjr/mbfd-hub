#!/bin/bash

echo "=========================================="
echo "Single Gas Meters Feature Deployment"
echo "=========================================="
echo ""

# Navigate to project directory
cd /var/www/mbfd-support-hub || exit 1

echo "Step 1: Pulling latest changes from Git..."
git pull origin main

echo ""
echo "Step 2: Running database migrations..."
docker-compose exec -T laravel.test php artisan migrate --force

echo ""
echo "Step 3: Clearing all caches..."
docker-compose exec -T laravel.test php artisan optimize:clear

echo ""
echo "Step 4: Optimizing application..."
docker-compose exec -T laravel.test php artisan config:cache
docker-compose exec -T laravel.test php artisan route:cache
docker-compose exec -T laravel.test php artisan view:cache

echo ""
echo "Step 5: Restarting Laravel container..."
docker-compose restart laravel.test

echo ""
echo "=========================================="
echo "Deployment Complete!"
echo "=========================================="
echo ""
echo "Verification Steps:"
echo "1. Visit https://support.darleyplex.com/admin"
echo "2. Look for 'Single Gas Meters' in Fire Equipment section"
echo "3. Navigate to a Station and check for 'Single Gas Meters' tab"
echo "4. Try creating a test meter entry"
echo "5. Test CSV export functionality"
echo ""
