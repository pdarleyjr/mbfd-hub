#!/bin/bash
cd /root/mbfd-hub || exit 1

echo "### FILE CHECKS"
for f in config/chatify.php resources/views/vendor/Chatify/layouts/footerLinks.blade.php resources/views/vendor/chatify/layouts/footerLinks.blade.php public/js/chatify/code.js public/css/app/chatify-integration.css public/storage; do
  if [ -e "$f" ]; then ls -ld "$f"; else echo "MISSING $f"; fi
done

echo
echo "### ENV"
grep -E "^(APP_URL|BROADCAST_CONNECTION|REVERB_APP_ID|REVERB_APP_KEY|REVERB_APP_SECRET|REVERB_HOST|REVERB_PORT|REVERB_SCHEME|REVERB_SERVER_HOST|REVERB_SERVER_PORT|CHATIFY_)=" .env || true

echo
echo "### CHATIFY ROUTES"
docker exec mbfd-hub-laravel.test-1 php artisan route:list --path=chatify 2>&1 || true

echo
echo "### REVERB PROCESS"
docker exec mbfd-hub-laravel.test-1 ps aux | grep reverb || true

echo
echo "### RUNTIME CHATIFY CONFIG"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo json_encode(config("chatify.pusher"), JSON_PRETTY_PRINT);' 2>&1 || true

echo
echo "### RUNTIME REVERB APPS CONFIG"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo json_encode(config("reverb.apps"), JSON_PRETTY_PRINT);' 2>&1 || true

echo
echo "### PUSHER AUTH ROUTE"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo route("pusher.auth");' 2>&1 || true

echo
echo "### VIEW RESOLUTION"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='
try { echo "Chatify:: ".view()->getFinder()->find("Chatify::layouts.footerLinks").PHP_EOL; } catch (\Throwable $e) { echo "Chatify:: ERROR ".$e->getMessage().PHP_EOL; }
try { echo "chatify:: ".view()->getFinder()->find("chatify::layouts.footerLinks").PHP_EOL; } catch (\Throwable $e) { echo "chatify:: ERROR ".$e->getMessage().PHP_EOL; }
' 2>&1 || true

echo
echo "### SYMLINK CHECK"
ls -la resources/views/vendor/ | grep -i chatify || true

echo
echo "### STORAGE LINK"
ls -la public/storage 2>&1 || true

echo
echo "### AUTH ENDPOINT CHECK (unauthenticated)"
curl -ksS -o /tmp/chatify_auth_body.txt -D /tmp/chatify_auth_headers.txt -w "HTTP %{http_code}" -X POST https://www.mbfdhub.com/chatify/chat/auth -H "X-CSRF-TOKEN: test" --data "channel_name=private-chatify.1&socket_id=1234.5678" 2>&1 || true
echo
head -20 /tmp/chatify_auth_headers.txt 2>/dev/null || true
echo "--- body ---"
head -40 /tmp/chatify_auth_body.txt 2>/dev/null || true

echo
echo "### BROADCASTING CONFIG"
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute='echo json_encode(config("broadcasting.connections.reverb"), JSON_PRETTY_PRINT);' 2>&1 || true

echo
echo "### DONE"
