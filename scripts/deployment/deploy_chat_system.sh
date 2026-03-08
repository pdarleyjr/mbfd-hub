#!/bin/bash

# MBFD Chat and Push Notification System Deployment
# This script deploys the complete chat system with push notifications

set -e  # Exit on error

echo "============================================"
echo "MBFD Chat System Deployment"
echo "============================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
REMOTE_USER="root"
REMOTE_HOST="145.223.73.170"
REMOTE_PATH="/var/www/html"
SSH_KEY="C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker"

echo -e "${YELLOW}Step 1: Uploading updated composer.json...${NC}"
scp -i "$SSH_KEY" composer.json $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/composer.json

echo -e "${YELLOW}Step 2: Uploading Panel Providers...${NC}"
scp -i "$SSH_KEY" app/Providers/Filament/AdminPanelProvider.php $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/app/Providers/Filament/
scp -i "$SSH_KEY" app/Providers/Filament/TrainingPanelProvider.php $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/app/Providers/Filament/

echo -e "${YELLOW}Step 3: Uploading Push Notification Widget...${NC}"
scp -i "$SSH_KEY" -r app/Filament/Widgets/PushNotificationWidget.php $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/app/Filament/Widgets/

echo -e "${YELLOW}Step 4: Checking for required files...${NC}"
# Check if ChMessageObserver exists
if [ -f "app/Observers/ChMessageObserver.php" ]; then
    echo "Uploading ChMessageObserver..."
    scp -i "$SSH_KEY" -r app/Observers/ChMessageObserver.php $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/app/Observers/
fi

# Check if ChatMessageReceived notification exists
if [ -f "app/Notifications/ChatMessageReceived.php" ]; then
    echo "Uploading ChatMessageReceived notification..."
    scp -i "$SSH_KEY" -r app/Notifications/ChatMessageReceived.php $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/app/Notifications/
fi

echo -e "${YELLOW}Step 5: Running remote commands...${NC}"
ssh -i "$SSH_KEY" $REMOTE_USER@$REMOTE_HOST << 'ENDSSH'
cd /var/www/html

echo "Installing Composer dependencies..."
composer require monzer/filament-chatify-integration:^1.0
composer require bezhansalleh/filament-shield:^3.2
composer install --no-dev --optimize-autoloader

echo "Running Chatify installation..."
php artisan filament-chatify-integration:install --force

echo "Publishing Chatify assets..."
php artisan vendor:publish --provider="M Composer\Package\ChatifyServiceProvider" --tag=chatify-assets --force

echo "Publishing WebPush migrations (if not already done)..."
php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag=migrations

echo "Running migrations..."
php artisan migrate --force

echo "Generating VAPID keys (if not already generated)..."
php artisan webpush:vapid || echo "VAPID keys may already exist"

echo "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "Rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Setting permissions..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "Restarting services..."
systemctl restart php8.2-fpm || service php8.2-fpm restart
systemctl restart nginx || service nginx restart

echo "Chat system deployed successfully!"
ENDSSH

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}Deployment Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "${YELLOW}IMPORTANT NEXT STEPS:${NC}"
echo "1. Add VAPID keys to /var/www/html/.env on the server"
echo "2. Configure Reverb settings in .env"
echo "3. Start Reverb server: php artisan reverb:start"
echo "4. Start queue worker: php artisan queue:work"
echo "5. Test chat functionality at https://support.darleyplex.com/admin"
echo ""
echo -e "${YELLOW}To check VAPID keys on server, run:${NC}"
echo "ssh -i \"$SSH_KEY\" $REMOTE_USER@$REMOTE_HOST \"cd $REMOTE_PATH && php artisan webpush:vapid\""
