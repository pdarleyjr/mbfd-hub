#!/bin/bash
set -e
cd /root/mbfd-hub || exit 1

echo "=== Phase 2: Deploying Chatify split-config fix ==="

# Backup current files
echo "--- Backing up current files ---"
cp config/broadcasting.php config/broadcasting.php.bak.$(date +%s)
cp config/chatify.php config/chatify.php.bak.$(date +%s)
cp public/js/chatify/code.js public/js/chatify/code.js.bak.$(date +%s)

echo "--- Files backed up ---"

echo
echo "=== Phase 4: Verify Reverb config ==="
echo "--- Reverb allowed_origins ---"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo json_encode(config("reverb.apps.0.allowed_origins"));' || true

echo
echo "=== Phase 5: Storage and assets ==="
echo "--- Storage link ---"
docker exec mbfd-hub-laravel.test-1 php artisan storage:link 2>&1 || true
echo "--- Verify public/storage inside container ---"
docker exec mbfd-hub-laravel.test-1 ls -la /var/www/html/public/storage 2>&1 || true
echo "--- Verify chatify-integration.css ---"
docker exec mbfd-hub-laravel.test-1 ls -la /var/www/html/public/css/app/chatify-integration.css 2>&1 || true
echo "--- Verify chatify JS assets ---"
docker exec mbfd-hub-laravel.test-1 ls -la /var/www/html/public/js/chatify/ 2>&1 || true

echo
echo "=== Phase 6: Cache clear and rebuild ==="
docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear
docker exec mbfd-hub-laravel.test-1 php artisan filament:clear-cached-components
docker exec mbfd-hub-laravel.test-1 php artisan permission:cache-reset
docker exec mbfd-hub-laravel.test-1 php artisan cache:clear
docker exec mbfd-hub-laravel.test-1 php artisan config:cache
docker exec mbfd-hub-laravel.test-1 php artisan route:cache
docker exec mbfd-hub-laravel.test-1 php artisan view:cache

echo
echo "=== Verify new runtime config ==="
echo "--- Broadcasting connection (should show 127.0.0.1:8080 http) ---"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo json_encode(config("broadcasting.connections.reverb"), JSON_PRETTY_PRINT);'

echo
echo "--- Chatify pusher backend config (should show 127.0.0.1:8080 http) ---"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo json_encode(config("chatify.pusher"), JSON_PRETTY_PRINT);'

echo
echo "--- Reverb process check ---"
docker exec mbfd-hub-laravel.test-1 ps aux | grep reverb || true

echo
echo "=== Phase 7: Tunnel validation ==="
echo "--- Cloudflare tunnel config ---"
cat /root/.cloudflared/config.yml 2>/dev/null || cat /etc/cloudflared/config.yml 2>/dev/null || echo "Tunnel config not found at standard paths"

echo
echo "=== DONE ==="
