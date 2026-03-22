# Chat and Push Notifications Setup Guide

This guide provides step-by-step instructions for setting up the chat and push notification system in new environments.

## Prerequisites

- PHP 8.2 or higher
- Composer 2.x
- PostgreSQL 14+ (or MySQL 8+)
- Node.js 18+ and npm (for asset compilation)
- Supervisor or systemd (for production services)

## Step 1: Install Dependencies

### Install PHP Packages

```bash
# Install Chatify Integration
composer require monzer/filament-chatify-integration

# Install Laravel Reverb for WebSockets
composer require laravel/reverb

# Install WebPush for notifications
composer require laravel-notification-channels/webpush

# Install dependencies
composer install --optimize-autoloader
```

### Install Node Dependencies (if needed)

```bash
npm install
npm run build
```

## Step 2: Run Migrations

### Create Database Tables

```bash
# Run all pending migrations
php artisan migrate

# This creates:
# - ch_messages (chat messages)
# - ch_favorites (user favorites)
# - push_subscriptions (notification subscriptions)
# - jobs (queue table)
```

### Verify Tables

```bash
# Check tables were created
php artisan db:show

# Or directly in PostgreSQL:
psql -U your_user -d your_database -c "\dt"
```

## Step 3: Configure Environment Variables

### Generate VAPID Keys

```bash
# Generate keys for Web Push notifications
php artisan webpush:vapid

# This outputs:
# VAPID_PUBLIC_KEY=...
# VAPID_PRIVATE_KEY=...
```

### Update .env File

Add or update these variables in your `.env` file:

```env
# Web Push Notifications
VAPID_PUBLIC_KEY=your_generated_public_key
VAPID_PRIVATE_KEY=your_generated_private_key
VAPID_SUBJECT=mailto:admin@yourdomain.com

# Laravel Reverb Configuration
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Broadcasting
BROADCAST_DRIVER=reverb

# Queue Configuration
QUEUE_CONNECTION=database

# Production Settings (when deploying)
# REVERB_HOST=yourdomain.com
# REVERB_PORT=443
# REVERB_SCHEME=https
```

### Clear Configuration Cache

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Step 4: Install Chatify Assets

### Publish Chatify Configuration

```bash
# Install Chatify integration
php artisan filament-chatify-integration:install

# Publish assets
php artisan filament:assets

# Clear view cache
php artisan view:clear
```

### Install Reverb

```bash
# Install Reverb (WebSocket server)
php artisan reverb:install

# This publishes config/reverb.php
```

## Step 5: Start Required Services

### Development Environment

#### Terminal 1: Start Application Server

```bash
# Laravel development server
php artisan serve

# Or use Valet/Herd/Docker depending on setup
```

#### Terminal 2: Start Reverb (WebSocket Server)

```bash
# Start Reverb server
php artisan reverb:start

# You should see:
# Reverb server started on ws://localhost:8080
```

#### Terminal 3: Start Queue Worker

```bash
# Start queue worker for notifications
php artisan queue:work --verbose

# With multiple workers:
php artisan queue:work --tries=3 --sleep=1
```

### Production Environment

For production, use Supervisor or systemd to manage services.

#### Option A: Supervisor Configuration

Create `/etc/supervisor/conf.d/laravel.conf`:

```ini
[program:laravel-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/queue.log
stopwaitsecs=3600

[program:laravel-reverb]
process_name=%(program_name)s
command=php /var/www/html/artisan reverb:start
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/reverb.log
```

Start services:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-queue:*
sudo supervisorctl start laravel-reverb
```

#### Option B: Systemd Configuration

Create `/etc/systemd/system/laravel-queue.service`:

```ini
[Unit]
Description=Laravel Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Create `/etc/systemd/system/laravel-reverb.service`:

```ini
[Unit]
Description=Laravel Reverb Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php artisan reverb:start
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable laravel-queue
sudo systemctl enable laravel-reverb
sudo systemctl start laravel-queue
sudo systemctl start laravel-reverb
```

## Step 6: Test Notifications

### Test Push Notification Subscription

1. **Open Application** in your browser
2. **Login** to either Admin or Training panel
3. **Locate Push Notification Widget** in dashboard
4. **Click "Subscribe to Notifications"**
5. **Grant Permission** when browser prompts
6. **Verify subscription** shows "Subscribed" status

### Send Test Push Notification

```bash
# Use Tinker to send a test notification
php artisan tinker

# In Tinker:
$user = \App\Models\User::find(1);
$user->notify(new \App\Notifications\ChatMessageReceived(
    'Test message',
    \App\Models\User::find(2),
    'https://your-domain.com/admin/chatify'
));
exit
```

### Test Chat Messaging

1. **Open chat** from panel menu
2. **Select a user** to chat with
3. **Send a message**
4. **Verify:**
   - Message appears in real-time
   - Recipient receives push notification (if subscribed)
   - No errors in browser console
   - No errors in `storage/logs/laravel.log`

## Step 7: Deploy to Production

### Pre-Deployment Checklist

