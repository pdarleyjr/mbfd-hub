#!/bin/bash

# Deploy MBFD Real Data Import Script to VPS
#
# This script copies the import command to the VPS and executes it

set -e

SSH_KEY="C:\\Users\\Peter Darley\\.ssh\\id_ed25519_hpb_docker"
VPS_HOST="root@145.223.73.170"
VPS_PATH="/root/mbfd-hub"

echo "======================================"
echo "MBFD Data Import Deployment"
echo "======================================"
echo ""

# Step 1: Copy the import command to VPS
echo "Step 1: Copying import command to VPS..."
scp -i "$SSH_KEY" \
    laravel-app/app/Console/Commands/ImportMBFDData.php \
    "${VPS_HOST}:${VPS_PATH}/app/Console/Commands/Import MBFDData.php"
echo "✓ Import command copied"
echo ""

# Step 2: SSH into VPS and run imports
echo "Step 2: Running data imports on VPS..."
ssh -i "$SSH_KEY" "$VPS_HOST" << 'ENDSSH'
cd /root/mbfd-hub

echo "Checking current record counts..."
docker compose exec -T laravel.test php artisan tinker --execute="
echo 'Current Counts:';
echo '  Apparatuses: ' . \App\Models\Apparatus::count();
echo '  Equipment Items: ' . \App\Models\EquipmentItem::count();
echo '  Inventory Locations: ' . \App\Models\InventoryLocation::count();
echo '  Capital Projects: ' . \App\Models\CapitalProject::count();
echo '  Stations: ' . \App\Models\Station::count();
echo '';
"

echo ""
echo "Running inventory import..."
docker compose exec -T laravel.test php artisan mbfd:import inventory

echo ""
echo "Running apparatus import..."
docker compose exec -T laravel.test php artisan mbfd:import apparatus

echo ""
echo "Running capital projects import..."
docker compose exec -T laravel.test php artisan mbfd:import projects

echo ""
echo "======================================"
echo "Verification - Final Counts:"
echo "======================================"
docker compose exec -T laravel.test php artisan tinker --execute="
echo 'Final Counts:';
echo '  Apparatuses: ' . \App\Models\Apparatus::count();
echo '  Equipment Items: ' . \App\Models\EquipmentItem::count();
echo '  Inventory Locations: ' . \App\Models\InventoryLocation::count();
echo '  Capital Projects: ' . \App\Models\CapitalProject::count();
echo '  Stations: ' . \App\Models\Station::count();
"

ENDSSH

echo ""
echo "======================================"
echo "✓ Data import completed successfully!"
echo "======================================"
