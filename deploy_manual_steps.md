# Manual Deployment Steps for Capital Projects Fix

Due to SSH host key issues, follow these manual steps:

## Step 1: Copy Files to VPS

Run these PowerShell commands from the project root:

```powershell
# Copy migration file
scp -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" `
    "database/migrations/2026_02_03_000001_fix_capital_projects_schema.php" `
    root@145.223.73.170:/var/www/mbfd-support-hub/database/migrations/

# Copy RelationManager
scp -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" `
    "app/Filament/Resources/StationResource/RelationManagers/CapitalProjectsRelationManager.php" `
    root@145.223.73.170:/var/www/mbfd-support-hub/app/Filament/Resources/StationResource/RelationManagers/
```

If you get a host key warning, type `yes` to accept.

## Step 2: SSH into VPS and Run Commands

```bash
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170
```

Once connected, run:

```bash
cd /var/www/mbfd-support-hub

# Run migration
docker-compose exec laravel.test php artisan migrate --force

# Clear caches
docker-compose exec laravel.test php artisan cache:clear
docker-compose exec laravel.test php artisan config:clear
docker-compose exec laravel.test php artisan view:clear
docker-compose exec laravel.test php artisan route:clear

# Optimize
docker-compose exec laravel.test php artisan optimize

# Exit SSH
exit
```

## Step 3: Verify the Fix

1. Open browser to: https://support.darleyplex.com/admin
2. Navigate to: Stations → [select a station] → Capital Projects tab
3. Verify the tab loads without HTTP 500 error

## What Was Fixed:

1. **Database enum values** - Updated from 'Planning'/'In Progress' to 'pending'/'in_progress' to match PHP enum
2. **Column names** - Fixed mismatches:
   - `budget` → `budget_amount`
   - Added `project_number` column
   - Added `percent_complete` column
3. **RelationManager** - Updated to use correct column names and added explicit filter options
