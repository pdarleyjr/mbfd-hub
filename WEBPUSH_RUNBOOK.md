# Web Push Notification Runbook
## Miami Beach Fire Department (MBFD) Hub

---

## 1. Overview

### What Are Web Push Notifications?

Web push notifications are alerts that appear on your device (phone, tablet, or computer) to notify you of important updates from the MBFD Hub. These notifications work similarly to text messages or app notifications, but they come directly from the website.

### Why They Matter for MBFD Hub

Push notifications help you stay informed about:
- **Critical alerts** and emergency notifications
- **Equipment updates** and maintenance reminders
- **Schedule changes** and shift updates
- **Important announcements** from administration

### Supported Platforms

- **iOS** (iPhone/iPad) - iOS 16.4 or later required
- **Android** devices
- **Desktop** computers (Chrome, Edge, Firefox browsers)

---

## 2. iOS Installation Instructions (CRITICAL)

### ⚠️ Important: iOS 16.4+ Requirement

**Apple requires iOS 16.4 or later to receive web push notifications.** If your device is running an earlier version, you must update it first.

**To check your iOS version:**
1. Open **Settings** on your iPhone/iPad
2. Tap **General**
3. Tap **About**
4. Look for **Software Version**
5. If it's below 16.4, go to **Settings > General > Software Update** to update

### Step-by-Step: Add to Home Screen

**This step is REQUIRED for iOS devices.** Apple only allows web push notifications from apps added to the home screen.

1. **Open Safari** on your iPhone/iPad
2. Navigate to: **https://support.darleyplex.com**
3. **Log in** with your credentials:
   - Username: `MiguelAnchia@miamibeachfl.gov`
   - Password: `Penco1`
4. Tap the **Share button** (square with arrow pointing up) at the bottom of the screen
5. Scroll down and tap **"Add to Home Screen"**
6. Enter a name for the app (e.g., "MBFD Hub")
7. Tap **"Add"** in the top-right corner
8. The MBFD Hub icon will now appear on your home screen

### Why This Is Required

Apple treats websites added to the home screen as "Progressive Web Apps" (PWAs). Only PWAs can send push notifications on iOS devices. This is an Apple security requirement.

---

## 3. Enabling Notifications

### Desktop (Chrome/Edge)

**Step-by-Step Instructions:**

1. Open **Chrome** or **Edge** browser on your computer
2. Navigate to: **https://support.darleyplex.com**
3. **Log in** with your credentials
4. Click the **"Enable Notifications"** button in the notification widget (usually in the top-right corner)
5. A browser prompt will appear asking for permission
6. Click **"Allow"** to enable notifications
7. You should see a confirmation message: "Notifications enabled successfully"

**Screenshot Placeholder:**
```
[Browser permission prompt showing "Allow" and "Block" buttons]
```

### Mobile (Android/Chrome)

**Step-by-Step Instructions:**

1. Open **Chrome** browser on your Android device
2. Navigate to: **https://support.darleyplex.com**
3. **Log in** with your credentials
4. Tap the **"Enable Notifications"** button
5. A browser prompt will appear asking for permission
6. Tap **"Allow"** to enable notifications
7. You should see a confirmation message

**Screenshot Placeholder:**
```
[Android permission dialog showing "Allow" and "Block" buttons]
```

### iOS (Safari)

**Important:** You must have added the MBFD Hub to your home screen first (see Section 2).

**Step-by-Step Instructions:**

1. Tap the **MBFD Hub icon** on your home screen (not Safari)
2. The app will open and you should be logged in
3. Tap the **"Enable Notifications"** button
4. A system prompt will appear asking for permission
5. Tap **"Allow"** to enable notifications
6. You should see a confirmation message

**Screenshot Placeholder:**
```
[iOS permission prompt showing "Allow" and "Don't Allow" buttons]
```

---

## 4. Testing Notifications

### How to Send a Test Notification

1. Navigate to: **https://support.darleyplex.com/admin**
2. **Log in** with your admin credentials:
   - Username: `MiguelAnchia@miamibeachfl.gov`
   - Password: `Penco1`
3. Look for the **"Push Notification"** widget in the admin dashboard
4. Click the **"Send Test Notification"** button
5. Wait 10-30 seconds for the notification to arrive

### What to Expect

When the test notification arrives, you should see:
- A notification banner or alert on your device
- The notification title: "MBFD Hub Test"
- The notification body: "This is a test notification from MBFD Hub"
- A sound or vibration (if enabled in your device settings)

### How to Verify It's Working

