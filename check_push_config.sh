#!/bin/bash

echo "========================================="
echo "Push Notification Configuration Check"
echo "========================================="
echo ""

echo "1. Checking VPS Connection..."
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170 "echo 'VPS Connection SUCCESSFUL'"

echo ""
echo "2. Checking .env file for VAPID keys..."
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170 << 'ENDSSH'
cd /root/mbfd-hub
echo "Checking for VAPID keys in .env file:"
echo "--------------------------------------"
grep -E "^VAPID_" .env || echo "⚠️ NO VAPID KEYS FOUND IN .env"
echo ""
echo "VAPID Key Length Check:"
VAPID_PUBLIC=$(grep "^VAPID_PUBLIC_KEY=" .env | cut -d'=' -f2)
VAPID_PRIVATE=$(grep "^VAPID_PRIVATE_KEY=" .env | cut -d'=' -f2)
VAPID_SUBJECT=$(grep "^VAPID_SUBJECT=" .env | cut -d'=' -f2)

if [ -z "$VAPID_PUBLIC" ]; then
    echo "❌ VAPID_PUBLIC_KEY is EMPTY or MISSING"
else
    echo "✅ VAPID_PUBLIC_KEY exists (length: ${#VAPID_PUBLIC} chars)"
fi

if [ -z "$VAPID_PRIVATE" ]; then
    echo "❌ VAPID_PRIVATE_KEY is EMPTY or MISSING"
else
    echo "✅ VAPID_PRIVATE_KEY exists (length: ${#VAPID_PRIVATE} chars)"
fi

if [ -z "$VAPID_SUBJECT" ]; then
    echo "❌ VAPID_SUBJECT is EMPTY or MISSING"
else
    echo "✅ VAPID_SUBJECT exists: $VAPID_SUBJECT"
fi

echo ""
echo "3. Checking Docker containers..."
docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep mbfd

echo ""
echo "4. Checking Laravel Queue Workers..."
docker exec mbfd-hub-laravel-1 php artisan queue:work --once --stop-when-empty 2>&1 | head -5 || echo "⚠️ Queue command failed"

echo ""
echo "5. Checking Service Worker file..."
if [ -f "public/sw.js" ]; then
    echo "✅ Service worker file exists at public/sw.js"
    echo "File size: $(stat -f%z public/sw.js 2>/dev/null || stat -c%s public/sw.js 2>/dev/null) bytes"
else
    echo "❌ Service worker file NOT FOUND at public/sw.js"
fi

echo ""
echo "6. Checking push_subscriptions table..."
docker exec -e PGPASSWORD=your_password mbfd-hub-postgres-1 psql -U mbfd_user -d mbfd_hub -c "SELECT COUNT(*) as subscription_count FROM push_subscriptions;" 2>&1 || echo "⚠️ Could not query push_subscriptions table"

echo ""
echo "========================================="
echo "Configuration check complete!"
echo "========================================="
ENDSSH
