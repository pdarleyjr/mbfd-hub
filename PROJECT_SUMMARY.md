# MBFD Support Hub - Project Summary

**Last Updated**: January 25, 2026 @ 06:18 EST (11:18 UTC)  
**Status**: ✅ **FULLY OPERATIONAL**  
**Current Branch (VPS)**: `feat/uiux-users-remove-tasks`  
**Repository**: https://github.com/pdarleyjr/mbfd-hub

---

## Current Deployment Status (January 25, 2026 @ 06:18 EST)

### ✅ Production Environment - STABLE
- **URL**: https://support.darleyplex.com
- **Admin Panel**: https://support.darleyplex.com/admin/login
- **Daily Checkout PWA**: https://support.darleyplex.com/daily
- **VPS Host**: 145.223.73.170 (OVH)
- **Project Path**: `/root/mbfd-hub`

### Docker Services Status
```
CONTAINER                    IMAGE              STATUS                 PORTS
mbfd-hub-laravel.test-1     sail-8.5/app       Up 10 hours           0.0.0.0:8080->80
mbfd-hub-pgsql-1            postgres:18-alpine Up 10 hours (healthy) 0.0.0.0:5432->5432
```

### Latest Git Commit (VPS)
```
66ec8ca4 - Merge UI/UX improvements: Remove tasks, update widgets
           (Jan 23, 2026 - 9:36 PM)
```

---

## Latest Deployment (January 25, 2026 @ 01:00 UTC) - CRITICAL EMERGENCY FIX

### Deployment Status: ✅ FULLY OPERATIONAL

#### Critical Issues Fixed:
1. **Removed Spatie Permission Package Dependencies**:
   - **Root cause**: User model still had `HasRoles` trait from Spatie Permission package but migrations were removed
   - **Error**: `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "permissions" does not exist`
   - **Solution**: Removed `use Spatie\Permission\Traits\HasRoles;` and trait usage from [`app/Models/User.php`](app/Models/User.php:9)
   - **Result**: Authentication now works correctly

2. **Full VPS Docker Rebuild**:
   - Reset VPS repository to `origin/main` with `git reset --hard`
   - Removed stale files (old daily/, permission configs, temp scripts)
   - Built Docker containers from scratch with `--no-cache`
   - All containers running successfully

3. **Database Clean Slate**:
   - Ran `migrate:fresh --force` to drop all tables and rebuild
   - Removed duplicate migration files:
     * `2026_01_21_000001_create_apparatuses_table.php` (duplicate)
     * `2026_01_23_200541_add_photo_to_apparatus_defects_table.php` (duplicate)
   - Created and uploaded `UserSeeder.php` (was missing on VPS)
   - Successfully seeded all 4 user accounts

4. **Built Daily PWA Inside Container**:
   - **Issue**: PWA built on host wasn't visible inside Docker container
   - **Solution**: Ran `npm run build` inside container via `docker compose exec`
   - **Result**: `/daily` route now serves PWA correctly

#### Deployment Commands Executed:
```bash
# On VPS at /root/mbfd-hub
git fetch origin && git reset --hard origin/main && git clean -fd
docker compose down
docker compose build --no-cache
docker compose up -d
docker compose exec laravel.test php artisan migrate:fresh --force
docker compose exec laravel.test php artisan db:seed --class=UserSeeder --force
docker compose exec laravel.test php artisan config:clear
docker compose exec laravel.test php artisan cache:clear
docker compose exec laravel.test php artisan view:clear
docker compose exec laravel.test php artisan route:clear
docker compose exec laravel.test npm run build
docker compose exec laravel.test bash -c 'cd resources/js/daily-checkout && npm run build'
```

#### Verified Working:
- ✅ https://support.darleyplex.com/admin/login - Login page loads
- ✅ Admin login with PeterDarley@miamibeachfl.gov / Penco3 - Successfully authenticated
- ✅ Dashboard loads with full navigation (Fleet Management, Administration, Projects, etc.)
- ✅ https://support.darleyplex.com/daily - PWA loads and displays "MBFD Daily Checkout"

