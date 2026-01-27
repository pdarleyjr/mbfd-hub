# Settings Page Migration - Technical Assessment & Implementation Plan

## Executive Summary

This document details the migration of notifications action card and users link from dashboard/sidebar to a dedicated Settings page in the MBFD Support Hub Filament application.

## Current Architecture Analysis

### 1. Notifications System Components

#### Database Layer
- **Table**: `notifications` (Laravel's standard notifications table)
  - UUID primary key
  - Polymorphic `notifiable` relationship
  - JSON `data` column (altered to JSONB)
  - `read_at` timestamp for read/unread state

- **Table**: `push_subscriptions` (WebPush subscriptions)
  - Stores VAPID-based push subscription endpoints per user

#### Models & Services
| File | Purpose |
|------|---------|
| `app/Models/NotificationTracking.php` | Tracks notification delivery status |
| `app/Services/NotificationService.php` | Handles notification dispatch logic |
| `app/Notifications/CriticalAlertNotification.php` | Critical alert notification class |
| `app/Notifications/TestPushNotification.php` | Test push notification class |

#### Widgets
| File | Purpose |
|------|---------|
| `app/Filament/Widgets/PushNotificationWidget.php` | Dashboard widget for push notification subscription |
| `app/Filament/Widgets/PriorityNotificationsWidget.php` | Priority notifications display |

#### Views
- `resources/views/filament/widgets/push-notification-widget.blade.php` - Push subscription UI

#### API Controllers
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/PushSubscriptionController.php` | Push subscription CRUD |
| `app/Http/Controllers/Api/TestNotificationController.php` | Test notification endpoint |

#### Configuration
- `config/webpush.php` - VAPID keys and WebPush settings

### 2. Users Management Components

#### Resource Files
| File | Purpose |
|------|---------|
| `app/Filament/Resources/UserResource.php` | Main user resource definition |
| `app/Filament/Resources/UserResource/Pages/ListUsers.php` | User listing page |
| `app/Filament/Resources/UserResource/Pages/CreateUser.php` | User creation page |
| `app/Filament/Resources/UserResource/Pages/EditUser.php` | User editing page |

#### Current Navigation
- Navigation Group: "Administration"
- Navigation Icon: `heroicon-o-users`
- Sidebar placement: Auto-discovered via `discoverResources()`

### 3. AdminPanelProvider Configuration
```php
// Current relevant configuration
->databaseNotifications()
->databaseNotificationsPolling('30s')
->widgets([
    PushNotificationWidget::class,  // First widget on dashboard
    FleetStatsWidget::class,
    InventoryOverviewWidget::class,
    TodoOverviewWidget::class,
    SmartUpdatesWidget::class,
])
```

## Migration Strategy

### Phase 1: Create Settings Page Infrastructure

**Goal**: Create a new Settings page that consolidates notification preferences and user management links.

**Files to Create**:
1. `app/Filament/Pages/Settings.php` - Main Settings page
2. `resources/views/filament/pages/settings.blade.php` - Settings page view

### Phase 2: Migrate Notifications Widget

**Goal**: Move PushNotificationWidget from dashboard to Settings page.

**Changes**:
1. Remove `PushNotificationWidget::class` from AdminPanelProvider widgets array
2. Embed notification controls directly in Settings page
3. Keep database notifications bell icon in top bar (Filament default)

### Phase 3: Relocate Users Link

**Goal**: Move Users resource from sidebar to Settings page navigation.

**Changes**:
1. Add `shouldRegisterNavigation(): false` to UserResource
2. Add Users management card/link on Settings page
3. Maintain all existing UserResource functionality

### Phase 4: Update Navigation

**Goal**: Add Settings link to user menu dropdown.

**Changes**:
1. Add `userMenuItems()` to AdminPanelProvider
2. Include Settings page link with gear icon

## Rollback Procedures

### Rollback Phase 1
```bash
# Remove settings page files
rm app/Filament/Pages/Settings.php
rm resources/views/filament/pages/settings.blade.php
```

### Rollback Phase 2
```bash
# Restore PushNotificationWidget to AdminPanelProvider widgets array
# Revert AdminPanelProvider.php to previous commit
git checkout HEAD~1 -- app/Providers/Filament/AdminPanelProvider.php
```

### Rollback Phase 3
```bash
# Remove shouldRegisterNavigation from UserResource
git checkout HEAD~1 -- app/Filament/Resources/UserResource.php
```

### Full Rollback
```bash
git revert <commit-hash>
# Or restore from backup
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan view:clear
```

## Success Criteria

| Phase | Criteria |
|-------|----------|
| 1 | Settings page accessible at `/admin/settings` |
| 2 | Push notification controls functional on Settings page |
| 2 | Dashboard no longer shows PushNotificationWidget |
| 2 | Database notifications bell icon still works in top bar |
| 3 | Users no longer appears in sidebar |
| 3 | Users management accessible from Settings page |
| 3 | All user CRUD operations still functional |
| 4 | Settings link visible in user dropdown menu |

## Test Plan

### Unit Tests
- Settings page renders correctly
- Push subscription endpoints still functional
- User resource CRUD operations work

### Integration Tests
- Full notification subscription flow
- User creation/edit/delete flow from Settings page
- Navigation flow to Settings and Users

### Manual Verification
1. Login as admin user
2. Verify Settings accessible from user menu
3. Test push notification subscription toggle
4. Navigate to Users from Settings page
5. Create/edit/delete user
6. Verify notifications bell icon works
7. Check sidebar no longer shows Users link

## Deployment Steps

1. Create feature branch
2. Implement changes
3. Push to GitHub
4. Deploy to VPS via SSH
5. Clear all caches
6. Verify functionality

## Deployment Evidence

**Commit:** `d818c15a` - "feat: migrate notifications and users to Settings page"  
**Deployed:** 2026-01-27  
**Live URL:** https://support.darleyplex.com/admin/settings

### Verification Completed
- [x] Settings page accessible at `/admin/settings`
- [x] Push notification subscription button functional
- [x] Manage Users link navigates to UserResource
- [x] User profile section displays current user
- [x] Users link removed from sidebar
- [x] Settings link added to user dropdown menu
- [x] Database notifications bell icon still operational
- [x] Zero-downtime deployment achieved
- [x] All caches cleared (config, view, cache, route)
- [x] Vite assets compiled successfully

---

**Created**: 2026-01-27
**Author**: Kilo Code
**Status**: ✅ DEPLOYED & VERIFIED
