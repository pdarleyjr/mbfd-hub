# Deploy MBFD Real Data Import Script to VPS
# PowerShell script for Windows

$ErrorActionPreference = "Stop"

$SSH_KEY = "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker"
$VPS_HOST = "root@145.223.73.170"
$VPS_PATH = "/root/mbfd-hub"

Write-Host "======================================" -ForegroundColor Cyan
Write-Host "MBFD Data Import Deployment" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Copy the import command to VPS
Write-Host "Step 1: Copying import command to VPS..." -ForegroundColor Yellow
scp -i $SSH_KEY -o "StrictHostKeyChecking=no" `
    "laravel-app/app/Console/Commands/ImportMBFDData.php" `
    "${VPS_HOST}:${VPS_PATH}/app/Console/Commands/ImportMBFDData.php"
Write-Host "✓ Import command copied" -ForegroundColor Green
Write-Host ""

# Step 2: SSH into VPS and run imports
Write-Host "Step 2: Running data imports on VPS..." -ForegroundColor Yellow

$sshScript = @'
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
'@

ssh -i $SSH_KEY -o "StrictHostKeyChecking=no" $VPS_HOST $sshScript

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host "✓ Data import completed successfully!" -ForegroundColor Green
Write-Host "======================================" -ForegroundColor Cyan