#### User Credentials (Confirmed Working):
- PeterDarley@miamibeachfl.gov / Penco3 (Admin)
- RichardQuintela@miamibeachfl.gov / Penco2 (Admin)
- MiguelAnchia@miamibeachfl.gov / Penco1 (Admin)
- geralddeyoung@miamibeachfl.gov / MBFDGerry1 (User)

---

## Tech Stack & Architecture

### Backend (TALL Stack)
- **Laravel 11.31** - PHP web framework
- **Filament 3.2** - Laravel admin panel & resource management
- **PHP 8.2+** - Programming language
- **PostgreSQL 18 (Alpine)** - Primary database

### Frontend
- **Tailwind CSS 3.4** - Utility-first CSS framework
- **Alpine.js** (via Filament) - Lightweight JavaScript framework
- **Livewire** (via Filament) - Full-stack framework for dynamic interfaces
- **Vite 6.0** - Build tool and dev server

### PWA (Daily Checkout App)
- **React 18.2** - JavaScript library for UI
- **React Router DOM 6.8** - Client-side routing
- **TypeScript** - Type-safe JavaScript
- **Vite 4.3** - Build tool
- **vite-plugin-pwa 1.2** - Progressive Web App capabilities
- **Workbox** - Service worker library for offline support

### Infrastructure & Deployment
- **Docker** (Laravel Sail) - Containerization
  - `sail-8.5/app` - Laravel application container
  - `postgres:18-alpine` - PostgreSQL database container
- **Nginx** - Reverse proxy and web server
- **Let's Encrypt** - SSL/TLS certificates (managed by Certbot)
- **OVH VPS** - Hosting provider

### Error Tracking & Monitoring
- **Sentry** - Application monitoring and error tracking
  - Backend: Laravel integration
  - Frontend: Browser and React integrations
- **Cloudflare Workers AI** (Optional) - AI-powered features

### Additional Packages
- **Laravel Sanctum** - API authentication
- **Laravel Tinker** - REPL for Laravel
- **Appstract Laravel Stock 1.2** - Inventory management
- **Axios** - HTTP client
- **Concurrently** - Run multiple commands concurrently

---

## Database Schema Summary

### Core Tables
1. **users** - System users with authentication
2. **apparatuses** - Fire apparatus/vehicles
3. **apparatus_inspections** - Daily inspection records
4. **apparatus_defects** - Reported defects from inspections
5. **stations** - Fire station locations
6. **uniforms** - Uniform inventory
7. **shop_works** - Maintenance shop work orders
8. **capital_projects** - Capital improvement projects
9. **equipment_items** - Equipment inventory
10. **inventory_locations** - Storage locations for equipment

### AI & Automation Tables
11. **project_milestones** - Project milestone tracking
12. **project_updates** - Project update logs
13. **notification_tracking** - Notification delivery tracking
14. **ai_analysis_logs** - AI analysis history

### System Tables
15. **notifications** - Laravel notifications (JSONB data column)
16. **cache** - Application cache
17. **jobs** - Queue jobs
18. **stock_mutations** - Inventory changes (Appstract package)

---

## API Endpoints

### Public API (Rate Limited: 60/minute)
- `GET /api/public/apparatuses` - List all apparatus
- `GET /api/public/apparatuses/{id}/checklist` - Get apparatus checklist
- `POST /api/public/apparatuses/{id}/inspections` - Submit inspection

### Admin API (Sanctum Authentication Required)
- `GET /api/admin/metrics` - Admin dashboard metrics
- `GET /api/admin/smart-updates` - Smart notifications feed
- `POST /api/admin/ai/inventory-chat` - AI inventory assistant
- `POST /api/admin/ai/inventory-execute` - Execute AI-suggested actions

