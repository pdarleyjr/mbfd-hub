# MBFD Data Import Instructions

## Files Created
1. **`laravel-app/app/Console/Commands/ImportMBFDData.php`** - Laravel Artisan command with embedded data
2. **`deploy_mbfd_data.sh`** - Bash deployment script
3. **`deploy_mbfd_data.ps1`** - PowerShell deployment script

## Manual Deployment Steps

### Step 1: Copy the Import Command to VPS

```bash
scp -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" -o "StrictHostKeyChecking=no" \
    "laravel-app/app/Console/Commands/ImportMBFDData.php" \
    root@145.223.73.170:/root/mbfd-hub/app/Console/Commands/ImportMBFDData.php
```

### Step 2: SSH into VPS

```bash
ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" -o "StrictHostKeyChecking=no" root@145.223.73.170
```

### Step 3: Navigate to Project Directory

```bash
cd /root/mbfd-hub
```

### Step 4: Check Current Database Counts (BEFORE Import)

```bash
docker compose exec -T laravel.test php artisan tinker --execute="
echo 'BEFORE Import - Current Counts:' . PHP_EOL;
echo '  Apparatuses: ' . \App\Models\Apparatus::count() . PHP_EOL;
echo '  Equipment Items: ' . \App\Models\EquipmentItem::count() . PHP_EOL;
echo '  Inventory Locations: ' . \App\Models\InventoryLocation::count() . PHP_EOL;
echo '  Capital Projects: ' . \App\Models\CapitalProject::count() . PHP_EOL;
echo '  Stations: ' . \App\Models\Station::count() . PHP_EOL;
"
```

### Step 5: Run the Imports

#### Import Supply Inventory (50 items sample)
```bash
docker compose exec -T laravel.test php artisan mbfd:import inventory
```

#### Import Apparatus Status (25 units from latest report)
```bash
docker compose exec -T laravel.test php artisan mbfd:import apparatus
```

#### Import Capital Projects (7 projects)
```bash
docker compose exec -T laravel.test php artisan mbfd:import projects
```

#### OR Run All Imports at Once
```bash
docker compose exec -T laravel.test php artisan mbfd:import
```

### Step 6: Verify Final Counts (AFTER Import)

```bash
docker compose exec -T laravel.test php artisan tinker --execute="
echo PHP_EOL . 'AFTER Import - Final Counts:' . PHP_EOL;
echo '  Apparatuses: ' . \App\Models\Apparatus::count() . PHP_EOL;
echo '  Equipment Items: ' . \App\Models\EquipmentItem::count() . PHP_EOL;
echo '  Inventory Locations: ' . \App\Models\InventoryLocation::count() . PHP_EOL;
echo '  Capital Projects: ' . \App\Models\CapitalProject::count() . PHP_EOL;
echo '  Stations: ' . \App\Models\Station::count() . PHP_EOL;
"
```

## Expected Results

### Inventory Import
- **Inventory Locations**: ~6 locations (A1-A4, B1-B4, C1, F3, etc.)
- **Equipment Items**: ~50 items (sample from 195 total)

### Apparatus Import
- **Stations**: 4 stations (Station 1-4)
- **Apparatuses**: ~25 units (from latest 1-23-2026 report)
  - Engines: ~7
  - Rescues: ~10
  - Ladders: ~4
  - Air Trucks: ~2

### Capital Projects Import
- **Capital Projects**: 7 projects
  - Total Budget: ~$1.7M across all projects

## Data Sources
1. **Supply Inventory**: `C:\Users\Peter Darley\Downloads\MBFD_supply_inventory - Sheet1.csv` (195 items, 50 imported as sample)
2. **Apparatus Status**: `C:\Users\Peter Darley\Downloads\Apparatus Status report 1-23-2026.xlsx` (Latest report from 1/23/2026)
3. **Capital Projects**: `C:\Users\Peter Darley\Downloads\capital_improvement_projects.csv` (7 projects)

## Key Features

### Upsert Logic
- All imports use **`updateOrCreate()`** to avoid duplicates
- Existing records are updated, new ones are created
- Safe to run multiple times

### Data Quality
- Real data from actual MBFD files
- Latest apparatus status as of 1/23/2026
- Proper categorization and normalization
- Station IDs parsed from location text

## Troubleshooting

### If SSH Key Issues
Add `-o "StrictHostKeyChecking=no"` to SSH/SCP commands

### If Command Not Found
Run `docker compose exec -T laravel.test php artisan list | grep mbfd` to verify command is registered

### If Import Fails
Check Laravel logs:
```bash
docker compose exec -T laravel.test tail -f storage/logs/laravel.log
```

### To Re-run Imports
Simply run the commands again - the upsert logic will handle duplicates safely
