# MBFD Hub - Web Push Notifications Playbook

## Overview

The MBFD Hub implements web push notifications using the Laravel WebPush package, allowing real-time alerts to users even when they're not actively using the application.

## Architecture

### Components

1. **Backend**
   - [`config/webpush.php`](../config/webpush.php) - VAPID configuration
   - [`app/Http/Controllers/Api/PushSubscriptionController.php`](../app/Http/Controllers/Api/PushSubscriptionController.php) - Subscription management
   - [`app/Http/Controllers/Api/TestNotificationController.php`](../app/Http/Controllers/Api/TestNotificationController.php) - Test endpoint
   - [`app/Notifications/TestPushNotification.php`](../app/Notifications/TestPushNotification.php) - Example notification
   - [`app/Models/User.php`](../app/Models/User.php) - Uses `HasPushSubscriptions` trait

2. **Frontend**
   - [`public/sw.js`](../public/sw.js) - Main service worker with push event handlers
   - [`resources/js/push-notification-widget.js`](../resources/js/push-notification-widget.js) - Widget logic with Alpine.js
   - [`resources/js/components/PushNotificationManager.jsx`](../resources/js/components/PushNotificationManager.jsx) - React component
   - [`resources/views/filament/widgets/push-notification-widget.blade.php`](../resources/views/filament/widgets/push-notification-widget.blade.php) - Blade widget

3. **API Endpoints**
   - `GET /api/push/vapid-public-key` - Public VAPID key (unauthenticated)
   - `POST /api/push-subscriptions` - Save subscription (authenticated)
   - `DELETE /api/push-subscriptions` - Remove subscription (authenticated)
   - `POST /api/push/test` - Send test notification (authenticated)

---

## Configuration Setup

### Step 1: Generate VAPID Keys

VAPID keys are required for web push authentication.

```bash
# Generate VAPID keys (adds to .env automatically)
php artisan webpush:vapid
```

This creates three environment variables:
```env
VAPID_PUBLIC_KEY=<base64-encoded-public-key>
VAPID_PRIVATE_KEY=<base64-encoded-private-key>
VAPID_SUBJECT=mailto:admin@mbfd.local
```

**Important**: 
- These keys must remain consistent across deployments
- Never commit them to version control
- If keys change, all existing subscriptions become invalid

### Step 2: Verify Configuration

Check [`config/webpush.php`](../config/webpush.php):

```php
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
    // ...
];
```

### Step 3: Run Migrations

```bash
php artisan migrate
```

This creates the `push_subscriptions` table for storing user subscriptions.

---

## How It Works

### 1. User Subscribes to Notifications

**Client Flow:**

1. Service worker registers: [`/sw.js`](../public/sw.js)
2. User clicks "Enable Push Notifications"
3. Browser requests notification permission
4. If granted, creates push subscription with VAPID public key
5. Subscription sent to server via `POST /api/push-subscriptions`

**Server Flow:**

1. [`PushSubscriptionController::store()`](../app/Http/Controllers/Api/PushSubscriptionController.php) receives subscription
2. Validates `endpoint`, `keys.p256dh`, `keys.auth`
3. Calls `$user->updatePushSubscription()` (from `HasPushSubscriptions` trait)
4. Stores in `push_subscriptions` table

### 2. Sending Notifications

**From Code:**

```php
use App\Notifications\TestPushNotification;

$user->notify(new TestPushNotification());
```

**Notification Class Structure:**

```php
class TestPushNotification extends Notification
{
    public function via($notifiable)
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('Alert Title')
            ->body('Alert message')
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->data(['url' => '/admin/route']);
    }
}
```

### 3. User Receives Notification

**Service Worker ([`sw.js`](../public/sw.js)):**

```javascript
self.addEventListener('push', function(event) {
    const payload = event.data.json();
    const options = {
        body: payload.body,
        icon: payload.icon,
        badge: '/images/mbfd-logo.png',
        data: payload.data,
        vibrate: [200, 100, 200],
        requireInteraction: true
    };
    event.waitUntil(
        self.registration.showNotification(payload.title, options)
    );
});
```

**User Clicks Notification:**

```javascript
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const urlToOpen = event.notification.data?.url || '/admin';
    // Opens URL or focuses existing tab
    event.waitUntil(clients.openWindow(urlToOpen));
});
```

---

## Testing

### 1. Enable Notifications (UI)

