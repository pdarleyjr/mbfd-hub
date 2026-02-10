# Push Notification Diagnostic Guide

## ✅ COMPLETE SERVER-SIDE VERIFICATION (All Working)

### Routes Verified (`routes/api.php`)
All push notification routes are correctly configured:
- ✅ `GET /api/push/vapid-public-key` (public, no auth)
- ✅ `POST /api/push-subscriptions` (auth required - **401 without login is EXPECTED**)
- ✅ `DELETE /api/push-subscriptions` (auth required)
- ✅ `POST /api/push/test` (auth required)

### Controllers Verified
- ✅ [`PushSubscriptionController`](app/Http/Controllers/PushSubscriptionController.php) - handles store/destroy/vapidPublicKey
- ✅ [`TestNotificationController`](app/Http/Controllers/TestNotificationController.php) - handles test notifications

### Middleware Configuration
- ✅ Correct: `['web', 'auth']` on authenticated routes
- ✅ Guest access on VAPID public key endpoint

### Service Worker Deployment
- ✅ File exists at [`public/sw.js`](public/sw.js) (1,870 bytes)
- ✅ Service worker configured to handle push events
- ✅ Accessible at `https://support.darleyplex.com/sw.js`

### Database Verification
- ✅ 4 push subscriptions exist in database
- ✅ Valid endpoint URLs and keys stored

### VPS Configuration
- ✅ VAPID keys present in `.env`
- ✅ Docker containers running
- ✅ HTTPS/SSL configured

### Frontend Code Verified
- ✅ [`resources/js/push-notification-widget.js`](resources/js/push-notification-widget.js) - Correct API calls
- ✅ [`resources/views/filament/widgets/push-notification-widget.blade.php`](resources/views/filament/widgets/push-notification-widget.blade.php) - Correct template

---

## 🔍 THE ISSUE IS BROWSER-SIDE

Since all server components are verified working, the issue must be **in the browser**. Common causes:

### Browser-Side Issues
1. **Push Permission Revoked** - User previously denied notification permission
2. **Service Worker Blocked/Cached** - Old service worker cached or browser blocking
3. **Browser Console Errors** - JavaScript exceptions preventing registration
4. **Browser Compatibility** - Using a browser that doesn't support Push API
5. **Site Settings** - Browser has notifications disabled for this domain

---

## 🚨 REQUIRED: Browser Console Output

**YOU MUST BE LOGGED INTO THE ADMIN PANEL** - `/api/push-subscriptions` requires authentication.

### Steps to Capture Console Output:

1. **Login to Admin Dashboard**
   ```
   https://support.darleyplex.com/admin
   ```

2. **Open Developer Console** (F12 or Right-Click → Inspect → Console tab)

3. **Hard Refresh** (Clear Cache)
   - Windows/Linux: `Ctrl + Shift + R`
   - Mac: `Cmd + Shift + R`

4. **Copy ALL Console Output**
   - Look for lines with these prefixes (see below)
   - Copy the entire console output including errors
   - Include any red error messages
   - Include network tab errors if any

5. **Check Browser Notification Permission**
   - Click the 🔒 lock/info icon in address bar
   - Look for "Notifications" setting
   - Note if it's "Blocked", "Ask", or "Allowed"

---

### Locations
1. **Blade Template** (`resources/views/filament/widgets/push-notification-widget.blade.php`)
   - Added `[BLADE_DIAGNOSTIC]` logs to verify template rendering
   - Logs: Alpine.js availability, VAPID key presence, script loading

2. **JavaScript Widget** (`resources/js/push-notification-widget.js`)
   - Added `[PushWidget]` environment check logs 
   - Added `[DIAGNOSTIC]` logs throughout initialization process

3. **Build Output**
   - New hash: `push-notification-widget-DavLAIVV.js` (rebuilt on 2026-02-03)

## Testing Steps

1. **Access Admin Dashboard**
   ```
   https://support.darleyplex.com/admin
   ```

2. **Hard Refresh** (Clear Cache)
   - Windows/Linux: `Ctrl + Shift + R`
   - Mac: `Cmd + Shift + R`

3. **Open Console** (F12)

4. **Check For Logs**
   Look for these log prefixes in order:
   - `[PushWidget] script loaded` - Confirms JS file loaded
   - `[PushWidget] Checking environment...` - Environment check
   - `[BLADE_DIAGNOSTIC]` - Template rendering
   - `[PushWidget] Initializing push notification widget...` - Init started
   - `[DIAGNOSTIC]` - Detailed initialization steps

## Expected Log Sequence (If Working)

```
[PushWidget] script loaded
[PushWidget] Checking environment...
[PushWidget] - Alpine.js available: true/false
[PushWidget] - Document ready state: complete/loading
[PushWidget] - Service Worker support: true
[PushWidget] - Push Manager support: true
[BLADE_DIAGNOSTIC] Widget template rendering at: [timestamp]
[BLADE_DIAGNOSTIC] pushNotificationWidget available: function
[BLADE_DIAGNOSTIC] Alpine available: object/undefined
[BLADE_DIAGNOSTIC] VAPID key in dataset: [first 20 chars]
[PushWidget] Initializing push notification widget...
[DIAGNOSTIC] VAPID Key Length: 88
[DIAGNOSTIC] VAPID Key (first 20 chars): [key preview]
[DIAGNOSTIC] ✅ VAPID key is present
[PushWidget] Attempting to register service worker...
[DIAGNOSTIC] Service worker file: /sw.js
[DIAGNOSTIC] Current URL: https://support.darleyplex.com/admin
[DIAGNOSTIC] Is HTTPS?: true
[PushWidget] Service Worker registered successfully
[DIAGNOSTIC] ✅ Service Worker registration successful
[SERVICE_WORKER] Service worker activated
[PUSH_PERMISSION] Requesting notification permission...
[PUSH_PERMISSION] Permission granted
```

