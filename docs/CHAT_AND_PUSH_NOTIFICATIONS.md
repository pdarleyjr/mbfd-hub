# Chat and Push Notifications Documentation

## Overview

This document describes the chat system and push notification implementation across both Logistics (Admin) and Training panels in the MBFD Support Services application.

## Features

- **Real-time chat** between users using Filament Chatify Integration
- **Web push notifications** for new chat messages
- **Push notification widgets** in both Admin and Training panels
- **Rate-limited notifications** to prevent spam (1 notification per 30 seconds per sender-recipient pair)
- **File sharing** support with security restrictions
- **Queue-based processing** for scalability and reliability

## Architecture

### Components

#### 1. Chatify (munzermonzer/chatify)
- Provides chat UI and message storage
- Manages user conversations and message history
- Handles file uploads for chat attachments
- Database tables: `ch_messages`, `ch_favorites`

#### 2. Laravel Reverb
- Real-time WebSocket transport layer
- Enables instant message delivery
- Broadcasting infrastructure for live updates
- Production-ready with SSL/TLS support

#### 3. WebPush (laravel-notification-channels/webpush)
- Browser push notifications via service worker
- VAPID protocol for secure delivery
- Works across modern browsers (Chrome, Firefox, Edge)
- Offline notification delivery

#### 4. ChMessageObserver
- Listens for new chat messages
- Triggers notifications on message creation
- Implements rate limiting logic
- Handles error logging and recovery

### Data Flow

```
┌─────────────┐
│ User sends  │
│ message via │  1. Message created
│ Chatify UI  │────────────────────┐
└─────────────┘                    │
                                   ▼
                        ┌──────────────────┐
                        │ ch_messages      │
                        │ table (database) │
                        └──────────────────┘
                                   │
                                   │ 2. Observer detects
                                   ▼
                        ┌──────────────────┐
                        │ ChMessageObserver│
                        │ - Checks if seen │
                        │ - Rate limiting  │
                        └──────────────────┘
                                   │
                                   │ 3. If allowed
                                   ▼
                        ┌──────────────────┐
                        │ ChatMessage-     │
                        │ Received         │
                        │ notification     │
                        └──────────────────┘
                                   │
                                   │ 4. Queued for delivery
                                   ▼
                        ┌──────────────────┐
                        │ Queue Worker     │
                        │ processes        │
                        └──────────────────┘
                                   │
                                   │ 5. Push sent
                                   ▼
                        ┌──────────────────┐
                        │ User's browser   │
                        │ Service Worker   │
                        │ displays popup   │
                        └──────────────────┘
```

## Configuration

### Environment Variables

Required in `.env`:

```env
# VAPID Keys for Web Push (generate with: php artisan webpush:vapid)
VAPID_PUBLIC_KEY=your_public_key_here
VAPID_PRIVATE_KEY=your_private_key_here
VAPID_SUBJECT=mailto:admin@example.com

# Laravel Reverb (WebSocket Server)
REVERB_APP_ID=app-id
REVERB_APP_KEY=app-key
REVERB_APP_SECRET=app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Production Reverb (with SSL)
# REVERB_HOST=your-domain.com
# REVERB_PORT=443
# REVERB_SCHEME=https

# Broadcasting
BROADCAST_DRIVER=reverb

# Queue Configuration (for async notifications)
QUEUE_CONNECTION=database
```

### File Upload Limits

Configured in [`config/chatify.php`](../config/chatify.php):

```php
'attachments' => [
    'folder' => 'storage/attachments',
    'max_upload_size' => 10240,  // 10MB in KB
    'allowed_images' => ['png', 'jpg', 'jpeg', 'gif'],
    'allowed_files' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'],
],
```

**Security Notes:**
- Executable files (`.exe`, `.sh`, `.bat`) are blocked
- Script files (`.php`, `.js`, `.py`) are blocked
- Only business-safe file types allowed
- 10MB max prevents DoS attacks

### Panel Configuration

Both panels (Admin and Training) include identical chat and notification features:

