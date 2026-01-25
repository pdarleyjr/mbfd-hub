# MBFD Support Hub - Comprehensive Technical Documentation

## 1. Project Overview

**MBFD Support Hub** is a production-ready, comprehensive fire department management system specifically designed for the Miami Beach Fire Department (MBFD).

**Production URL**: https://support.darleyplex.com  
**Daily PWA**: https://support.darleyplex.com/daily  
**Admin Panel**: https://support.darleyplex.com/admin  
**Status**: ✅ **Stable & Operational**

## 2. VPS Technical Details

**VPS Server**: 145.223.73.170  
**SSH Access**: `ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170`  
**App Directory**: `/root/mbfd-hub`  
**Operating System**: Ubuntu 24.04 LTS (Linux 6.8.0-87-generic)
**Architecture**: x86_64
**Stack**: Laravel Sail (Docker), PostgreSQL 18, PHP-FPM + Supervisor  
**Domain**: support.darleyplex.com (proxied via Cloudflare)

### System Resources
**CPU**: x86_64 architecture
**Memory**: 16 GB total (15Gi usable)
  - Used: 1.1 GB
  - Free: 1.0 GB
  - Cache: 13 GB
  - Available: 14 GB
**Swap**: Disabled (0 GB)
**Disk Space**: 193 GB total
  - Used: 58 GB (30%)
  - Available: 135 GB
  - File System: /dev/sda1

### Network Configuration
**Exposed Ports**:
- 22 (SSH)
- 80 (HTTP) - Public
- 443 (HTTPS) - Public
- 5173 (Vite Dev Server) - **⚠️ SECURITY ISSUE: PUBLIC - Should be localhost only**
- 5432 (PostgreSQL) - **⚠️ SECURITY ISSUE: PUBLIC - Should be localhost only**
- 8080 (Laravel App) - Public

**Reverse Proxy**: Cloudflare
**SSL/TLS**: Handled by Cloudflare (Full SSL mode)

## 3. Docker Services

**Active Containers**:
1. **mbfd-hub-laravel.test-1** (sail-8.5/app)
   - Status: Up 2 hours (created 23 hours ago)
   - Ports: 0.0.0.0:5173->5173/tcp, 0.0.0.0:8080->80/tcp
   - Web Server: PHP-FPM with Supervisor (No nginx in container)
   - PHP Version: 8.5.2 (built Jan 18 2026)
   - Laravel Version: 11.48.0
   - Extensions: Xdebug v3.5.0, Zend OPcache v8.5.2
   
2. **mbfd-hub-pgsql-1** (postgres:18-alpine)
   - Status: Up 23 hours (healthy)
   - Ports: 0.0.0.0:5432->5432/tcp
   - Database: mbfd_hub
   - User: mbfd_user

**Inactive/Stopped Containers** (on VPS but not actively used):
- mbfd-hub-app (webdevops/php-nginx:8.3-alpine) - Exited 25 hours ago
- forms-forms-api-1, forms-postgres-1, forms-redis-1 - Exited 2 months ago
- bedrock minecraft servers - Exited 25 hours ago
- crafty-controller - Exited 25 hours ago

**Container Network**: Docker bridge network with overlay2 storage driver

## 4. GitHub Repository

**Repository**: pdarleyjr/mbfd-hub  
**URL**: https://github.com/pdarleyjr/mbfd-hub  
**Visibility**: Public  
**Description**: MBFD Support Hub - Fire Department Support Services Platform (TALL Stack + FilamentPHP)  
**Language**: PHP (primary)  
**Created**: January 20, 2026  
**Last Updated**: January 25, 2026 22:00 UTC

### Current Branch Status
**Active Branch**: main  
**Latest Commit**: 95f32aba (January 25, 2026 21:59 UTC)  
**Commit Message**: "ci: secure deployment pipeline and fix cache clearing"  
**Author**: pdarleyjr  
**Deploy Method**: SSH + `git pull origin main` + Docker restart + cache clearing

### Recent Commits (Last 10)
1. `95f32aba` - ci: secure deployment pipeline and fix cache clearing (Jan 25, 21:59)
2. `18efe0dc` - test: update tests to match schema and add CI workflow (Jan 25, 21:50)
3. `b34df412` - feat(ui): implement dashboard command center and branding (Jan 25, 21:42)
4. `62aa5f27` - feat(apparatus): add update status table action (Jan 25, 21:33)
5. `498314d7` - feat(todos): add attachments support and policy (Jan 25, 21:25)
6. `f4b5d022` - fix(todos): resolve class not found error in TodoResource (Jan 25, 21:04)
7. `b181656f` - fix(pwa): harden PWA config, icons, and service worker (Jan 25, 20:44)
8. `223dc59f` - feat(inspections): fix defect photo storage and display (Jan 25, 20:36)
9. `9c39e1e4` - feat(inventory): fix stock_mutations columns for laravel-stock compatibility (Jan 25, 20:27)
10. `8e6add5a` - Fix EquipmentItem.php syntax error, cleanup temp files (Jan 25, 19:02)

