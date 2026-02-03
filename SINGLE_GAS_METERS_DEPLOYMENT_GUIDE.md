# Single Gas Meters Feature - Manual Deployment Guide

## Overview
This guide will help you manually deploy the Single Gas Meters tracking feature to your VPS.

## Files Created

### 1. Models
- `laravel-app/app/Models/SingleGasMeter.php` (NEW)
- `laravel-app/app/Models/Apparatus.php` (UPDATED - added `singleGasMeters()` relationship)
- `laravel-app/app/Models/Station.php` (UPDATED - added `singleGasMeters()` and `apparatuses()` relationships)

### 2. Migration
- `laravel-app/database/migrations/2026_02_03_000001_create_single_gas_meters_table.php` (NEW)

### 3. Filament Resources
- `laravel-app/app/Filament/Resources/SingleGasMeterResource.php` (NEW)
- `laravel-app/app/Filament/Resources/StationResource.php` (UPDATED - added relation manager)

### 4. Filament Pages
- `laravel-app/app/Filament/Resources/SingleGasMeterResource/Pages/ListSingleGasMeters.php` (NEW)
- `laravel-app/app/Filament/Resources/SingleGasMeterResource/Pages/CreateSingleGasMeter.php` (NEW)
- `laravel-app/app/Filament/Resources/SingleGasMeterResource/Pages/EditSingleGasMeter.php` (NEW)

### 5. Exporter
- `laravel-app/app/Filament/Exports/SingleGasMetersExporter.php` (NEW)

### 6. Relation Manager
- `laravel-app/app/Filament/Resources/StationResource/RelationManagers/SingleGasMetersRelationManager.php` (NEW)

## Manual Deployment Steps

### Option 1: Using Git (Recommended)

1. **Commit all changes to git:**
   ```bash
   git add .
   git commit -m "feat: Add Single Gas Meters tracking feature"
   git push origin main
   ```

2. **SSH into your VPS:**
   ```bash
   ssh -i "path/to/your/key.pem" root@104.128.90.91
   ```

3. **Navigate to project directory:**
   ```bash
   cd /var/www/html
   ```

4. **Pull the latest changes:**
   ```bash
   git pull origin main
   ```

5. **Run migrations:**
   ```bash
   docker compose exec laravel-app php artisan migrate --force
   ```

6. **Clear and rebuild caches:**
   ```bash
   docker compose exec laravel-app php artisan config:clear
   docker compose exec laravel-app php artisan cache:clear
   docker compose exec laravel-app php artisan route:clear
   docker compose exec laravel-app php artisan view:clear
   docker compose exec laravel-app php artisan config:cache
   docker compose exec laravel-app php artisan route:cache
   docker compose exec laravel-app php artisan view:cache
   ```

7. **Restart the container:**
   ```bash
   docker compose restart laravel-app
   ```

### Option 2: Using SCP (Manual File Transfer)

If you can't use git, use these commands to copy files:

```powershell
# From your local machine, run:
$SSH_KEY = "path\to\your\key.pem"
$VPS = "root@104.128.90.91"
$REMOTE = "/var/www/html"

# Copy Models
scp -i $SSH_KEY laravel-app/app/Models/SingleGasMeter.php ${VPS}:${REMOTE}/app/Models/
scp -i $SSH_KEY laravel-app/app/Models/Apparatus.php ${VPS}:${REMOTE}/app/Models/
scp -i $SSH_KEY laravel-app/app/Models/Station.php ${VPS}:${REMOTE}/app/Models/

# Copy Migration
scp -i $SSH_KEY laravel-app/database/migrations/2026_02_03_000001_create_single_gas_meters_table.php ${VPS}:${REMOTE}/database/migrations/

# Copy Resource
scp -i $SSH_KEY laravel-app/app/Filament/Resources/SingleGasMeterResource.php ${VPS}:${REMOTE}/app/Filament/Resources/
scp -i $SSH_KEY laravel-app/app/Filament/Resources/StationResource.php ${VPS}:${REMOTE}/app/Filament/Resources/

# Create directories and copy Pages
ssh -i $SSH_KEY $VPS "mkdir -p ${REMOTE}/app/Filament/Resources/SingleGasMeterResource/Pages"
scp -i $SSH_KEY laravel-app/app/Filament/Resources/SingleGasMeterResource/Pages/*.php ${VPS}:${REMOTE}/app/Filament/Resources/SingleGasMeterResource/Pages/

# Copy Exporter
ssh -i $SSH_KEY $VPS "mkdir -p ${REMOTE}/app/Filament/Exports"
scp -i $SSH_KEY laravel-app/app/Filament/Exports/SingleGasMetersExporter.php ${VPS}:${REMOTE}/app/Filament/Exports/

# Copy Relation Manager
ssh -i $SSH_KEY $VPS "mkdir -p ${REMOTE}/app/Filament/Resources/StationResource/RelationManagers"
scp -i $SSH_KEY laravel-app/app/Filament/Resources/StationResource/RelationManagers/SingleGasMetersRelationManager.php ${VPS}:${REMOTE}/app/Filament/Resources/StationResource/RelationManagers/
```