1. Log in to MBFD Hub admin panel
2. Look for "Push Notifications" widget on dashboard
3. Click "Enable Push Notifications"
4. Grant browser permission when prompted
5. Widget should show "Push notifications enabled"

### 2. Send Test Notification (UI)

1. After enabling notifications, click "Send Test Notification" button
2. Should receive a test alert within seconds
3. Notification should appear even if browser tab is in background

### 3. Send Test Notification (API)

```bash
# Get auth token (if using Sanctum)
TOKEN="your-api-token"

# Send test notification
curl -X POST https://support.darleyplex.com/api/push/test \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

Expected response:
```json
{
  "success": true,
  "message": "Test notification sent successfully!"
}
```

### 4. Verify Subscription Storage

```bash
php artisan tinker
```

```php
// Check user's subscriptions
$user = User::find(1);
dd($user->pushSubscriptions()->count());

// View subscription details
$user->pushSubscriptions()->get();
```

---

## Troubleshooting

### Issue: No "Enable" Button Shows

**Symptoms:**
- Widget shows loading state indefinitely
- Console shows service worker errors

**Solutions:**

1. **Check HTTPS Requirement**
   - Service workers require HTTPS (or localhost)
   - Verify site is served over HTTPS in production

2. **Verify Service Worker Registration**
   ```javascript
   // In browser console
   navigator.serviceWorker.getRegistrations().then(console.log);
   ```

3. **Check Console for Errors**
   - Open browser DevTools → Console
   - Look for service worker or push API errors

### Issue: VAPID Keys Not Set

**Symptoms:**
- Error: "No VAPID keys configured"
- Subscription fails with authentication error

**Solutions:**

1. **Generate Keys**
   ```bash
   php artisan webpush:vapid
   ```

2. **Verify `.env`**
   ```bash
   grep VAPID .env
   ```

3. **Clear Config Cache**
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

### Issue: Notifications Not Received

**Symptoms:**
- Subscription successful but no notifications appear
- Test endpoint returns success but nothing happens

**Solutions:**

1. **Check Browser Permission**
   - Browser Settings → Site Settings → Notifications
   - Ensure notifications are allowed for the site

2. **Verify Subscription in Database**
   ```sql
   SELECT * FROM push_subscriptions WHERE subscribable_id = <user_id>;
   ```

3. **Check Queue Workers** (if using queues)
   ```bash
   # If notifications are queued
   php artisan queue:work
   ```

4. **Test with Laravel Log**
   ```php
   // Temporarily change notification channel to log
   public function via($notifiable) {
       return ['log']; // Changed from WebPushChannel
   }
   ```

### Issue: iOS Notifications Not Working

**Symptoms:**
- Works on desktop/Android but not iOS
- Widget shows "Add to Home Screen" prompt on iOS

**Cause:**
- iOS requires PWA to be installed (Add to Home Screen)
- Push notifications only work in installed PWAs on iOS

**Solutions:**

1. **User Must Install PWA**
   - Safari → Share → Add to Home Screen
   - Open app from home screen
   - Then enable notifications

2. **Manifest Configuration**
   - Verify [`public/manifest.json`](../public/manifest.json) exists
   - Check `display: "standalone"` is set

### Issue: Service Worker Scope Problems

**Symptoms:**
- Service worker registers but push doesn't work
- Scope mismatch errors in console

**Solutions:**

1. **Verify Registration Scope**
   ```javascript
   navigator.serviceWorker.register('/sw.js', { scope: '/' })
   ```

2. **Check Service Worker Location**
   - Must be in [`public/sw.js`](../public/sw.js) for root scope
   - Cannot control paths above its location

---

## Security Considerations

### 1. VAPID Keys

- **Never expose private key** - Keep in `.env` only
- **Rotate keys periodically** - But understand all subscriptions will need re-subscription
- **Use strong subject** - Should be a valid email or URL

### 2. Subscription Endpoint Validation

- Validate all incoming subscription data
- Check endpoint URL format
- Verify `p256dh` and `auth` keys are present

### 3. Rate Limiting

Apply rate limits to prevent abuse:

```php
// In routes/api.php
Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::post('push/test', [TestNotificationController::class, 'sendTestNotification']);
});
```

### 4. User Authorization

- Only authenticated users can subscribe
- Users can only manage their own subscriptions
- Verify user owns subscription before sending

---

## Production Deployment

### Pre-Deployment Checklist

- [ ] VAPID keys generated and added to production `.env`
- [ ] HTTPS configured and working
- [ ] Service worker accessible at `/sw.js`
- [ ] Push subscriptions migration run
- [ ] Test notification endpoint works
- [ ] Queue workers running (if notifications are queued)

### Environment Variables

```env
VAPID_PUBLIC_KEY=<your-public-key>
VAPID_PRIVATE_KEY=<your-private-key>
VAPID_SUBJECT=mailto:support@mbfd.local

