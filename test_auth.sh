#!/bin/bash
cd /root/mbfd-hub
echo "Testing authentication for admin@mbfd.org..."
docker compose exec -T laravel.test bash -c 'php artisan tinker --execute="\$result = Auth::attempt([\"email\" => \"admin@mbfd.org\", \"password\" => \"password123\"]); echo \$result ? \"SUCCESS: Authentication works!\" : \"FAILED: Authentication failed\";"'