✅ **Success Indicators:**
- Notification appears on your device
- You can tap it to open the MBFD Hub
- No error messages appear in the browser console

❌ **Failure Indicators:**
- No notification appears after 30 seconds
- Error message appears on screen
- Browser console shows errors (see Section 5)

---

## 5. Troubleshooting

### Common Issues

#### Issue: "Notifications Not Appearing"

**Possible Causes:**
- Notifications are blocked in browser settings
- Device is in "Do Not Disturb" mode
- Browser is not running (desktop only)
- Service worker failed to register

**Solutions:**

1. **Check Browser Permissions:**
   - **Chrome/Edge:** Click the lock icon in the address bar → Site settings → Notifications → Set to "Allow"
   - **Safari (iOS):** Settings → Safari → Notifications → Find MBFD Hub → Enable

2. **Check Device Settings:**
   - **iOS:** Settings → Notifications → MBFD Hub → Enable "Allow Notifications"
   - **Android:** Settings → Apps → Chrome → Notifications → Enable
   - **Desktop:** Check system notification settings

3. **Restart Browser:**
   - Close and reopen your browser
   - For iOS, close the MBFD Hub app and reopen it

#### Issue: "Enable Notifications Button Does Nothing"

**Possible Causes:**
- Service worker is not registered
- VAPID keys are not configured
- Browser doesn't support push notifications

**Solutions:**

1. **Check Browser Compatibility:**
   - Ensure you're using a supported browser (Chrome, Edge, Firefox, Safari)
   - Update your browser to the latest version

2. **Clear Browser Cache:**
   - **Chrome/Edge:** Ctrl+Shift+Delete → Clear browsing data
   - **Safari:** History → Clear History

3. **Check Browser Console for Errors:**
   - Right-click → Inspect → Console tab
   - Look for red error messages
   - Report errors to IT support

#### Issue: "Service Worker Registration Failed"

**Possible Causes:**
- Service worker file is missing or corrupted
- HTTPS is not enabled
- Browser security settings

**Solutions:**

1. **Verify HTTPS:**
   - Ensure you're accessing `https://support.darleyplex.com` (not http://)
   - Look for the lock icon in the address bar

2. **Check Service Worker Status:**
   - Open browser DevTools (F12)
   - Go to Application tab → Service Workers
   - Check if service worker is active and running

3. **Unregister and Re-register:**
   - In DevTools, click "Unregister" on the service worker
   - Refresh the page
   - Click "Enable Notifications" again

#### Issue: "Permission Denied"

**Possible Causes:**
- User previously blocked notifications
- Browser settings prevent notifications
- Device restrictions

**Solutions:**

1. **Reset Permissions:**
   - **Chrome/Edge:** Click lock icon → Site settings → Notifications → Reset permissions
   - **Safari:** Settings → Safari → Clear History and Website Data

2. **Re-enable Notifications:**
   - Follow the instructions in Section 3 again
   - Make sure to click "Allow" when prompted

### Diagnostic Steps

#### Step 1: Check Browser Console for Errors

1. Open the MBFD Hub in your browser
2. Right-click anywhere on the page
3. Select **"Inspect"** or **"Inspect Element"**
4. Click the **"Console"** tab
5. Look for red error messages
6. Take a screenshot of any errors for IT support

**Common Error Messages:**
- `ServiceWorker registration failed`
- `PushManager not supported`
- `VAPID keys not configured`
- `Permission denied`

#### Step 2: Verify VAPID Keys Are Configured

**For IT Support Only:**

VAPID keys should be configured in the Laravel `.env` file:
```
VAPID_PUBLIC_KEY=your_public_key_here
VAPID_PRIVATE_KEY=your_private_key_here
```

To verify:
1. Access the server
2. Check the `.env` file
3. Ensure both keys are present and valid

#### Step 3: Check Service Worker Status

1. Open browser DevTools (F12)
2. Go to **Application** tab
3. Click **Service Workers** in the left sidebar
4. Check the status:
   - **Active:** Service worker is running correctly
   - **Redundant:** Service worker is not active
   - **Stopped:** Service worker has stopped

### Emergency Rollback

**If web push notifications are causing issues:**

1. **Disable Feature Flag:**
   - Access the Laravel application
   - Open `config/services.php`
   - Set `'push_enabled' => false`

2. **Clear Cache:**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

3. **Notify Users:**
   - Send an email to all users
   - Post an announcement on the MBFD Hub
   - Inform them that notifications are temporarily disabled