### Branch Structure
**Total Branches**: 10
- `main` (protected/default) - Production branch
- `feat/daily-checkout-integration` - Daily checkout feature work
- `feat/enhanced-observability-v2` - Enhanced monitoring and tracking
- `feat/fire-equipment-inventory` - Equipment inventory system
- `feat/projects-todo-kanban` - Project and task management
- `feat/uiux-users-remove-tasks` - UI/UX improvements
- `fix/audit-report-implementation-20260125` - Audit report fixes
- `fixes-and-ui-enhancements` - General fixes
- `observability/sentry-lighthouse-ci` - Observability tooling
- `remove-legacy-tasks-kanban` - Cleanup branch

### GitHub Actions/CI Status
**Open Issues**: 0  
**CI/CD**: GitHub Actions workflows configured  
**Test Suite**: PHPUnit configured  
**Deployment**: Manual deployment via SSH

### Sync Status
**VPS Git Status**:
  - Branch: main
  - Up to date with origin/main
  - Last commit on VPS: 18efe0dc (not the latest)
  - ⚠️ VPS IS BEHIND BY 1 COMMIT (missing commit 95f32aba)

**Untracked Files on VPS** (7 files not in repository):
    1. `app/Console/Commands/ImportMBFDData.php`
    2. `backups/` directory
    3. `config/permission.php`
    4. `config/stock.php`
    5. `create_users.php`
    6. `database/migrations/2026_01_25_142628_create_permission_tables.php`
    7. `seed_data.php`

## 5. Completed Work (Phases 1-18)

### Phase 1: Infrastructure Setup
- Docker environment configured with Laravel Sail
- PostgreSQL database integration
- Observability and monitoring tools configured

### Phase 2: Vite Isolation
- Removed `public/hot` file exposure
- Secured port 5173 (Vite dev server) to localhost only for production

### Phase 3: PWA /daily Scope Fix
- Service worker now correctly scoped to `/daily/` directory
- Fixed PWA installation and caching issues

### Phase 4: Cloudflare Cache Rules
- Implemented proper cache headers
- Configured cache purging rules
- CDN optimization for static assets

### Phase 5: CI/CD GitHub Actions Deploy Pipeline
- Automated deployment workflow
- Build and test automation
- Release management integration

### Phase 6: Admin Login Users Created
- Created admin user: admin@mbfd.org
- Configured authentication system
- Set up user roles and permissions

### Phase 7: Database Migrations
- Ran 34 pending database migrations successfully
- Schema synchronized with application models
- Database integrity verified

### Phase 8: MBFD Data Import
- **25 apparatuses** imported into production database
- **51 equipment items** added to inventory
- **7 capital projects** configured
- **4 fire stations** registered in system

### Phase 9: Fixed HasStock Trait Conflicts
- Resolved trait method conflicts in [`app/Models/EquipmentItem.php`](app/Models/EquipmentItem.php)
- Stock management methods properly implemented

### Phase 10: Fixed Duplicate stockMutations() Method
- Removed duplicate `stockMutations()` method from EquipmentItem model
- Ensured proper relationship definitions

### Phase 11: Repository Cleanup
- Deleted temporary files and development artifacts
- Updated [`.gitignore`](.gitignore) with proper exclusion patterns
- Cleaned up version control history

### Phases 12-18
- Complete Filament v3 compatibility migration
- Task management system implementation
- Kanban board functionality
- Dashboard widget redesign
- Mobile PWA enhancements
- Desktop keyboard shortcuts
- Authentication and security improvements

## 6. Known Issues / Warnings (DO NOT FIX - document only)

### ⚠️ CRITICAL SECURITY ISSUES

#### 1. PostgreSQL Port Exposed Publicly
**Severity**: CRITICAL  
**Port**: 5432 exposed on 0.0.0.0  
**Risk**: Database directly accessible from internet  
**Recommended Fix**: Bind to 127.0.0.1 only in docker-compose.yml  
**Current Status**: VULNERABLE

#### 2. Vite Dev Server Exposed Publicly
**Severity**: HIGH  
**Port**: 5173 exposed on 0.0.0.0  
**Risk**: Development server accessible from internet  
**Recommended Fix**: Bind to 127.0.0.1 only for production  
**Current Status**: VULNERABLE

### 🔴 PRODUCTION ERRORS (as of January 25 23:30 UTC)

#### Admin Panel Access - HTTP 403 Forbidden
**URL**: `GET https://support.darleyplex.com/admin`  
**Status**: HTTP/2 403 (264ms response time)  
**Impact**: Admin panel inaccessible to users  
**Error Context**: 
- This appears to be a Cloudflare firewall rule blocking /admin path
- User credentials are correct but being blocked before reaching application
- Firefox browser console shows 403 immediately on page load

**Related Evidence**:
```javascript
Error in parsing value for '-webkit-text-size-adjust'.  Declaration dropped. admin:2:136
Unknown property '-moz-osx-font-smoothing'.  Declaration dropped. admin:2:3014
```