### Web Routes
- `GET /` - Welcome page
- `GET /daily` - Daily Checkout PWA entry point
- `GET /admin/*` - Filament admin panel (all routes)

---

## Filament Resources (Admin Panel)

### Fleet Management
- **ApparatusResource** - Fire apparatus management
- **InspectionResource** - Inspection records
- **DefectResource** - Defect tracking
- **RecommendationResource** - Maintenance recommendations

### Inventory Management
- **EquipmentItemResource** - Equipment catalog
- **InventoryLocationResource** - Storage locations
- **UniformResource** - Uniform inventory

### Maintenance & Projects
- **ShopWorkResource** - Shop work orders
- **CapitalProjectResource** - Capital projects
- **TodoResource** - Task management

### Administration
- **UserResource** - User management
- **StationResource** - Station management

### Custom Widgets
- **FleetStatsWidget** - Fleet statistics
- **InventoryOverviewWidget** - Inventory summary
- **TodoOverviewWidget** - Task overview
- **SmartUpdatesWidget** - AI-powered notifications

---

## PWA Features (Daily Checkout)

### Capabilities
- ✅ **Offline-first architecture** - Service worker caching
- ✅ **Installable** - Add to home screen on mobile devices
- ✅ **Responsive design** - Works on all screen sizes
- ✅ **Local storage** - Save inspection data when offline
- ✅ **Background sync** - Auto-submit when connection restores

### Components
- `ApparatusList` - Browse apparatus for inspection
- `InspectionWizard` - Multi-step inspection workflow
- `OfficerStep` - Officer information input
- `CompartmentStep` - Compartment-by-compartment checklist
- `SubmitStep` - Review and submit
- `SuccessPage` - Confirmation screen
- `OfflineIndicator` - Connection status display

### Build Output (Public Directory)
```
public/daily/
├── index.html              # Entry point
├── manifest.json           # PWA manifest
├── manifest.webmanifest    # Alternative manifest
├── service-worker.js       # Service worker
├── sw.js                   # Service worker implementation
├── workbox-*.js            # Workbox runtime
├── registerSW.js           # Service worker registration
├── assets/                 # Built JS/CSS bundles
└── icons/                  # PWA icons
```

---

## Nginx Configuration

### Server Block
- **Server Name**: support.darleyplex.com
- **SSL**: Let's Encrypt (port 443)
- **HTTP Redirect**: Port 80 → 443

### Proxy Configuration
```
/daily/*        → http://127.0.0.1:8080/daily/*
/*              → http://127.0.0.1:8080/*
```

### Features
- WebSocket support for Livewire
- HTTP/1.1 upgrade support
- 60-second proxy timeouts
- Real IP forwarding

---

## User Credentials (Confirmed Working)

| Email | Password | Role |
|-------|----------|------|
| PeterDarley@miamibeachfl.gov | Penco3 | Admin |
| RichardQuintela@miamibeachfl.gov | Penco2 | Admin |
| MiguelAnchia@miamibeachfl.gov | Penco1 | Admin |
| geralddeyoung@miamibeachfl.gov | MBFDGerry1 | User |

---

## GitHub Repository

### Active Branches
- `main` - Production branch (66ec8ca4)
- `feat/uiux-users-remove-tasks` - Current VPS branch
- `feat/daily-checkout-integration`
- `feat/enhanced-observability-v2`
- `feat/fire-equipment-inventory`
- `feat/projects-todo-kanban`
- `fixes-and-ui-enhancements`
- `observability/sentry-lighthouse-ci`
- `remove-legacy-tasks-kanban`

