# Laravel Reverb Deployment Guide

## Overview

Laravel Reverb has been configured as the real-time WebSocket transport layer for Chatify. This guide covers deployment and configuration for production environments.

## What Was Configured

1. **Package Added**: `laravel/reverb` added to [`composer.json`](composer.json:16)
2. **Configuration Files Created**:
   - [`config/broadcasting.php`](config/broadcasting.php:1) - Broadcasting driver configuration
   - [`config/reverb.php`](config/reverb.php:1) - Reverb server configuration
3. **Environment Variables**: Added to [`.env.example`](.env.example:37)
4. **Chatify Integration**: Updated [`config/chatify.php`](config/chatify.php:41) to use Reverb credentials
5. **Docker Support**: Added reverb service to [`compose.yaml`](compose.yaml:25)

## Local Development with Docker

### Using Docker Compose

The reverb service is already configured in [`compose.yaml`](compose.yaml:25). Start all services:

```bash
./vendor/bin/sail up -d
```

The Reverb server will be available at:
- **Host**: `localhost`
- **Port**: `8080`
- **Scheme**: `http`

### Environment Configuration

Update your `.env` file:

```env
BROADCAST_DRIVER=reverb

REVERB_APP_ID=local
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

## Production Deployment (VPS)

### Step 1: Install Dependencies

After deploying your code, run:

```bash
composer install --optimize-autoloader --no-dev
```

### Step 2: Configure Environment Variables

Update your production `.env` file:

```env
BROADCAST_DRIVER=reverb

REVERB_APP_ID=production-app-id
REVERB_APP_KEY=<generate-secure-key>
REVERB_APP_SECRET=<generate-secure-secret>
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

**Important**: Generate secure random values for `REVERB_APP_KEY` and `REVERB_APP_SECRET`.

### Step 3: Run Reverb with Process Manager

Reverb should run as a persistent background process. Use Supervisor or systemd.

#### Option A: Supervisor Configuration

Create `/etc/supervisor/conf.d/reverb.conf`:

```ini
[program:reverb]
command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/html
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/reverb.log
stopwaitsecs=3600
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start reverb
```

#### Option B: Systemd Service

Create `/etc/systemd/system/reverb.service`:

```ini
[Unit]
Description=Laravel Reverb WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable reverb
sudo systemctl start reverb
```

### Step 4: Configure Reverse Proxy (Nginx)

Add WebSocket proxy configuration to your Nginx site:

```nginx
# WebSocket proxy for Reverb
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
}
```

Reload Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## Cloudflare Configuration

### SSL/TLS Settings

1. Go to **SSL/TLS** tab in Cloudflare dashboard
2. Set encryption mode to **Full** or **Full (Strict)**
3. **DO NOT use Flexible SSL** - WebSockets require end-to-end encryption

### WebSocket Support

Cloudflare automatically supports WebSockets on all plans. Ensure:

1. **Orange Cloud** (proxied) is enabled for your domain
2. SSL mode is set to Full/Strict
3. Your application uses `wss://` (WebSocket Secure) in production

### Environment Variables for Cloudflare

When behind Cloudflare with HTTPS:

```env
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

## Testing the Connection

### Check Reverb Server Status

```bash
# Check if Reverb is running
ps aux | grep reverb

# Check Reverb logs
tail -f storage/logs/reverb.log
```

### Test WebSocket Connection

From your browser console:

```javascript
// This will be handled by Chatify's JavaScript
// Just verify no connection errors in browser console
```

### Monitor Reverb

```bash
# Using Supervisor
sudo supervisorctl status reverb

# Using systemd
sudo systemctl status reverb
```

## Troubleshooting

### Connection Refused

**Issue**: WebSocket connection fails with "Connection refused"

**Solutions**:
1. Verify Reverb is running: `ps aux | grep reverb`
2. Check firewall rules: `sudo ufw status`
3. Ensure port 8080 is open internally (not externally)
4. Verify Nginx proxy configuration

### SSL/TLS Errors

**Issue**: Mixed content warnings or SSL errors

**Solutions**:
1. Set `REVERB_SCHEME=https` in production
2. Use Cloudflare Full/Strict SSL mode
3. Ensure Nginx has valid SSL certificates

### Cloudflare 521 Error

**Issue**: Cloudflare returns 521 (Web Server is Down)

**Solutions**:
1. Verify Nginx is running: `sudo systemctl status nginx`
2. Check Nginx error logs: `sudo tail -f /var/log/nginx/error.log`
3. Ensure upstream proxy is correctly configured

### Authentication Failures

**Issue**: Reverb rejects connections with auth errors

**Solutions**:
1. Verify `REVERB_APP_KEY` and `REVERB_APP_SECRET` match in `.env`
2. Clear config cache: `php artisan config:clear`
3. Restart Reverb process

## Scaling Considerations

### Horizontal Scaling

For multiple servers, enable Redis-based scaling in [`config/reverb.php`](config/reverb.php:1):

```php
'scaling' => [
    'enabled' => true,
    'channel' => 'reverb',
],
```

Configure Redis in `.env`:

```env
REVERB_SCALING_ENABLED=true
REDIS_HOST=your-redis-host
REDIS_PORT=6379
```

### Load Balancing

When using multiple Reverb instances behind a load balancer:

1. Enable sticky sessions (session affinity)
2. Use Redis for scaling (see above)
3. Ensure all instances share the same `REVERB_APP_KEY` and `REVERB_APP_SECRET`

## Security Best Practices

1. **Never expose Reverb port (8080) directly** - always use reverse proxy
2. **Use strong secrets** - generate with `openssl rand -base64 32`
3. **Enable Cloudflare Full/Strict SSL** - protect WebSocket traffic
4. **Configure allowed origins** in production (see [`config/reverb.php`](config/reverb.php:70))
5. **Monitor logs** regularly for suspicious activity

## Next Steps

1. Deploy code changes to production
2. Run `composer install`
3. Update production `.env` file
4. Configure Supervisor/systemd
5. Update Nginx configuration
6. Test WebSocket connectivity
7. Monitor Reverb logs

## Support

For issues or questions:
- Check Laravel Reverb documentation: https://laravel.com/docs/11.x/reverb
- Review Chatify documentation: https://chatify.munafio.com/
- Check application logs: `storage/logs/laravel.log`
- Check Reverb logs: `storage/logs/reverb.log` (if configured)