# Optional: If using queue for notifications
QUEUE_CONNECTION=redis
```

### Monitoring

1. **Track Subscription Count**
   ```sql
   SELECT COUNT(*) FROM push_subscriptions;
   ```

2. **Monitor Failed Notifications**
   - Check Laravel logs for push failures
   - Failed subscriptions (expired/invalid) are automatically cleaned

3. **User Engagement**
   - Track which users have enabled notifications
   - Monitor notification click-through rates

---

## Advanced Usage

### Creating Custom Notifications

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class InventoryAlertNotification extends Notification
{
    protected $item;
    protected $currentStock;

    public function __construct($item, $currentStock)
    {
        $this->item = $item;
        $this->currentStock = $currentStock;
    }

    public function via($notifiable)
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('Low Inventory Alert')
            ->body("{$this->item} is running low (Current: {$this->currentStock})")
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->action('View Inventory', 'view_inventory')
            ->data([
                'url' => '/admin/inventory',
                'item_id' => $this->item->id
            ])
            ->options(['TTL' => 3600]) // Expires in 1 hour
            ->vibrate([300, 100, 300]); // Custom vibration pattern
    }
}
```

### Sending to Multiple Users

```php
// Notify all admins
$admins = User::role('admin')->get();
Notification::send($admins, new CriticalAlertNotification());

// Notify specific users
$users = User::whereIn('id', [1, 2, 3])->get();
Notification::send($users, new InventoryAlertNotification($item, $stock));
```

### Notification Actions

Handle notification actions in service worker:

```javascript
// In sw.js
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    if (event.action === 'view_inventory') {
        event.waitUntil(clients.openWindow('/admin/inventory'));
    } else {
        // Default action
        const url = event.notification.data?.url || '/admin';
        event.waitUntil(clients.openWindow(url));
    }
});
```

---

## Maintenance

### Cleaning Up Expired Subscriptions

The Laravel WebPush package automatically cleans up failed subscriptions, but you can manually prune:

```bash
php artisan push:prune
```

Or schedule it:

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('push:prune')->weekly();
}
```

### Viewing Active Subscriptions

```php
// Get all active subscriptions
$subscriptions = \NotificationChannels\WebPush\PushSubscription::all();

// Get subscriptions for a user
$user = User::find(1);
$userSubs = $user->pushSubscriptions;
```

---

## Additional Resources

- [Laravel WebPush Package](https://github.com/laravel-notification-channels/webpush)
- [Web Push Protocol](https://web.dev/push-notifications-overview/)
- [Service Worker API](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [Push API](https://developer.mozilla.org/en-US/docs/Web/API/Push_API)

---

## Quick Reference

### Common Commands

```bash
# Generate VAPID keys
php artisan webpush:vapid

# Test sending notification
php artisan tinker
> User::find(1)->notify(new \App\Notifications\TestPushNotification());

# Check subscriptions
php artisan tinker
> \NotificationChannels\WebPush\PushSubscription::count();

# Clear config cache
php artisan config:clear && php artisan config:cache
```

### File Locations

| Component | Path |
|-----------|------|
| Service Worker | [`public/sw.js`](../public/sw.js) |
| Config | [`config/webpush.php`](../config/webpush.php) |
| Main Widget JS | [`resources/js/push-notification-widget.js`](../resources/js/push-notification-widget.js) |
| Controllers | [`app/Http/Controllers/Api/PushSubscriptionController.php`](../app/Http/Controllers/Api/PushSubscriptionController.php) |
| Test Notification | [`app/Notifications/TestPushNotification.php`](../app/Notifications/TestPushNotification.php) |
| API Routes | [`routes/api.php`](../routes/api.php) |

---

**Document Version:** 1.0  
**Last Updated:** 2026-02-03  
**Author:** MBFD Technical Team
