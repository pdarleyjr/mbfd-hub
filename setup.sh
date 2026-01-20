#!/bin/bash

# MBFD Support Hub Setup Script
echo "🚒 Setting up MBFD Support Hub..."

# Navigate to Laravel app directory
cd /root/mbfd-hub/laravel-app

# Update .env for PostgreSQL
sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=pgsql/' .env
sed -i 's/# DB_HOST=127.0.0.1/DB_HOST=pgsql/' .env  
sed -i 's/# DB_PORT=3306/DB_PORT=5432/' .env
sed -i 's/# DB_DATABASE=laravel/DB_DATABASE=mbfd_hub/' .env
sed -i 's/# DB_USERNAME=root/DB_USERNAME=mbfd_user/' .env
sed -i 's/# DB_PASSWORD=/DB_PASSWORD=mbfd_secure_pass_2026/' .env

# Set app name and URL
sed -i 's/APP_NAME=Laravel/APP_NAME="MBFD Support Hub"/' .env
sed -i 's#APP_URL=http://localhost#APP_URL=https://support.darleyplex.com#' .env

echo "✅ Laravel .env configured"

# Navigate back to root
cd /root/mbfd-hub

# Start Docker containers
echo "🐳 Starting Docker containers..."
docker compose up -d

echo "⏳ Waiting for database to be ready..."
sleep 10

# Install FilamentPHP
echo "📦 Installing FilamentPHP..."
docker compose exec -T app composer require filament/filament:"^3.2" -W

# Run migrations
echo "🗄️  Running database migrations..."
docker compose exec -T app php artisan migrate --force

# Create Filament admin user
echo "👤 Creating Filament admin panel..."
docker compose exec -T app php artisan filament:install --panels

echo "✨ Setup complete! Application is running on http://127.0.0.1:8082"
