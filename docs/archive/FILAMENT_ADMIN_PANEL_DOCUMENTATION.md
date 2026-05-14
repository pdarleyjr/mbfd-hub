# MBFD Hub Filament Admin Panel Configuration & User Management

## Overview

MBFD Hub uses a **multi-panel Filament architecture** with four distinct panels, each serving different user types with separate authentication guards and access controls.

---

## Panel Architecture

### 1. Admin Panel (`/admin`)
**Provider:** `App\Providers\Filament\AdminPanelProvider`

| Aspect | Configuration |
|--------|---------------|
| **Path** | `/admin` |
| **Guard** | `web` (default) |
| **Model** | `App\Models\User` |
| **Login Class** | `App\Filament\Pages\Auth\Login` |
| **Brand Name** | MBFD Support Hub |
| **Default Panel** | Yes (`->default()`) |

**Access Control:**
- Middleware: `RedirectTrainingUsers` - redirects training-only users to `/training`
- Users must have one of these roles: `super_admin`, `admin`, `logistics_admin`, `training_admin`, `training_viewer`

**Navigation Groups:**
- Dashboard
- Active Operations
- Fleet Management
- Inventory & Logistics
- Workgroup Management
- Administration
- Communication / AI
- Monitoring
- External Tools

---

### 2. Employee Portal (`/employee`)
**Provider:** `App\Providers\Filament\EmployeePanelProvider`

| Aspect | Configuration |
|--------|---------------|
| **Path** | `/employee` |
| **Guard** | `employee` (custom) |
| **Model** | `App\Models\Employee` |
| **Login Class** | `App\Filament\Employee\Pages\Auth\EmployeeLogin` |
| **Brand Name** | MBFD Employee Portal |
| **Auth Method** | Employee ID + Password |

**Access Control:**
- Uses completely separate `employees` table
- Custom `employee` guard with `employees` provider
- Middleware: `ForcePasswordChangeMiddleware` - forces password change if `must_change_password` is true
- Any authenticated employee can access

**Pages:**
- `EmployeeDashboard` - Main dashboard
- `MyEquipmentPage` - View assigned equipment
- `RequestEquipmentPage` - Request new equipment
- `ChangePasswordPage` - Password change form

---

### 3. Workgroup Panel (`/workgroups`)
**Provider:** `App\Providers\Filament\WorkgroupPanelProvider`

| Aspect | Configuration |
|--------|---------------|
| **Path** | `/workgroups` |
| **Guard** | `web` |
| **Model** | `App\Models\User` |
| **Login Class** | `App\Filament\Pages\Auth\Login` |
| **Brand Name** | Eval Feedback Hub |

**Access Control:**
- Middleware: `EnsureWorkgroupPanelAccess`
- Required roles/permissions:
  - `super_admin`
  - `admin`
  - `logistics_admin`
  - `workgroup_admin`
  - `workgroup_facilitator`
  - `workgroup_member`
  - OR `workgroup.access` permission

**Resources:**
- `EvaluationCategoryResource`
- `CandidateProductResource`

**Pages:**
- Dashboard, Files, Notes, Evaluations, SharedUploads, Profile, etc.

---

### 4. Training Panel (`/training`)
**Provider:** `App\Providers\Filament\TrainingPanelProvider`

| Aspect | Configuration |
|--------|---------------|
| **Path** | `/training` |
| **Guard** | `web` |
| **Model** | `App\Models\User` |
| **Login Class** | `App\Filament\Pages\Auth\Login` |
| **Brand Name** | MBFD Training Division |

**Access Control:**
- Middleware: `EnsureTrainingPanelAccess`
- Required roles/permissions:
  - `super_admin`
  - `training_admin`
  - `training_viewer`
  - OR `training.access` permission

**Resources:**
- `TrainingTodoResource`
- `ExternalSourceResource`
- `ExternalNavItemResource`

---

## User Roles Defined

### Role Hierarchy

| Role | Guard | Description | Panel Access |
|------|-------|-------------|--------------|
| `super_admin` | web | Full system access, all permissions | Admin, Training, Workgroup |
| `admin` | web | Administrative access | Admin, Workgroup |
| `logistics_admin` | web | Logistics/fleet management | Admin, Workgroup |
| `training_admin` | web | Full training panel access | Training (redirected from Admin) |
| `training_viewer` | web | Read-only training panel access | Training (redirected from Admin) |
| `workgroup_admin` | web | Workgroup administration | Workgroup |
| `workgroup_facilitator` | web | Workgroup facilitation | Workgroup |
| `workgroup_member` | web | Basic workgroup participation | Workgroup |
| `staff` | web | Basic staff role | Admin (limited) |