**Admin Panel:** [`app/Providers/Filament/AdminPanelProvider.php`](../app/Providers/Filament/AdminPanelProvider.php)
```php
->plugin(ChatifyPlugin::make())
->widgets([
    PushNotificationWidget::class,
])
```

**Training Panel:** [`app/Providers/Filament/TrainingPanelProvider.php`](../app/Providers/Filament/TrainingPanelProvider.php)
```php
->plugin(ChatifyPlugin::make())
->widgets([
    PushNotificationWidget::class,
])
```

## Security

### Authentication & Authorization

All chat routes require authentication:

```php
// config/chatify.php
'routes' => [
    'middleware' => ['web', 'auth'],  // Authentication required
]
```

- Unauthenticated users are redirected to login
- CSRF protection via `web` middleware
- No public access to chat endpoints

### File Upload Security

- **Allowed Image Types:** png, jpg, jpeg, gif
- **Allowed File Types:** pdf, doc, docx, xls, xlsx, zip
- **Blocked:** All executable and script files
- **Max Size:** 10MB per file
- Extension-based validation prevents malicious uploads

### WebSocket Security

- **Development:** `ws://localhost:8080`
- **Production:** `wss://your-domain.com` (encrypted)
- TLS encryption required in production
- Authentication required for WebSocket connections

### Rate Limiting

Prevents notification spam:

```php
// 1 notification per 30 seconds per sender-recipient pair
$cacheKey = "chat_notification_{$sender->id}_{$recipient->id}";
if (Cache::has($cacheKey)) {
    return;  // Skip notification
}
Cache::put($cacheKey, true, now()->addSeconds(30));
```

### VAPID Key Management

- **Generate:** `php artisan webpush:vapid`
- **Store:** In `.env` file only (never commit)
- **Rotate:** Regenerate if compromised
- **Subject:** mailto: address for push provider contact

## Testing

### Automated Tests

Run the test suite:

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run specific test file
php artisan test tests/Feature/Chat/ChatMessageNotificationTest.php

# Run with coverage (requires Xdebug)
php artisan test --coverage
```

### Test Coverage

24 automated tests covering:

1. **Notification Dispatch** (5 tests)
   - Notification sent for new messages
   - No notification for seen messages
   - Rate limiting enforcement
   - Support for different senders
   - Sender information inclusion

2. **Authorization** (4 tests)
   - Guest redirection from chat routes
   - Authenticated user access
   - API endpoint protection

3. **Panel Integration** (8 tests)
   - Panel access control
   - Widget presence verification
   - Script inclusion checks

4. **Notification Content** (7 tests)
   - Message formatting
   - Truncation of long messages
   - Icon and badge configuration
   - Action data structure
   - Queue configuration

### Manual Testing Checklist

See [`docs/VERIFICATION_CHECKLIST.md`](./VERIFICATION_CHECKLIST.md) for complete manual testing procedures.

## Troubleshooting

### Push Notifications Not Appearing

**Symptoms:** Browser notifications don't show up

**Solutions:**

1. **Check VAPID Keys**
   ```bash
   # Verify keys are set in .env
   php artisan config:cache
   ```

2. **Verify Service Worker**
   - Open browser DevTools → Application → Service Workers
   - Ensure service worker is registered and active
   - Check for registration errors in console

3. **Check Browser Permissions**
   - Click lock icon in address bar
   - Verify notifications are "Allowed"
   - Try resetting permissions and re-subscribing

4. **Inspect Console Logs**
   - Look for WebPush errors
   - Check for subscription failures
   - Verify VAPID public key is correct

### Chat Not Real-Time

**Symptoms:** Messages don't appear instantly

**Solutions:**

1. **Verify Reverb is Running**
   ```bash
   php artisan reverb:start
   # Should show: "Reverb server started on..."
   ```

2. **Check Broadcasting Configuration**
   ```bash
   # Verify .env has:
   BROADCAST_DRIVER=reverb
   ```

3. **Check WebSocket Connection**
   - Open browser DevTools → Network → WS
   - Look for WebSocket connection
   - Should show connected status

4. **Clear Caches**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan route:clear
   ```