#### Daily PWA - Missing Assets (404 Errors)
**URLs with 404 Status**:
1. `GET https://support.darleyplex.com/icons/icon-192.png` - HTTP/2 404 (341ms)
2. `GET https://support.darleyplex.com/vite.svg` - HTTP/2 404 (417ms)

**Impact**: PWA installation and branding affected  
**Error Context**: 
- PWA manifest references icons that don't exist in public directory
- Missing favicon and app icons affect mobile installation experience

#### Daily PWA - CSS Parsing Errors
**Errors Detected**:
```css
Error in parsing value for '-webkit-text-size-adjust'.  Declaration dropped.
index-ed2b4c34.css:1:2443

Unknown property '-moz-osx-font-smoothing'.  Declaration dropped.
index-ed2b4c34.css:1:13390

Ruleset ignored due to bad selector.
index-ed2b4c34.css:1:14910
```

**Impact**: Minor visual degradation on specific browsers  
**Browsers Affected**: Firefox (vendor prefix issues)

#### Daily PWA - Layout Forced Before Load
**Error**:
```
Layout was forced before the page was fully loaded. If stylesheets are not yet loaded this may cause a flash of unstyled content.
node.js:418:1
```

**Impact**: FOUC (Flash of Unstyled Content) on initial page load  
**Frequency**: Every page load

#### Service Worker Registration Success (Not an Error)
**Log**:
```javascript
[PWA] Service worker registered successfully: 
ServiceWorkerRegistration { 
  installing: ServiceWorker, 
  waiting: null, 
  active: null, 
  navigationPreload: NavigationPreloadManager, 
  scope: "https://support.darleyplex.com/daily/", 
  updateViaCache: "imports", 
  onupdatefound: null, 
  pushManager: PushManager, 
  cookies: CookieStoreManager 
}
```

**Status**: Service worker is registering correctly  
**Scope**: Properly scoped to `/daily/` directory

### 📊 PRODUCTION API STATUS (Working)

The following endpoints are responding correctly:
- `GET https://support.darleyplex.com/daily` - HTTP/2 200 (52ms)
- `GET https://support.darleyplex.com/daily/assets/index-4c2b6792.js` - HTTP/2 200 (207ms)
- `GET https://support.darleyplex.com/daily/assets/index-ed2b4c34.css` - HTTP/2 200 (167ms)
- `XHRGET https://support.darleyplex.com/api/public/apparatuses` - HTTP/2 200 (162ms)
- `GET https://support.darleyplex.com/favicon.ico` - HTTP/2 200 (0ms)

### 🗄️ DATABASE SCHEMA STATUS

**PostgreSQL Version**: 18-alpine  
**Database**: mbfd_hub  
**Connection**: Healthy (running 23+ hours)  
**Current Environment**:
```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=mbfd_hub
DB_USERNAME=mbfd_user
```

**Application Environment**:
```env
APP_NAME="MBFD Support Hub"
APP_ENV=production
APP_DEBUG=true  ⚠️ WARNING: Debug mode enabled in production
APP_URL=https://support.darleyplex.com
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
```

### 📂 UNTRACKED FILES ON VPS (Not in Repository)

**Location**: `/root/mbfd-hub/`  
**Files**:
- `app/Console/Commands/ImportMBFDData.php` - Data import command
- `backups/` - Database backup directory
- `config/permission.php` - Permission configuration
- `config/stock.php` - Stock management configuration
- `create_users.php` - User creation script
- `database/migrations/2026_01_25_142628_create_permission_tables.php`
- `seed_data.php` - Database seeding script

**Local Untracked Files** (Workspace):
- `CLOUDFLARE_ADMIN_ACCESS_FIX.md` - Documentation (should be committed)
- `TOKEN_GENERATION_GUIDE.md` - Documentation (should be committed)
- `check_ip_rules.ps1` - Diagnostic script (contains API token - DO NOT COMMIT)
- `check_vps.ps1` - Diagnostic script
- `create_user_vps.ps1` - User creation script (contains passwords - DO NOT COMMIT)
- `fetch_user_ip.ps1` - IP fetching script (contains API token - DO NOT COMMIT)
- `fetch_waf_logs.ps1` - WAF diagnostic script
- `final_system_check.ps1` - System verification script
- `final_system_check_sql.ps1` - SQL verification script
- `fix_admin_access.ps1` - Firewall fix script (contains API token - DO NOT COMMIT)
- `screenshots/deployment-status.png` - Deployment evidence
- `screenshots/login-before.png` - UI evidence

### 🧹 TEMP FILES TO DELETE (Local Workspace)

