#!/bin/bash
cd /var/www/mbfd-hub
git pull
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Deployment complete!"
