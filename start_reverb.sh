#!/bin/bash
set -e

# Add supervisorctl section and reverb program to supervisord.conf
CONF_FILE="/etc/supervisor/conf.d/supervisord.conf"

# Check if reverb is already configured
if grep -q "program:reverb" "$CONF_FILE"; then
    echo "Reverb already in supervisor config"
else
    cat >> "$CONF_FILE" << 'EOF'

[unix_http_server]
file=/var/run/supervisor.sock
chmod=0700

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///var/run/supervisor.sock

[program:reverb]
command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction
user=sail
environment=LARAVEL_SAIL="1"
stdout_logfile=/var/log/supervisor/reverb.log
stdout_logfile_maxbytes=10MB
stderr_logfile=/var/log/supervisor/reverb_error.log
stderr_logfile_maxbytes=10MB
autostart=true
autorestart=true
EOF
    echo "Reverb added to supervisor config"
fi

# Kill supervisord to trigger restart
kill -SIGTERM 1