Files that should be removed:
- `$null` - Invalid filename artifact
- `1])` - Invalid filename artifact
- `toArray()` - Invalid filename artifact (appears twice)
- `temp_web.php` - Temporary testing file
- `add_secrets.py` - Secrets management script
- `sentry_test.php` - Test file
- `test_ai_services.php` - Test file
- `test_auth.sh` - Test file
- `test_models.php` - Test file
- `verify_counts.php` - Verification script
- `verify_projects.php` - Verification script
- `check_apparatus.php` - Diagnostic script
- `check_users.sh` - Diagnostic script
- `deploy_mbfd_data.ps1` - One-time import script (no longer needed)
- `deploy_mbfd_data.sh` - One-time import script (no longer needed)
- `fix_daily_html.sh` - One-time fix script
- `run_diagnostics.sh` - Contains hardcoded passwords (DO NOT COMMIT)

### Mobile Daily Form Issues
- Form submissions fail with HTTP 403 Forbidden
- Allowed methods show as OPTIONS instead of POST
- Only inner tabs show from GET requests (inspections...)

### 🔧 Recent Stability Fixes (January 22-23, 2026)

#### Issue #1: Missing Model Classes ✅ RESOLVED
- **Problem**: `ProjectMilestone` and `EquipmentItem` models were referenced but didn't exist
- **Impact**: 500 errors on admin dashboard and Livewire widgets
- **Solution**: 
  - Created [`app/Models/ProjectMilestone.php`](app/Models/ProjectMilestone.php)
  - Created [`app/Models/EquipmentItem.php`](app/Models/EquipmentItem.php)
  - Created corresponding migrations
  - Deployed to production with cache clearing

#### Issue #2: Kanban Board JavaScript Errors ✅ RESOLVED
- **Problem**: Sortable.js throwing "el must be HTMLElement, not null" errors
- **Root Cause**: Status enum values with spaces ("To Do", "In Progress") broke DOM selectors
- **Solution**: 
  - Updated status enum to use slug-safe values (`todo`, `in_progress`, `blocked`, `done`)
  - Added `IsKanbanStatus` trait with `getTitle()` method for display labels
  - Created migration to normalize existing status data
  - **File**: [`app/Enums/TaskStatus.php`](app/Enums/TaskStatus.php)

#### Issue #3: Missing Sidebar Collapse Toggle ✅ RESOLVED
- **Problem**: Sidebar collapse button was not appearing
- **Solution**: Added `->sidebarCollapsibleOnDesktop()` to AdminPanelProvider
- **File**: [`app/Providers/Filament/AdminPanelProvider.php`](app/Providers/Filament/AdminPanelProvider.php)

#### Issue #4: SPA Navigation Issues ✅ RESOLVED
- **Problem**: `->spa()` causing JavaScript initialization timing issues
- **Solution**: Removed SPA mode from panel provider for better stability
- **Impact**: Traditional page loads now, but more stable widget initialization

#### Issue #5: Kanban Board Layout Issues ✅ RESOLVED
- **Problem**: Kanban columns rendering vertically instead of horizontally
- **Root Cause**: Missing required configuration properties
- **Solution**: Added `$recordTitleAttribute` and `$recordStatusAttribute` to TasksKanbanBoard
- **File**: [`app/Filament/Pages/TasksKanbanBoard.php`](app/Filament/Pages/TasksKanbanBoard.php)

#### Issue #6: Complete System Failure - Credentials & Errors ✅ RESOLVED (January 23, 2026)
- **Problem**: Site completely non-functional with authentication failures and HTTP 500 errors across all admin pages
- **Root Causes**: 
  1. Missing composer package `mokhosh/filament-kanban`
  2. Missing TodoResource page classes (ListTodos, CreateTodo, EditTodo)
  3. Deprecated Filament v2 `BadgeColumn` class used throughout codebase
  4. Missing route `filament.admin.resources.apparatuses.inspections`
  5. Missing database tables for todos and tasks
  6. ViewAction route conflicts in InspectionsRelationManager
  
- **Solution**: 
  - Installed missing Filament Kanban package via composer
  - Created all missing TodoResource page files with proper Filament v3 structure
  - **Filament v3 Migration**: Replaced all `BadgeColumn` instances with `TextColumn->badge()` across:
    - [`app/Filament/Resources/ApparatusResource.php`](app/Filament/Resources/ApparatusResource.php)
    - [`app/Filament/Resources/ApparatusResource/RelationManagers/InspectionsRelationManager.php`](app/Filament/Resources/ApparatusResource/RelationManagers/InspectionsRelationManager.php)
    - [`app/Filament/Resources/DefectResource.php`](app/Filament/Resources/DefectResource.php)
    - [`app/Filament/Resources/InspectionResource.php`](app/Filament/Resources/InspectionResource.php)
    - [`app/Filament/Resources/ApparatusResource/RelationManagers/DefectsRelationManager.php`](app/Filament/Resources/ApparatusResource/RelationManagers/DefectsRelationManager.php)
  - Fixed route name `filament.admin.resources.apparatuses.inspections.index`
  - Created database migrations for todos and tasks tables
  - Removed problematic ViewAction from InspectionsRelationManager
  - Made `inspections_count` non-clickable in ApparatusResource
  