### Rate Limiting Issues

**Symptoms:** Expected notifications not received

**Solutions:**

1. **Check Cache**
   ```bash
   # Clear rate limit cache
   php artisan cache:clear
   ```

2. **Adjust Rate Limit**
   - Edit [`app/Observers/ChMessageObserver.php`](../app/Observers/ChMessageObserver.php)
   - Modify `addSeconds(30)` to desired interval

3. **Monitor Logs**
   ```bash
   tail -f storage/logs/laravel.log | grep "Rate limit hit"
   ```

### Queue Not Processing

**Symptoms:** Notifications delayed or not sent

**Solutions:**

1. **Start Queue Worker**
   ```bash
   php artisan queue:work
   # Or in production with Supervisor/systemd
   ```

2. **Check Queue Configuration**
   ```bash
   # Verify .env has:
   QUEUE_CONNECTION=database
   ```

3. **Monitor Queue**
   ```bash
   php artisan queue:monitor
   php artisan queue:failed
   ```

4. **Retry Failed Jobs**
   ```bash
   php artisan queue:retry all
   ```

## Production Deployment

### Pre-Deployment Checklist

- [ ] VAPID keys generated and set in `.env`
- [ ] Reverb configured with wss:// (SSL)
- [ ] Queue worker running via Supervisor/systemd
- [ ] File upload limits tested
- [ ] Rate limiting verified
- [ ] Both panels tested

### Production Services

**Queue Worker (Supervisor Config):**

```ini
[program:laravel-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/log/queue.log
```

**Reverb Server (Supervisor Config):**

```ini
[program:laravel-reverb]
process_name=%(program_name)s
command=php /path/to/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/log/reverb.log
```

### Monitoring

Key metrics to track:

1. **Queue Length:** `php artisan queue:monitor`
2. **Notification Success Rate:** Check logs for failures
3. **WebSocket Connections:** Monitor Reverb server
4. **Rate Limit Hits:** Track in logs
5. **File Upload Activity:** Monitor storage usage

### Error Logging

All errors are logged with full context:

```php
Log::error('Chat message notification failed', [
    'message_id' => $message->id,
    'sender_id' => $sender->id,
    'recipient_id' => $recipient->id,
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString()
]);
```

Query recent errors:
```bash
grep "notification failed" storage/logs/laravel.log
```

## API Reference

### Chatify Routes

All routes require authentication (`auth` middleware):

- `GET /internal/chatify` - Main chat interface
- `GET /internal/chatify/api/search` - Search users
- `POST /internal/chatify/api/sendMessage` - Send message
- `POST /internal/chatify/api/uploadAttachment` - Upload file

### Push Notification API

**Subscribe:**
```javascript
// PushNotificationManager.jsx
const subscription = await serviceWorkerRegistration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
});

// Send to server
await fetch('/api/push-subscriptions', {
    method: 'POST',
    body: JSON.stringify(subscription)
});
```

**Unsubscribe:**
```javascript
await subscription.unsubscribe();
await fetch(`/api/push-subscriptions/${id}`, { method: 'DELETE' });
```

## Related Documentation

- [`docs/CHAT_SETUP_GUIDE.md`](./CHAT_SETUP_GUIDE.md) - Step-by-step setup
- [`docs/CHAT_SECURITY.md`](./CHAT_SECURITY.md) - Security details
- [`docs/COMMANDS_REFERENCE.md`](./COMMANDS_REFERENCE.md) - All commands
- [`docs/VERIFICATION_CHECKLIST.md`](./VERIFICATION_CHECKLIST.md) - Testing checklist
- [`IMPLEMENTATION_REPORT.md`](../IMPLEMENTATION_REPORT.md) - Complete implementation history

## Support

For issues or questions:

1. Check this documentation
2. Review logs: `storage/logs/laravel.log`
3. Run tests: `php artisan test`
4. Check service status: Reverb and queue workers

---

**Last Updated:** 2026-02-09  
**Version:** 1.0.0  
**Status:** Production Ready ✅