Then run the migration and cache commands from Option 1, steps 5-7.

## Verification

After deployment, verify the feature is working:

1. **Log into the admin panel** at https://support.darleyplex.com/admin
2. **Check navigation** - You should see "Single Gas Meters" under "Fire Equipment" section
3. **Create a test meter:**
   - Click "Single Gas Meters" > "New Single Gas Meter"
   - Select an apparatus (e.g., E1)
   - Enter a 5-digit serial number
   - Select an activation date
   - Verify expiration date is auto-calculated (+2 years)
   - Save
4. **Test exports:**
   - Click "Export" from the list page
   - Verify CSV contains all meters with proper data
5. **Test Station view:**
   - Go to Stations list
   - Click on any station
   - Look for "Single Gas Meters" tab
   - Verify it shows meters for apparatus at that station

## Feature Capabilities

### Main Features
✅ Track Watchgas portable single gas detectors by apparatus
✅ Auto-calculate expiration dates (activation + 2 years)
✅ Status badges (Valid/Expired)
✅ Days until expiration counter
✅ CSV export (individual and bulk)
✅ Filter by station, apparatus, expired status
✅ Search by serial number
✅ Prevent duplicate serial numbers

### Station Integration
✅ View all gas meters assigned to apparatuses at a station
✅ Add meters directly from station page
✅ Filter expired or expiring soon

### Validation
✅ Serial number: exactly 5 alphanumeric characters
✅ Activation date: cannot be in future
✅ Unique serial numbers across system
✅ Expiration auto-calculation on save

## Troubleshooting

### Migration Fails
```bash
# Check if table already exists
docker compose exec laravel-app php artisan tinker
>>> Schema::hasTable('single_gas_meters');

# If true, manually drop and rerun:
>>> Schema::dropIfExists('single_gas_meters');
>>> exit

docker compose exec laravel-app php artisan migrate --force
```

### Feature Not Appearing
```bash
# Clear all caches
docker compose exec laravel-app php artisan optimize:clear
docker compose exec laravel-app php artisan config:cache
docker compose exec laravel-app php artisan route:cache
docker compose restart laravel-app
```

### Export Not Working
- Ensure `filament/actions` package is installed
- Check `SingleGasMetersExporter` class exists
- Verify `app/Filament/Exports` directory exists

## Database Schema

```sql
CREATE TABLE single_gas_meters (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    apparatus_id BIGINT NOT NULL,
    serial_number VARCHAR(5) UNIQUE NOT NULL,
    activation_date DATE NOT NULL,
    expiration_date DATE NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (apparatus_id) REFERENCES apparatuses(id) ON DELETE CASCADE
);
```

## Support

If you encounter any issues:
1. Check Laravel logs: `docker compose logs laravel-app`
2. Check database connection: `docker compose exec laravel-app php artisan tinker`
3. Verify all files were copied correctly
4. Ensure directory permissions are correct: `chown -R www-data:www-data /var/www/html`

---

**Deployment Date:** February 3, 2026
**Developer:** Kilo Code
**Status:** Ready for Deployment