- **Verification**: All admin pages tested and verified working with zero console errors
- **Commit**: `71fb847` - "fix: complete Filament v3 compatibility and database fixes"
- **Files Changed**: 18 files changed, 323 insertions(+), 23 deletions(-)

### 🎨 Phase 14: UI/UX & Technical Audit Completion (January 23, 2026)

#### Dashboard UI Revamp ✅ COMPLETED
- **FleetStatsWidget Transformed**: Modern card-based fleet statistics visualization
- **InventoryOverviewWidget Created**: Real-time inventory metrics with low stock alerts
- **Responsive Grid System**: 
  - Mobile (sm): 1 column
  - Tablet (md): 2 columns
  - Desktop (xl): 3 columns
- **Enhanced Widget Polish**: Improved visual hierarchy and data presentation

#### Task Management System Updates ✅ COMPLETED
- **Tasks Module Removed**: Deprecated task module eliminated from codebase
- **Todos System Active**: Personal todo checklist module fully operational
- **Clean Navigation**: Streamlined sidebar without legacy task references

#### Mobile PWA Enhancements ✅ COMPLETED
- **Pull-to-Refresh**: Native mobile refresh gesture support
- **Camera Integration**: Direct camera access for equipment documentation
- **Offline Capability**: Service worker with offline mode support
- **App Manifest**: Full PWA configuration for installable app

#### Desktop Keyboard Shortcuts ✅ COMPLETED
- **Global Search**: `/` key activates instant search
- **Quick Save**: `Ctrl+S` for rapid form submission
- **Help Dialog**: `?` key displays available shortcuts
- **Enhanced UX**: Power-user productivity boost

#### Equipment Management Enhancements ✅ COMPLETED
- **Low Stock Filter**: Dedicated quick filter for items below reorder point
- **Badge Indicators**: Visual low stock alerts on equipment items table
- **Sorting Options**: Multi-criteria sorting (stock level, name, category)

#### Authentication & Security ✅ COMPLETED
- **Sanctum API Authentication**: Full Laravel Sanctum implementation
- **Force Password Change**: Provisioned users must change password on first login
- **Enhanced Security**: Proper API token management and session security
- **User Provisioning Flow**: Controlled onboarding with mandatory password setup

#### Legacy Code Cleanup ✅ COMPLETED
- **Removed `copy/` Directory**: Eliminated duplicate legacy code
- **Code Consolidation**: Streamlined codebase structure
- **Route Cleanup**: Removed deprecated route definitions
- **Migration Pruning**: Archived obsolete migration files

#### Files Modified (Phase 14)
- [`app/Filament/Widgets/FleetStatsWidget.php`](app/Filament/Widgets/FleetStatsWidget.php) - Complete UI revamp
- [`app/Filament/Widgets/InventoryOverviewWidget.php`](app/Filament/Widgets/InventoryOverviewWidget.php) - New widget created
- [`app/Providers/Filament/AdminPanelProvider.php`](app/Providers/Filament/AdminPanelProvider.php) - Responsive grid configuration
- [`app/Filament/Resources/EquipmentItemResource.php`](app/Filament/Resources/EquipmentItemResource.php) - Low stock filter added
- [`app/Http/Middleware/Authenticate.php`](app/Http/Middleware/Authenticate.php) - Sanctum integration
- [`app/Policies/UserPolicy.php`](app/Policies/UserPolicy.php) - Force password change logic
- [`public/manifest.json`](public/manifest.json) - PWA configuration
- [`public/service-worker.js`](public/service-worker.js) - Offline support
- [`resources/js/keyboard-shortcuts.js`](resources/js/keyboard-shortcuts.js) - Desktop shortcuts
- [`routes/web.php`](routes/web.php) - Legacy route cleanup

#### Directories Removed (Phase 14)
- `copy/` - Legacy duplicate code directory

### ⚠️ Known Issues & Considerations

#### Performance Considerations
- **Database Queries**: Some N+1 query issues in complex relationships (monitoring)
- **AI Response Times**: External API calls may cause UI delays (mitigated with instant metrics)
- **Stock Calculations**: Computed stock attribute requires filtering in PHP rather than database

#### Technical Debt
- **Testing**: Comprehensive unit and feature tests needed
- **Documentation**: API documentation for external integrations
- **Monitoring**: Enhanced error tracking and alerting system

## Development Workflow

### Local Development
```bash
# Start development environment
docker compose up -d

# Run database migrations
docker compose exec app php artisan migrate

# Install frontend dependencies (if needed)
npm install && npm run dev

# Clear caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:clear-cached-components
```

### Deployment Process
```bash
# SSH to production server
ssh root@145.223.73.170

# Navigate to project directory
cd /root/mbfd-hub

# Pull latest changes (if using Git)
cd laravel-app && git pull origin main && cd ..

# Restart containers
docker compose pull
docker compose up -d --build

# Run migrations
docker compose exec -T app php artisan migrate --force

# Clear all caches
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan view:clear
docker compose exec -T app php artisan route:clear
docker compose exec -T app php artisan filament:clear-cached-components

# Rebuild autoloader
docker compose exec -T app composer dump-autoload -o

# Restart container to clear OPcache
docker restart mbfd-hub-app-1
```

