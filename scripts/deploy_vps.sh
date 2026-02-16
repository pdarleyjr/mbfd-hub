#!/bin/bash
set -e

# Configuration
VPS_USER="root"
VPS_HOST="145.223.73.170"
VPS_PATH="/root/mbfd-hub"

echo "Deploying to VPS..."

# 1. Update VPS code and env
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" -o StrictHostKeyChecking=no $VPS_USER@$VPS_HOST << EOF
    cd $VPS_PATH
    
    # Fetch and Reset
    git fetch origin
    git checkout feature/gmail-oauth-revised
    git reset --hard origin/feature/gmail-oauth-revised
    
    # Update Env Vars (Naive approach, really should use sed or a tool, but appending works for new keys)
    # Check if keys exist, if not append
    # GOOGLE secrets are set manually or via separate secure script to avoid committing them
    
    # Ensure Feature Flags
    sed -i '/FEATURE_EMAIL_SENDING=/d' .env
    echo "FEATURE_EMAIL_SENDING=true" >> .env
    sed -i '/FEATURE_EMAIL_CENTER=/d' .env
    echo "FEATURE_EMAIL_CENTER=true" >> .env
    
    # Install Composer Dependencies (including google/apiclient)
    docker compose exec -T laravel.test composer install --no-dev --optimize-autoloader
    
    # Run Migrations
    docker compose exec -T laravel.test php artisan migrate --force
    
    # Optimize
    docker compose exec -T laravel.test php artisan optimize:clear
    docker compose exec -T laravel.test php artisan config:cache
    docker compose exec -T laravel.test php artisan view:cache
    docker compose exec -T laravel.test php artisan route:cache

    echo "Deployment Complete on VPS."
EOF