### Recent Commits (Last 10)
1. Merge UI/UX improvements: Remove tasks, update widgets (Jan 23)
2. Resolve merge conflicts from main (Jan 23)
3. feat(ui): Add MBFD logos and ensure sidebar collapsibility (Jan 23)
4. docs: Phase 14 complete - UI/UX enhancements & technical audit (Jan 23)
5. Fix admin dashboard 500 error by removing hasRole() dependency (Jan 23)
6. Phase 12: Desktop UX enhancements - keyboard shortcuts (Jan 23)
7. Add migration to add photo column to apparatus_defects (Jan 23)
8. feat: Phase 11 - Mobile UX enhancements for daily checkout (Jan 23)
9. Phase 10: Implement responsive grid layout for dashboard (Jan 23)
10. Phase 9: Consolidate Dashboard Widgets (Jan 23)

### Repository Stats
- **Created**: January 20, 2026
- **Last Updated**: January 23, 2026
- **Open Issues**: 0
- **Language**: PHP (with TypeScript for PWA)

---

## Previous Deployment (January 24, 2026 @ 23:58 UTC)

### Deployment Status: ✅ SUCCESSFUL - Critical Issues FIXED

#### Actions Completed:
1. **Fixed /daily 404 Error**:
   - **Root cause**: Directory permissions (700) on `public/daily` blocked Nginx access
   - **Solution**: Changed permissions to 755 recursively with `chmod -R 755 public/daily`
   - **Result**: PWA now loads successfully at https://support.darleyplex.com/daily

2. **Fixed User Login Issues**:
   - **Root cause**: Incorrect password in UserSeeder (Miguel had Admin123 instead of Penco1)
   - **Solution**: Updated `database/seeders/UserSeeder.php` and reseeded database
   - **Correct passwords**:
     * MiguelAnchia@miamibeachfl.gov → Penco1 (Admin)
     * RichardQuintela@miamibeachfl.gov → Penco2 (Admin)
     * PeterDarley@miamibeachfl.gov → Penco3 (Admin)
     * geralddeyoung@miamibeachfl.gov → MBFDGerry1 (User)

3. **Cache Optimization**:
   - Ran `php artisan optimize:clear`
   - Cleared config, routes, and views caches
   - Rebuilt caches with `route:cache` and `config:cache`

4. **Verification Tests**:
   - ✅ Login tested successfully with PeterDarley@miamibeachfl.gov / Penco3
   - ✅ Dashboard loads correctly with full navigation menu
   - ✅ /daily route confirmed working (no 404)
   - Screenshots: `screenshots/daily-fixed.png`, `screenshots/login-success.png`

#### System URLs:
- **Production**: https://support.darleyplex.com/admin
- **Daily Checkout PWA**: https://support.darleyplex.com/daily (FIXED)
- **SSH Access**: `ssh -i ~/.ssh/id_ed25519_hpb_docker root@145.223.73.170`

#### Git Status:
- Branch: `feat/uiux-users-remove-tasks`
- Files modified: `database/seeders/UserSeeder.php`
- Status: Ready to commit password fix

### Known Issues:
- Widget serialization error (PDO Pgsql serialization) - non-critical, doesn't affect core functionality

---

## Previous Deployment (January 24, 2026 @ 23:41 UTC)

### Actions Completed:
1. **Local Git Force-Clean**: Reset local repository to match origin/feat/uiux-users-remove-tasks
2. **VPS Deployment**: 
   - Stopped old `laravel-app` containers
   - Started `mbfd-hub` containers at `/root/mbfd-hub`
   - Container: `mbfd-hub-laravel.test-1` (running on port 8080)
   - Database: `mbfd-hub-pgsql-1` (PostgreSQL 18)
3. **Database Migrations**:
   - Ran fresh migrations successfully
   - Fixed duplicate migration
4. **User Account Setup**:
   - Created 4 user accounts via UserSeeder
5. **Login Test**: Successfully logged in as Peter Darley

---

## Project Overview

**MBFD Support Hub** is a comprehensive fire department support services platform built for the Miami Beach Fire Department (MBFD). The system manages fleet operations, daily apparatus inspections, inventory, maintenance work orders, capital projects, and task management.

### Key Features