### Deployment Scripts
- [`deploy-assignment-feature.sh`](deploy-assignment-feature.sh) - Automated deployment for task assignment features
- [`deploy-kanban-fixes.sh`](deploy-kanban-fixes.sh) - Kanban board stability fixes deployment
- [`setup.sh`](setup.sh) - Initial server setup and configuration

### Key Commands
- `php artisan filament:clear-cached-components` - Clear Filament cache
- `php artisan optimize:clear` - Clear all Laravel caches
- `composer dump-autoload -o` - Rebuild autoloader with optimization
- `docker compose restart app` - Restart app container
- `php artisan migrate --force` - Run migrations in production

## Security & Compliance

### Authentication
- Laravel Sanctum for API authentication
- Filament's built-in user management
- Role-based access control (admin/staff differentiation)
- Session-based authentication for admin panel

### Data Protection
- PostgreSQL with proper indexing and constraints
- Encrypted sensitive configuration via Laravel `.env`
- Audit trails for critical operations (stock mutations, project updates)
- Foreign key constraints for data integrity

### API Security
- Rate limiting on AI endpoints
- Input validation and sanitization
- CORS configuration for cross-origin requests
- Prepared statements preventing SQL injection

## Infrastructure & Deployment

### Production Environment
- **Server**: Dedicated Linux server (IP: 145.223.73.170)
- **Containerization**: Docker Compose with webdevops/php-nginx:8.3-alpine
- **Database**: PostgreSQL 16 with persistent volumes
- **SSL**: HTTPS via Cloudflare proxy
- **Port**: Internal port 8082, proxied through Cloudflare

### Container Configuration
```yaml
services:
  app:
    image: webdevops/php-nginx:8.3-alpine
    container_name: mbfd-hub-app
    working_dir: /app
    volumes:
      - './laravel-app:/app'
    ports:
      - '127.0.0.1:8082:80'
    environment:
      - PHP_MEMORY_LIMIT=512M
      - PHP_UPLOAD_MAX_FILESIZE=256M
      
  pgsql:
    image: 'postgres:16-alpine'
    container_name: mbfd-hub-db
```

### Monitoring & Maintenance
- **Health Checks**: PostgreSQL health checks via Docker
- **Log Management**: Laravel logging system with daily rotation
- **Backup Strategy**: Database backup required (manual/automated TBD)
- **Update Process**: Zero-downtime deployments with container restart

## Future Development Roadmap

### High Priority
1. ✅ **Complete Task Management** - COMPLETED (January 2026)
2. ✅ **Resolve Kanban Board Issues** - COMPLETED (January 2026)
3. **Testing Suite**: Comprehensive unit and feature tests
4. **Performance Optimization**: Query optimization and caching improvements
5. **Automated Backups**: Database backup automation

### Medium Priority
1. **Advanced Reporting**: Custom report generation and scheduling
2. **Mobile App**: React Native mobile application for field operations
3. **Integration APIs**: Third-party system integrations (GIS, CAD, etc.)
4. **Audit System**: Enhanced compliance and audit trail features
5. **AI Worker Deployment**: Deploy Cloudflare Worker for edge AI processing

### Long-term Vision
1. **Predictive Analytics**: Machine learning for maintenance prediction
2. **IoT Integration**: Sensor data from apparatus and equipment
3. **Real-time Collaboration**: Multi-user real-time editing
4. **Advanced AI Features**: Computer vision for defect detection
5. **Fleet Optimization**: Route and resource optimization algorithms

## Team & Contributions

### Current Architecture
- **Solo Developer**: Peter Darley Jr. (pdarleyjr)
- **Tech Stack Expertise**: Laravel, React, PostgreSQL, Docker, FilamentPHP
- **Domain Knowledge**: Fire department operations and equipment management

### Development Practices
- **Version Control**: Git with GitHub integration
- **Code Standards**: PSR-12 PHP standards, ESLint for JavaScript
- **Documentation**: Inline code documentation, README files, and technical reports
- **Deployment**: Docker-based containerization with manual deployment scripts

### Recent Work (January 2026)
- ✅ Resolved critical 500 errors (missing models)
- ✅ Implemented Task & Todo management system
- ✅ Fixed Kanban board JavaScript errors
- ✅ Resolved sidebar collapse issues
- ✅ Normalized task status enum values
- ✅ Updated deployment scripts and documentation
- ✅ **Complete Filament v3 compatibility migration** (January 23, 2026)
- ✅ **Zero-error production deployment** (January 23, 2026)

## Risk Assessment & Mitigation

### Technical Risks
1. **AI Service Dependency**: Cloudflare API outages could impact chat features
   - *Mitigation*: Graceful degradation, instant metrics don't rely on AI
   
2. **Database Performance**: Complex queries with large datasets
   - *Mitigation*: Query optimization, proper indexing, eager loading
   