- [ ] All environment variables configured in production `.env`
- [ ] VAPID keys generated and set
- [ ] Database migrations run
- [ ] Composer dependencies installed with `--no-dev --optimize-autoloader`
- [ ] Assets compiled with `npm run build`
- [ ] Caches cleared and rebuilt
- [ ] Supervisor/systemd services configured
- [ ] SSL certificate installed (for wss://

)
- [ ] Firewall rules allow WebSocket port (if needed)

### Deployment Commands

```bash
# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install && npm run build

# Run migrations
php artisan migrate --force

# Clear and rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
sudo supervisorctl restart laravel-queue:*
sudo supervisorctl restart laravel-reverb

# Or with systemd:
sudo systemctl restart laravel-queue
sudo systemctl restart laravel-reverb

# Restart web server (if needed)
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

### Production WebSocket Configuration

Update `.env` for production:

```env
# Production Reverb (with SSL)
REVERB_HOST=yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

### Nginx Configuration for WebSocket

Add to your Nginx site configuration:

```nginx
# WebSocket proxy for Reverb
location /app {
    proxy_pass http://localhost:8080;
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

## Step 8: Verify Installation

Use the verification checklist:

### Quick Verification

```bash
# Check services are running
sudo supervisorctl status

# Or with systemd:
sudo systemctl status laravel-queue
sudo systemctl status laravel-reverb

# Check logs for errors
tail -f storage/logs/laravel.log
tail -f storage/logs/queue.log
tail -f storage/logs/reverb.log
```

### Full Verification

See [`docs/VERIFICATION_CHECKLIST.md`](./VERIFICATION_CHECKLIST.md) for complete manual testing steps.

## Troubleshooting

### Migrations Fail

```bash
# Check database connection
php artisan db:show

# Run migrations one at a time
php artisan migrate --step

# Roll back and retry
php artisan migrate:rollback
php artisan migrate
```

### VAPID Keys Not Working

```bash
# Regenerate keys
php artisan webpush:vapid

# Update .env with new keys
# Clear config cache
php artisan config:clear
php artisan config:cache
```

### Reverb Won't Start

```bash
# Check if port is in use
lsof -i :8080

# Kill existing process
kill -9 <PID>

# Start with debug mode
php artisan reverb:start --debug
```

### Queue Not Processing

```bash
# Check queue table
php artisan queue:monitor

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear all jobs and restart
php artisan queue:clear
php artisan queue:work
```

### Push Notifications Not Working

1. **Check VAPID keys are set:**
   ```bash
   php artisan config:show webpush
   ```

2. **Verify service worker registered:**
   - Open DevTools → Application → Service Workers
   - Should show service worker registered

3. **Check notification permissions:**
   - Browser → Settings → Site Settings → Notifications
   - Ensure site has permission

4. **Monitor logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep "notification"
   ```

### Chat Not Real-Time

1. **Verify Reverb is running:**
   ```bash
   ps aux | grep reverb
   ```

2. **Check WebSocket connection:**
   - Open DevTools → Network → WS tab
   - Should show active WebSocket connection

3. **Verify broadcast driver:**
   ```bash
   php artisan config:show broadcasting
   # Should show: driver => reverb
   ```

## Security Notes

- **Never commit `.env` file** to version control
- **Keep VAPID keys secret** - treat like passwords
- **Use https/wss** in production (not http/ws)
- **Set strong passwords** for Reverb app key and secret
- **Limit file upload types** as configured in `config/chatify.php`
- **Monitor logs** for suspicious activity

## Performance Optimization

### Production Optimizations

```bash
# Optimize Composer autoloader
composer install --optimize-autoloader --no-dev

# Cache everything
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Optimize assets
npm run build

# Enable OPcache in php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
```

### Queue Performance

```bash
# Use multiple queue workers
php artisan queue:work --queue=notifications,default --sleep=1 --tries=3

# Or configure in Supervisor with numprocs=4
```

### Database Indexes

Ensure these indexes exist:

```sql
-- For chat messages
CREATE INDEX idx_ch_messages_to_id ON ch_messages(to_id);
CREATE INDEX idx_ch_messages_from_id ON ch_messages(from_id);
CREATE INDEX idx_ch_messages_seen ON ch_messages(seen);

-- For push subscriptions
CREATE INDEX idx_push_subscriptions_user_id ON push_subscriptions(user_id);
```

## Next Steps

After successful setup:

1. Review [`docs/CHAT_AND_PUSH_NOTIFICATIONS.md`](./CHAT_AND_PUSH_NOTIFICATIONS.md) for usage documentation
2. Check [`docs/CHAT_SECURITY.md`](./CHAT_SECURITY.md) for security best practices
3. Run automated tests: `php artisan test`
4. Complete manual verification checklist
5. Set up monitoring and alerting

## Support Resources

- [Laravel Reverb Documentation](https://laravel.com/docs/11.x/reverb)
- [Chatify Documentation](https://github.com/munafio/chatify)
- [Web Push API](https://developer.mozilla.org/en-US/docs/Web/API/Push_API)
- [Supervisor Documentation](http://supervisord.org/)

---

**Last Updated:** 2026-02-09  
**Status:** Production Ready ✅
