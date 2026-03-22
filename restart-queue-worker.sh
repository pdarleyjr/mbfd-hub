#!/bin/bash
# Restart Laravel queue worker in mbfd-hub container if not running
if ! docker exec mbfd-hub-laravel.test-1 pgrep -f 'queue:work' > /dev/null 2>&1; then
    docker exec -d mbfd-hub-laravel.test-1 bash -c 'nohup php /var/www/html/artisan queue:work --sleep=3 --tries=3 --max-time=3600 >> /tmp/queue-worker.log 2>&1 &'
    echo "$(date): Queue worker restarted" >> /var/log/queue-worker-restarts.log
fi