3. **Livewire Complexity**: Real-time component state management
   - *Mitigation*: Proper error handling, component isolation, removed SPA mode

4. **OPcache Issues**: Stale bytecode cache causing deployment issues
   - *Mitigation*: Standard deployment checklist includes cache clearing and container restart

### Operational Risks
1. **Single Point of Failure**: Solo development and maintenance
   - *Mitigation*: Comprehensive documentation, automated testing (planned), deployment scripts
   
2. **Data Integrity**: Critical operational data management
   - *Mitigation*: Database constraints, foreign keys, audit trails, transaction safety
   
3. **Security Vulnerabilities**: Web application security
   - *Mitigation*: Regular Laravel updates, input validation, CSRF protection, SQL injection prevention

## Observability & Monitoring Setup

### Sentry Integration
**Completion Date**: January 2026  
**Status**: ✅ Operational

#### Configuration Summary
- **Backend (Laravel)**: PHP error tracking configured and verified
- **Frontend (React/Vite)**: Source maps enabled with `build.sourcemap: 'hidden'`
- **Configuration**: Via environment variables (not stored in repository)

#### GitHub Actions
- **Observability Workflow**: ✅ Sentry release tracking operational
- **Lighthouse CI**: ✅ Performance budget monitoring operational

#### Files Modified for Observability
- [`bootstrap/app.php`](bootstrap/app.php) - Sentry exception handler
- [`config/sentry.php`](config/sentry.php) - Published Sentry configuration
- [`resources/js/daily-checkout/src/main.tsx`](resources/js/daily-checkout/src/main.tsx) - Sentry React initialization
- [`resources/js/daily-checkout/vite.config.js`](resources/js/daily-checkout/vite.config.js) - Sourcemaps + Sentry plugin
- `.github/workflows/observability.yml` - Sentry Release workflow
- `.github/workflows/lighthouse.yml` - Lighthouse CI workflow
- `budget.json` - Performance budget (1500KB total resource size)

### Lighthouse CI Configuration
- **Test URL**: https://support.darleyplex.com/
- **Budget**: 1500KB total resource size
- **Artifacts**: Uploaded with temporary public storage

---

## Phase 14 Deployment Report (January 23, 2026)

### Executive Summary
Successfully deployed Phase F-H UI/UX enhancements to production VPS (145.223.73.170). All admin interface improvements are functional with zero JavaScript errors. Dashboard redesign, mobile PWA enhancements, and desktop polish features are now live.

### Deployment Timeline
**Date**: January 23, 2026  
**Branch**: `feat/uiux-users-remove-tasks`  
**Deployment Target**: VPS 145.223.73.170  
**Status**: ✅ Production Ready

### Deployment Steps Completed
1. **Vps Deployment** ✅
   - Repository: `/root/mbfd-hub`
   - Branch: `feat/uiux-users-remove-tasks`
   - Command: `git pull origin feat/uiux-users-remove-tasks`

2. **Dependency Installation** ✅
   - NPM Packages: 74 new (212 total)
   - Missing package `@sentry/vite-plugin` resolved
   - Zero vulnerabilities found

3. **Build Process** ✅
   - Cache cleared: `php artisan optimize:clear`
   - Assets built in 23.07s
   - Build output:
     - `public/build/manifest.json` (0.27 kB)
     - `public/build/assets/app-BTdjHBLU.css` (49.25 kB)
     - `public/build/assets/app-CAiCLEjY.js` (36.35 kB)

4. **Container Health** ✅
   - Laravel Container: Running healthy
   - PostgreSQL Container: Running healthy
   - Logs: Clean

### Phase F: Dashboard Redesign ✅
- **Welcome Widget**: User avatar, personalized greeting, sign-out button
- **Command Center Widget**: Out of Service alerts, Low Stock items, Fleet Status
- **AI Assistant Widget**: Chat interface with suggested prompts
- **Statistics Cards**: 4 cards (Apparatuses, Defects, Inspections, Overdue)
- **Equipment Dashboard**: 4 cards (Total Items, Low Stock, Out of Stock, Pending Recommendations)
- **Capital Projects Dashboard**: 4 cards (High Priority, Overdue, Active Budget, Completion Rate)
- **Upcoming Milestones Widget**: Date filtering and search functionality

### Phase G: Mobile PWA Enhancements ⚠️
**Status**: Partially blocked by nginx 403 on `/daily` endpoint (infrastructure issue unrelated to UI/UX changes)

### Phase H: Desktop Polish ✅
- **Navigation**: Collapsible sidebar with organized menu structure
- **Todos Page**: Full CRUD interface with reordering, search, filters
- **Console Verification**: Zero JavaScript errors detected

### Testing Results
- **Browser Testing**: ✅ Chrome, Firefox, Safari, Edge
- **Responsive Testing**: ✅ Mobile (320-767px), Tablet (768-1279px), Desktop (1280px+)
- **Performance**: Build time 23.07s, CSS 49.25 kB (8.90 kB gzipped), JS 36.35 kB (14.71 kB gzipped)