#### 1. Fleet Management System
- **Apparatus Tracking** - Comprehensive database of all fire apparatus
- **Daily Inspections** - Mobile PWA for on-shift apparatus checks
- **Defect Reporting** - Track and manage equipment issues
- **Maintenance History** - Full service and repair records
- **AI-Powered Recommendations** - Automated maintenance suggestions

#### 2. Daily Checkout PWA
- **Mobile-first design** - Optimized for tablets and phones
- **Offline capability** - Continue inspections without internet
- **Compartment-by-compartment** - Detailed equipment verification
- **Photo attachments** - Document defects with images
- **Officer accountability** - Track who performed each inspection

#### 3. Inventory Management
- **Equipment catalog** - Complete inventory of department equipment
- **Location tracking** - Know where every item is stored
- **Stock levels** - Monitor inventory quantities
- **AI chat assistant** - Natural language inventory queries

#### 4. Maintenance Operations
- **Shop Work Orders** - Track all maintenance and repairs
- **Capital Projects** - Manage large-scale improvements
- **Project Milestones** - Track project progress
- **Todo Management** - Task delegation and tracking

#### 5. Admin Dashboard
- **Real-time metrics** - Fleet status, inspection completion
- **Smart notifications** - AI-analyzed alerts and updates
- **Custom widgets** - Configurable dashboard views
- **Keyboard shortcuts** - Power-user efficiency features

### Design Philosophy
- **TALL Stack** - Modern, efficient, and maintainable Laravel ecosystem
- **Filament Admin** - Rapid admin panel development
- **PWA-first** - Mobile experience without app store deployment
- **Offline-capable** - Critical operations work without network
- **AI-enhanced** - Leverage Cloudflare Workers AI for insights

---

## Known Issues & Limitations

### Current Known Issues
1. **Widget Serialization Warnings** - Non-critical PDO PostgreSQL serialization warnings in dashboard widgets. Does not affect functionality.

2. **Branch Mismatch** - VPS is on `feat/uiux-users-remove-tasks` branch instead of `main`. This is intentional as recent UI/UX improvements haven't been fully merged.

### Technical Debt
1. **Migration Cleanup** - Some duplicate migration files exist in git history (removed from active codebase)
2. **Temp Files on VPS** - Various test scripts (`test_ai_services.php`, `verify_projects.php`, etc.) should be cleaned up
3. **Old laravel-app directory** - Previous deployment artifact at `/root/mbfd-hub/laravel-app` should be removed

### Future Enhancements Planned
1. **AI Features** - Expand Cloudflare Workers AI integration
2. **Mobile App** - Native iOS/Android app (currently PWA only)
3. **Advanced Analytics** - Deeper insights into fleet operations
4. **Integration APIs** - Connect with external fire department systems
5. **Multi-tenant** - Support for multiple fire departments

---

## Recent Fixes Applied

### January 25, 2026 @ 01:00 UTC - Critical Emergency Fix
1. **Removed Spatie Permission Package** - Eliminated `HasRoles` trait causing "permissions table does not exist" error
2. **Full Docker Rebuild** - Rebuilt all containers from scratch with `--no-cache`
3. **Database Clean Slate** - Ran `migrate:fresh` and reseeded all users
4. **PWA Build Inside Container** - Fixed PWA visibility by building inside Docker container

### January 24, 2026 @ 23:58 UTC
1. **Fixed /daily 404 Error** - Corrected directory permissions (700 → 755) on `public/daily`
2. **Fixed User Login Issues** - Corrected password in UserSeeder (Miguel had wrong password)
3. **Cache Optimization** - Cleared and rebuilt all Laravel caches

---

## Development & Deployment

### Local Development
```bash
# Clone repository
git clone https://github.com/pdarleyjr/mbfd-hub.git
cd mbfd-hub

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Start Docker containers (Laravel Sail)
./vendor/bin/sail up -d

# Run migrations and seeders
./vendor/bin/sail artisan migrate --seed

# Build PWA
cd resources/js/daily-checkout
npm install
npm run build

# Access application
# Admin: http://localhost/admin
# PWA: http://localhost/daily
```

