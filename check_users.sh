#!/bin/bash
cd /root/mbfd-hub
docker compose exec -T laravel.test bash -c 'php artisan tinker --execute="echo json_encode(App\Models\User::all([\"id\",\"email\",\"name\"])->toArray());"'