**Who to Contact:**
- **Primary IT Contact:** Miguel Anchia (MiguelAnchia@miamibeachfl.gov)
- **Emergency Contact:** MBFD IT Support
- **Developer Contact:** [Add developer contact info]

---

## 6. Technical Details (For IT)

### VAPID Key Locations

**Environment Variables (.env):**
```
VAPID_PUBLIC_KEY=your_public_key_here
VAPID_PRIVATE_KEY=your_private_key_here
```

**Configuration File:** `config/services.php`
```php
'webpush' => [
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => 'mailto:support@miamibeachfl.gov',
    ],
],
```

### Service Worker Endpoints

**Service Worker File:** `public/sw.js`
**Registration Endpoint:** `/api/push/subscribe`
**Unsubscribe Endpoint:** `/api/push/unsubscribe`

### API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/push/subscribe` | POST | Subscribe to push notifications |
| `/api/push/unsubscribe` | DELETE | Unsubscribe from push notifications |
| `/api/push/send` | POST | Send push notification |
| `/api/push/test` | POST | Send test notification |

### Database Tables

**push_subscriptions:**
```sql
- id (bigint, primary key)
- user_id (bigint, foreign key)
- endpoint (text)
- public_key (text)
- auth_token (text)
- content_encoding (varchar)
- created_at (timestamp)
- updated_at (timestamp)
```

**notifications:**
```sql
- id (bigint, primary key)
- user_id (bigint, foreign key)
- title (varchar)
- body (text)
- data (jsonb)
- read_at (timestamp, nullable)
- created_at (timestamp)
```

**notification_tracking:**
```sql
- id (bigint, primary key)
- notification_id (bigint, foreign key)
- subscription_id (bigint, foreign key)
- status (varchar: sent, delivered, failed)
- error_message (text, nullable)
- delivered_at (timestamp, nullable)
- created_at (timestamp)
```

### Feature Flags

**Location:** `config/features.php`
```php
'push_notifications' => env('PUSH_NOTIFICATIONS_ENABLED', true),
```

**To disable:**
```bash
# In .env file
PUSH_NOTIFICATIONS_ENABLED=false

# Clear cache
php artisan cache:clear
php artisan config:clear
```

### Browser Compatibility

| Browser | Version | Support |
|---------|---------|---------|
| Chrome | 50+ | ✅ Full |
| Edge | 17+ | ✅ Full |
| Firefox | 44+ | ✅ Full |
| Safari (iOS) | 16.4+ | ✅ Full |
| Safari (macOS) | 16.4+ | ✅ Full |

---

## Quick Reference Card

### iOS Setup Checklist
- [ ] Update to iOS 16.4 or later
- [ ] Open Safari and go to support.darleyplex.com
- [ ] Log in with credentials
- [ ] Tap Share → Add to Home Screen
- [ ] Open MBFD Hub from home screen
- [ ] Tap "Enable Notifications"
- [ ] Allow notifications when prompted

### Android Setup Checklist
- [ ] Open Chrome and go to support.darleyplex.com
- [ ] Log in with credentials
- [ ] Tap "Enable Notifications"
- [ ] Allow notifications when prompted

### Desktop Setup Checklist
- [ ] Open Chrome/Edge and go to support.darleyplex.com
- [ ] Log in with credentials
- [ ] Click "Enable Notifications"
- [ ] Allow notifications when prompted

---

## Contact Information

**For Technical Support:**
- **Name:** Miguel Anchia
- **Email:** MiguelAnchia@miamibeachfl.gov
- **Phone:** [Add phone number]

**For Emergency Issues:**
- **MBFD IT Support:** [Add contact info]
- **After Hours:** [Add emergency contact]

---

## Document Information

- **Version:** 1.0
- **Last Updated:** January 27, 2026
- **Maintained By:** MBFD IT Department
- **Next Review Date:** July 27, 2026

---

## Appendix: Frequently Asked Questions

**Q: Do I need to keep the browser open to receive notifications?**
- **Desktop:** Yes, the browser must be running
- **Mobile:** No, notifications will appear even when the app is closed

**Q: Can I receive notifications on multiple devices?**
- Yes, you can enable notifications on as many devices as you want

**Q: Will notifications use my data plan?**
- Yes, notifications use a small amount of data. However, the impact is minimal

**Q: Can I customize which notifications I receive?**
- Currently, all enabled users receive all notifications. Customization may be added in the future

**Q: What if I lose my device?**
- Contact IT support to remove your device from the notification system

**Q: Are notifications secure?**
- Yes, all notifications are encrypted and sent over HTTPS

---

*End of Document*
