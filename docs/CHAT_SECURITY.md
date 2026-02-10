# Chat Security Documentation

This document outlines the security measures implemented for the Chatify messaging integration in the MBFD Support Hub application.

## Authentication & Authorization

### Route Protection

All Chatify routes are protected with authentication middleware:

```php
'middleware' => ['web', 'auth']
```

**Security Implications:**
- No public access to chat endpoints
- Users must be logged in to access any chat functionality
- Session-based authentication via Laravel's web middleware
- CSRF protection included via web middleware

### API Routes

API routes use the `api` middleware group:

```php
'api_routes' => [
    'middleware' => env('CHATIFY_API_ROUTES_MIDDLEWARE', ['api']),
]
```

## File Upload Security

### Allowed File Types

**Image Files (allowed_images):**
- `png`
- `jpg`
- `jpeg`
- `gif`

**Document Files (allowed_files):**
- `pdf`
- `doc`
- `docx`
- `xls`
- `xlsx`
- `zip`

**Configuration Location:** [`config/chatify.php`](../config/chatify.php:89-91)

### Upload Restrictions

- **Maximum Upload Size:** 10MB (10,240 KB)
- **Storage Disk:** `public` (configurable via `CHATIFY_STORAGE_DISK`)
- **Upload Folder:** `/attachments` within the storage disk

**Security Best Practices:**
- Only safe, common business file types are allowed
- Executable files (`.exe`, `.sh`, `.bat`, etc.) are blocked
- Script files (`.php`, `.js`, `.py`, etc.) are blocked
- File size limit prevents DoS via large uploads
- Files are scanned by extension before processing

## WebSocket Security

### Development Environment

```env
REVERB_SCHEME=http
REVERB_HOST=localhost
REVERB_PORT=8080
```

**Connection:** Uses `ws://` protocol for local development

### Production Environment

```env
REVERB_SCHEME=https
REVERB_HOST=your-domain.com
REVERB_PORT=443
```

**Connection:** Uses `wss://` (WebSocket Secure) protocol

**Security Requirements:**
- Must use HTTPS when site is served over HTTPS
- Cloudflare SSL mode should be Full or Strict (NOT Flexible)
- TLS encryption enabled: `'useTLS' => true`
- Encrypted connections enforced: `'encrypted' => true`

**Configuration Location:** [`config/chatify.php`](../config/chatify.php:42-55)

## Rate Limiting

### Chat Message Notifications

Rate limiting is applied to prevent notification spam:

- **Limit:** 1 notification per 30 seconds per sender-recipient pair
- **Implementation:** [`ChMessageObserver`](../app/Observers/ChMessageObserver.php)
- **Key Format:** `chat-notification:{sender_id}:{recipient_id}`
- **Decay:** 30 seconds

**Behavior:**
- When rate limit is hit, notification is silently skipped
- Rate limit only applies to push notifications, not messages themselves
- Users can still send multiple messages; only notifications are throttled
- Each unique sender-recipient pair has independent rate limits

**Logging:**
- Rate limit hits are logged at `DEBUG` level
- Notification successes logged at `INFO` level
- Notification failures logged at `ERROR` level with stack traces

## Push Notification Security

### VAPID Keys

VAPID (Voluntary Application Server Identification) keys are used for web push authentication:

```env
VAPID_SUBJECT=mailto:your-email@example.com
VAPID_PUBLIC_KEY=<generated-key>
VAPID_PRIVATE_KEY=<generated-key>
```

**Security Practices:**
- Keys generated via `php artisan webpush:vapid`
- Private key MUST be kept secret
- Public key can be shared with browsers
- Subject should be a valid contact email
- All keys stored in `.env` (never committed to git)

**Configuration Location:** [`config/webpush.php`](../config/webpush.php:9-14)

### Notification Content

Push notifications contain limited data to protect privacy:

- Sender name (not full message content)
- First 100 characters of message (truncated)
- Message ID and sender ID (for linking)
- TTL (Time To Live): 1 hour

**Implementation:** [`ChatMessageReceived`](../app/Notifications/ChatMessageReceived.php)

## Error Handling & Logging

### ChMessageObserver Error Handling

All notification sending is wrapped in try-catch blocks:

```php
try {
    $recipient->notify(new ChatMessageReceived($sender, $message));
} catch (\Exception $e) {
    Log::error('Chat message notification failed', [
        'message_id' => $message->id,
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
```

**Benefits:**
- Prevents notification failures from breaking chat functionality
- Provides detailed error context for debugging
- Stack traces help identify root causes

## Environment Variable Security

### Secrets Management

**Never commit these values:**
- `VAPID_PRIVATE_KEY`
- `VAPID_PUBLIC_KEY`
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`

**Best Practices:**
- Use `.env` for all secrets (gitignored)
- Use `.env.example` with placeholder values only
- All config files use `env()` helper
- No hardcoded secrets in configuration
- Rotate keys if compromised

### Example Configuration

See [`.env.example`](../.env.example) for proper placeholder format.

## Queue Configuration

### Chat Notifications

Notifications are queued for better performance:

- **Interface:** `ShouldQueue`
- **Queue Connection:** `database` (configured in `.env`)
- **Queue Name:** `notifications` (for WebPush channel)

**Benefits:**
- Non-blocking message sending
- Failed notifications can be retried
- Better scalability under load

**Requirements:**
- Queue worker must be running: `php artisan queue:work`
- Use Supervisor or similar for production

## Panel Parity

Both Admin and Training panels have equal chat functionality:

### Admin Panel
- ✅ ChatifyPlugin registered
- ✅ PushNotificationWidget available
- ✅ Full chat access

### Training Panel
- ✅ ChatifyPlugin registered
- ✅ PushNotificationWidget available
- ✅ Full chat access

**Configuration:**
- [`AdminPanelProvider.php`](../app/Providers/Filament/AdminPanelProvider.php:59,71)
- [`TrainingPanelProvider.php`](../app/Providers/Filament/TrainingPanelProvider.php:46,49-51)

## Security Checklist

- [x] Routes protected with `auth` middleware
- [x] File upload types restricted to safe formats
- [x] File upload size limited to 10MB
- [x] WebSocket connections use `wss://` in production
- [x] VAPID keys stored in environment variables
- [x] No secrets committed in config files
- [x] Rate limiting prevents notification spam
- [x] Error logging provides audit trail
- [x] Push notifications queued for reliability
- [x] Both panels have equal functionality

## Monitoring & Auditing

### Log Locations

- **Chat Notifications:** Search logs for `ChatMessageObserver`
- **Rate Limiting:** Look for "Rate limit hit" messages
- **Errors:** Search for "notification failed" at ERROR level

### Recommended Monitoring

1. Monitor notification failure rates
2. Track rate limit hits to detect abuse
3. Alert on repeated WebSocket connection failures
4. Review file upload patterns for anomalies

## Updates & Maintenance

**Last Updated:** 2026-02-09
**Reviewed By:** Operational Hardening - Phase 5
**Next Review:** Quarterly or after security incidents