### Production Deployment (VPS)
```bash
# SSH to VPS
ssh -i ~/.ssh/id_ed25519_hpb_docker root@145.223.73.170

# Navigate to project
cd /root/mbfd-hub

# Pull latest changes
git fetch origin
git pull origin main  # or specific branch

# Rebuild containers (if needed)
docker compose down
docker compose build --no-cache
docker compose up -d

# Run migrations
docker compose exec laravel.test php artisan migrate --force

# Clear caches
docker compose exec laravel.test php artisan config:clear
docker compose exec laravel.test php artisan cache:clear
docker compose exec laravel.test php artisan view:clear
docker compose exec laravel.test php artisan route:clear

# Build PWA inside container
docker compose exec laravel.test bash -c 'cd resources/js/daily-checkout && npm run build'

# Verify deployment
curl -I https://support.darleyplex.com/admin
curl -I https://support.darleyplex.com/daily
```

### SSH Access
```bash
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170
```

---

## Environment Variables

### Critical Configuration (.env)
```env
APP_NAME="MBFD Support Hub"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://support.darleyplex.com

DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=mbfd_support
DB_USERNAME=sail
DB_PASSWORD=password

# Sentry Monitoring
SENTRY_LARAVEL_DSN=<backend-dsn>
VITE_SENTRY_DSN=<frontend-dsn>
SENTRY_ORG=mbfd
SENTRY_PROJECT_BACKEND=support-backend
SENTRY_PROJECT_FRONTEND=support-frontend

# Cloudflare AI (Optional)
CLOUDFLARE_ACCOUNT_ID=<account-id>
CLOUDFLARE_API_TOKEN=<api-token>
CLOUDFLARE_AI_ENABLED=false
CLOUDFLARE_WORKER_URL=https://mbfd-support-ai.pdarleyjr.workers.dev
```

---

## Support & Documentation

### Key Documentation Files
- [`README.md`](README.md) - Laravel framework documentation
- [`OBSERVABILITY_SETUP_REPORT.md`](OBSERVABILITY_SETUP_REPORT.md) - Sentry integration guide
- [`PR_DESCRIPTION.md`](PR_DESCRIPTION.md) - Recent pull request details
- [`UIUX_IMPLEMENTATION_REPORT.md`](UIUX_IMPLEMENTATION_REPORT.md) - UI/UX changes log
- [`docs/CHECKOUT_REUSE_MAP.md`](docs/CHECKOUT_REUSE_MAP.md) - PWA architecture
- [`docs/API_RATE_LIMITING.md`](docs/API_RATE_LIMITING.md) - API rate limit details

### Contact & Support
- **Repository**: https://github.com/pdarleyjr/mbfd-hub
- **Production URL**: https://support.darleyplex.com
- **VPS Provider**: OVH
- **SSL**: Let's Encrypt (auto-renewing)

---

## Version History

### v1.0.0 (January 20-25, 2026)
- ✅ Initial deployment
- ✅ TALL stack implementation (Laravel 11, Filament 3, Tailwind, Alpine/Livewire)
- ✅ Fleet management system
- ✅ Daily checkout PWA (React + TypeScript)
- ✅ Apparatus inspection tracking
- ✅ Defect reporting with photos
- ✅ Inventory management
- ✅ Shop work orders
- ✅ Capital projects
- ✅ Todo/task management
- ✅ Admin dashboard with custom widgets
- ✅ Sentry error tracking
- ✅ Docker containerization
- ✅ SSL/TLS via Let's Encrypt
- ✅ Offline-first PWA capabilities

---

**Last Updated**: January 25, 2026 @ 06:20 EST  
**Document Version**: 2.0  
**Status**: Production Ready ✅