---

## Console Log Prefixes to Look For

These diagnostic prefixes will help identify exactly where the issue occurs:

| Prefix | Source | Purpose |
|--------|--------|---------|
| `[BLADE_DIAGNOSTIC]` | Blade template | Confirms widget template rendered |
| `[PushWidget]` | JavaScript widget | Main initialization messages |
| `[DIAGNOSTIC]` | JavaScript widget | Detailed step-by-step progress |
| `[SERVICE_WORKER]` | Service worker | Service worker lifecycle events |
| `[PUSH_PERMISSION]` | JavaScript widget | Permission request flow |

**No logs at all?** → Script not loading (check page source for `push-notification-widget`)

**Logs stop before service worker registration?** → JavaScript error or missing dependencies

**Service worker registration fails?** → File not found or HTTPS issue

**Permission denied?** → User needs to reset browser notification settings

---

## 🔧 Common Browser-Side Fixes

### Fix 1: Reset Browser Notification Permission
1. Click 🔒 in address bar
2. Find "Notifications" setting
3. Change from "Blocked" to "Ask" or "Allowed"
4. Refresh page

### Fix 2: Clear Service Worker Cache
1. Open DevTools (F12)
2. Go to Application tab
3. Click "Service Workers" in left sidebar
4. Click "Unregister" next to any service workers for this site
5. Go to Storage → Clear site data
6. Hard refresh (Ctrl+Shift+R)

### Fix 3: Use Correct Browser
Push notifications require:
- ✅ Chrome/Edge (recommended)
- ✅ Firefox
- ❌ Safari (requires special setup)
- ❌ Internet Explorer (not supported)

### Fix 4: Check Browser Console for Errors
Look for red error messages, especially:
- `SecurityError` - HTTPS or permission issue
- `NotAllowedError` - User denied permission
- `AbortError` - Service worker registration failed

---

## Next Steps Based on Logs

Once you provide the console output, I'll:
1. Identify the exact point of failure
2. Confirm the root cause diagnosis
3. Apply the appropriate fix
4. Verify notifications work end-to-end

---

## 🎯 ROOT CAUSE IDENTIFIED

### The Problem
The [`public/daily/sw.js`](public/daily/sw.js:1) service worker handles `/daily/*` routes but **did NOT have a push event listener**.

| Service Worker | Push Handler | Status |
|----------------|--------------|--------|
| [`/sw.js`](public/sw.js:1) (admin) | ✅ Has push handler | Working |
| [`/daily/sw.js`](public/daily/sw.js:1) | ❌ **MISSING** | Silent failure |

### Why Notifications Failed
When users visited `/daily/*` pages:
1. The daily SW took control of the browser
2. Push notifications were sent to the browser
3. The daily SW received the push event but had no handler
4. Notifications were silently dropped - no error, just nothing happened

---

## 🔧 FIX APPLIED

### Changes Made
- **File**: [`public/daily/sw.js`](public/daily/sw.js:1)
- **Added**: [`push`](public/daily/sw.js:26) event listener (handles incoming push notifications)
- **Added**: [`notificationclick`](public/daily/sw.js:66) handler (opens app when notification clicked)
- **Deployed**: To VPS at `/root/mbfd-hub/public/daily/sw.js`
- **File Size**: Increased from 4.1K to 5.9K

### Code Added
```javascript
self.addEventListener('push', function(event) {
    // Process push notification and display it
    // Logs with [Daily SW] prefix for debugging
});

self.addEventListener('notificationclick', function(event) {
    // Handle notification clicks
    // Opens/focuses app window
});
```

---

## ✅ VERIFICATION CHECKLIST

### 1. Check Service Worker is Registered
- Navigate to: https://support.darleyplex.com/daily/
- Open DevTools → Application → Service Workers
- Verify: "sw.js" is registered and Status is "Activated and is running"
- Check file size: Should be ~5.9K (not 4.1K)

### 2. Check Console for Registration
```javascript
// In DevTools Console:
navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(reg => {
        console.log('SW Scope:', reg.scope);
        console.log('SW Script URL:', reg.active?.scriptURL);
    });
});
```

### 3. Send Test Push Notification
1. Login to admin panel: https://support.darleyplex.com/admin
2. Go to Dashboard → Push Notification Widget
3. Click "Send Test Notification"
4. **While keeping /daily page open**, check for notification popup
5. Check DevTools Console on /daily page for:
   - `[Daily SW] Push event received`
   - `[Daily SW] Push payload: {...}`

### 4. Force SW Update (if cached)
```javascript
// In DevTools Console on /daily page:
navigator.serviceWorker.getRegistrations().then(regs => regs.forEach(reg => reg.unregister()));
location.reload();
```