### Role Permissions (from RolesAndPermissionsSeeder)

```php
// Permissions created:
'training.access'           // Access training panel
'training.manage_external_links'  // Manage external navigation items

// Role assignments:
super_admin → All permissions
training_admin → training.access, training.manage_external_links
training_viewer → training.access
```

---

## Authentication Guards & Providers

### Configuration (`config/auth.php`)

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'users',
    ],
    'employee' => [
        'driver'   => 'session',
        'provider' => 'employees',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
    'employees' => [
        'driver' => 'eloquent',
        'model'  => App\Models\Employee::class,
    ],
],
```

---

## User Models

### User Model (`App\Models\User`)

**Table:** `users`

**Implements:** `FilamentUser` interface

**Key Traits:**
- `HasRoles` (Spatie Permission)
- `HasApiTokens` (Laravel Sanctum)
- `HasPushSubscriptions` (WebPush)

**Key Fields:**
- `name`, `email`, `password`
- `display_name`, `rank`, `station`, `phone`
- `must_change_password` (boolean)
- `notification_preferences` (JSON)
- `employee_id` (nullable)

**Panel Access Logic (`canAccessPanel`):**

```php
// Training panel
if ($panel->getId() === 'training') {
    return $this->hasRole('super_admin')
        || $this->hasRole('training_admin')
        || $this->hasRole('training_viewer')
        || $this->can('training.access');
}

// Workgroup panel
if ($panel->getId() === 'workgroups') {
    return $this->hasRole('super_admin')
        || $this->hasRole('admin')
        || $this->hasRole('logistics_admin')
        || $this->hasRole('workgroup_admin')
        || $this->hasRole('workgroup_facilitator')
        || $this->hasRole('workgroup_member')
        || $this->can('workgroup.access');
}