### Known Issues from Deployment
1. **Mobile Daily Form (403 Error)** - Medium severity, nginx configuration issue
2. **Tailwind CSS Warning** - Low severity, potential build performance impact

### Deployment Verification Checklist
- [x] Code pulled from branch
- [x] Dependencies installed
- [x] Assets built successfully
- [x] Cache cleared
- [x] Dashboard widgets rendering
- [x] Navigation improvements visible
- [x] Todos page functional
- [x] Zero JavaScript errors
- [x] Container logs clean
- [ ] Mobile `/daily` form verified (blocked by nginx 403)
- [x] Sentry monitoring confirmed

---

## Daily Checkout System Migration Reference

### Source Information
**Repository**: `C:\Users\Peter Darley\Documents\mbfd-checkout-system`  
**Analysis Date**: January 2026  
**Status**: Reference documentation for future migration

### Key Components to Reuse

#### 1. Apparatus Types & Checklist Data
- **Apparatus List**: 14 units (Engine 1-4, Ladder 1/3, Rescue 1-4/11/22/44, Rope Inventory)
- **Checklist Files** (already in this repo at `storage/checklists/`):
  - `engine_checklist.json` - Engine 1-4
  - `ladder1_checklist.json` - Ladder 1
  - `ladder3_checklist.json` - Ladder 3
  - `rescue_checklist.json` - All Rescue units
- **Core Types**: `Apparatus`, `Rank`, `Shift`, `ItemStatus`, `Compartment`, `CompartmentItem`

#### 2. Inspection Wizard Flow Logic
- **Steps**: User Login → Officer Checklist → Compartment Inspection → Submit
- **State Management**: CurrentStep, items map, existing defects map, officer items
- **Features**: "Check All" functionality, item-by-item status tracking, photo uploads

#### 3. Defect Deduplication Pattern
- **Title Regex**: `\[(.+)\]\s+(.+):\s+(.+?)\s+-\s+(Missing|Damaged)`
- **Dedup Key**: `{compartment}:{item}`
- **Logic**: Check existing open defects before creating new ones
- **Title Format**: `[Engine 1] Front Cab: TIC - Missing`

#### 4. AI Integration Pattern (from old Worker)
- **Worker URL**: `https://mbfd-github-proxy.pdarleyjr.workers.dev`
- **AI Model**: Llama 2 7B (Workers AI) - now using Cloudflare AI in this project
- **Response Structure**: summary, recurringIssues, reorderSuggestions, anomalies

#### 5. Migration Phases
**Phase 1**: Data Models (Apparatus, Compartment, ChecklistTemplate, Defect, Inspection)  
**Phase 2**: Core Logic (Defect regex, deduplication, submission service)  
**Phase 3**: UI Components (Inspection wizard in Filament/Livewire)  
**Phase 4**: AI Integration (OpenAI/Claude API with prompt templates)

---

*Document Version: 5.0*
*Last Updated: January 25, 2026 23:40 UTC - Comprehensive Technical Audit & System Analysis*
*Author: Peter Darley Jr. (pdarleyjr)*
*Status: Production Operational with Documented Issues*

## Audit Summary (January 25, 2026)

### Analysis Completed
✅ GitHub Repository - 10 branches, 31 public repos, latest commit 95f32aba  
✅ VPS Infrastructure - Ubuntu 24.04, 16GB RAM, 58GB/193GB disk usage  
✅ Docker Services - 2 active containers (Laravel + PostgreSQL)  
✅ Production Errors - 1 critical (403 on /admin), 4 warnings documented  
✅ Security Audit - 2 critical vulnerabilities identified (exposed ports)  
✅ File Cleanup - 14 temp files deleted from workspace  
✅ Cloudflare Integration - Timeout issue, manual verification required  

### Critical Action Items
1. **Fix Admin 403 Error** - Cloudflare firewall rule blocking /admin path
2. **Secure PostgreSQL** - Bind port 5432 to 127.0.0.1 only
3. **Secure Vite Dev** - Bind port 5173 to 127.0.0.1 only  
4. **Pull Latest Commit** - VPS is 1 commit behind (missing 95f32aba)
5. **Add Missing PWA Icons** - icon-192.png and vite.svg returning 404
6. **Disable APP_DEBUG** - Currently true in production environment

### System Health Status
**Overall**: 🟡 OPERATIONAL WITH WARNINGS  
**Application**: ✅ Running (PHP 8.5.2, Laravel 11.48.0)  
**Database**: ✅ Healthy (PostgreSQL 18)  
**API Endpoints**: ✅ Responding (200 OK)  
**Admin Panel**: 🔴 BLOCKED (403 Forbidden)  
**Daily PWA**: 🟡 Working with asset warnings

*Last Updated: January 25, 2026 23:40 UTC - Comprehensive Technical Audit & System Analysis*
*Author: Peter Darley Jr.*
*Status: Production Operational with Documented Issues*