// Admin panel (training users redirected by middleware)
if ($panel->getId() === 'admin') {
    return $this->hasRole('super_admin')
        || $this->hasRole('admin')
        || $this->hasRole('logistics_admin')
        || $this->hasRole('training_admin')
        || $this->hasRole('training_viewer');
}
```

---

### Employee Model (`App\Models\Employee`)

**Table:** `employees` (completely separate from `users`)

**Implements:** `FilamentUser` interface

**Key Fields:**
- `employee_id` (unique, used for login)
- `name`, `rank`
- `password` (hashed)
- `must_change_password` (boolean)

**Panel Access:**
```php
public function canAccessPanel(Panel $panel): bool
{
    return $panel->getId() === 'employee';
}
```

---

## Middleware Access Control

### RedirectTrainingUsers
**Purpose:** Redirects training-only users away from admin panel

```php
// Allows: super_admin, admin
// Redirects: training_admin, training_viewer → /training
// Allows: All other users
```

### EnsureWorkgroupPanelAccess
**Purpose:** Restricts workgroup panel to authorized roles

```php
// Allows: super_admin, admin, logistics_admin, workgroup_admin, 
//         workgroup_facilitator, workgroup_member, or 'workgroup.access' permission
// Denies: 404 for all others
```

### EnsureTrainingPanelAccess
**Purpose:** Restricts training panel to authorized roles

```php
// Allows: super_admin, training_admin, training_viewer, or 'training.access' permission
// Denies: 404 for all others
```

### ForcePasswordChangeMiddleware
**Purpose:** Forces password change for employee portal

```php
// Checks: employee->must_change_password
// Redirects: to change-password page if true
// Skips: change-password page and logout routes
```

---

## Login Redirection (`App\Http\Responses\LoginResponse`)

```php
// Priority order:
1. super_admin, admin → /admin
2. training_admin, training_viewer → /training
3. Default → filament()->getUrl()
```

---

## Filament Resources by Panel

### Admin Panel Resources
Located in: `app/Filament/Resources/`

| Resource | Description |
|----------|-------------|
| `UserResource` | User management (admin/admin roles only) |
| `ApparatusResource` | Fleet/Apparatus management |
| `StationResource` | Fire station management |
| `CapitalProjectResource` | Capital projects |
| `Under25kProjectResource` | Small projects |
| `ShopWorkResource` | Shop work orders |
| `InventoryItemResource` | Inventory management |
| `InventoryLocationResource` | Inventory locations |
| `EquipmentItemResource` | Equipment items |
| `UniformResource` | Uniform management |
| `DefectResource` | Defect tracking |
| `InspectionResource` | Inspections |
| `StationInspectionResource` | Station inspections |
| `SingleGasMeterResource` | Gas meter tracking |
| `FireEquipmentRequestResource` | Equipment requests |
| `EmployeeEquipmentRequestResource` | Employee equipment requests |
| `TodoResource` | Todo management |
| `RecommendationResource` | Recommendations |

### Workgroup Resources
Located in: `app/Filament/Resources/Workgroup/`

| Resource | Description |
|----------|-------------|
| `WorkgroupResource` | Workgroup management |
| `WorkgroupMemberResource` | Member management |
| `WorkgroupSessionResource` | Session management |
| `EvaluationCategoryResource` | Evaluation categories |
| `EvaluationCriterionResource` | Evaluation criteria |
| `EvaluationTemplateResource` | Evaluation templates |
| `EvaluationSubmissionResource` | Evaluation submissions |
| `CandidateProductResource` | Candidate products |
| `WorkgroupFileResource` | File management |

### Training Resources
Located in: `app/Filament/Training/Resources/`

| Resource | Description |
|----------|-------------|
| `TrainingTodoResource` | Training tasks |
| `ExternalSourceResource` | External content sources |
| `ExternalNavItemResource` | External navigation items |

---

## User Management Commands

### Provision Users
```bash
php artisan mbfd:provision-users
```
Creates admin and staff users with default passwords.

### Provision Workgroup Members
```bash
php artisan mbfd:provision-workgroup-members
```
Creates workgroup member accounts and assigns `workgroup_member` role.

### Sync Snipe-IT Users
```bash
php artisan snipeit:sync-users
```
Syncs users from Snipe-IT inventory system.

---

## Notification Preferences

Users can manage notification preferences for:
- `vehicle_inspections` - Vehicle inspection submissions
- `station_inspections` - Station inspection submissions
- `fire_equipment_requests` - Fire equipment requests
- `workgroup_evaluations` - Workgroup evaluation submissions
- `station_inventory_alerts` - Station inventory alerts

**Eligible Roles for Notification Settings:**
- `super_admin`
- `admin`
- `logistics_admin`
- `workgroup_admin`
- `workgroup_facilitator`

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `config/auth.php` | Authentication guards and providers |
| `config/filament-shield.php` | Role/permission configuration |
| `app/Models/User.php` | User model with FilamentUser interface |
| `app/Models/Employee.php` | Employee model for employee portal |
| `app/Providers/Filament/AdminPanelProvider.php` | Admin panel configuration |
| `app/Providers/Filament/EmployeePanelProvider.php` | Employee portal configuration |
| `app/Providers/Filament/WorkgroupPanelProvider.php` | Workgroup panel configuration |
| `app/Providers/Filament/TrainingPanelProvider.php` | Training panel configuration |
| `app/Http/Middleware/RedirectTrainingUsers.php` | Admin panel access middleware |
| `app/Http/Middleware/EnsureWorkgroupPanelAccess.php` | Workgroup access middleware |
| `app/Http/Middleware/EnsureTrainingPanelAccess.php` | Training access middleware |
| `app/Http/Middleware/ForcePasswordChangeMiddleware.php` | Employee password change |
| `database/seeders/RolesAndPermissionsSeeder.php` | Role/permission definitions |
| `database/migrations/2026_03_16_150000_create_employees_table.php` | Employee table migration |

---

## Summary: Who Has Access to What?

### Admin Dashboard (`/admin`)
| Role | Access |
|------|--------|
| `super_admin` | ✅ Full access |
| `admin` | ✅ Full access |
| `logistics_admin` | ✅ Full access |
| `training_admin` | ⚠️ Redirected to `/training` |
| `training_viewer` | ⚠️ Redirected to `/training` |
| `workgroup_admin` | ❌ No access |
| `workgroup_facilitator` | ❌ No access |
| `workgroup_member` | ❌ No access |
| `staff` | ✅ Limited access |

### Employee Portal (`/employee`)
| User Type | Access |
|-----------|--------|
| Employee (from `employees` table) | ✅ Full access |
| User (from `users` table) | ❌ No access |

### Workgroup Dashboard (`/workgroups`)
| Role | Access |
|------|--------|
| `super_admin` | ✅ Full access |
| `admin` | ✅ Full access |
| `logistics_admin` | ✅ Full access |
| `workgroup_admin` | ✅ Full access |
| `workgroup_facilitator` | ✅ Full access |
| `workgroup_member` | ✅ Basic access |
| `training_admin` | ❌ No access |
| `training_viewer` | ❌ No access |

### Training Dashboard (`/training`)
| Role | Access |
|------|--------|
| `super_admin` | ✅ Full access |
| `training_admin` | ✅ Full access + manage external links |
| `training_viewer` | ✅ Read-only access |
| `admin` | ❌ No access |
| `logistics_admin` | ❌ No access |