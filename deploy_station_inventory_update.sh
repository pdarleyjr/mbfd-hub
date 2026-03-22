#!/usr/bin/env pwsh
# Deploy Station Inventory fixes to VPS

Write-Host "Deploying Station Inventory updates..." -ForegroundColor Cyan
Write-Host ""

$SSH_KEY = "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker"
$VPS_HOST = "root@145.223.73.170"
$VPS_PATH = "/root/mbfd-hub"

# Copy PHP files
Write-Host "📤 Copying PHP configuration file..." -ForegroundColor Yellow
scp -i $SSH_KEY app/Config/StationSupplyList.php "${VPS_HOST}:${VPS_PATH}/app/Config/"

Write-Host "📤 Copying PHP model file..." -ForegroundColor Yellow
scp -i $SSH_KEY app/Models/StationInventorySubmission.php "${VPS_HOST}:${VPS_PATH}/app/Models/"

Write-Host "📤 Copying Filament resource file..." -ForegroundColor Yellow
scp -i $SSH_KEY app/Filament/Resources/StationInventorySubmissionResource.php "${VPS_HOST}:${VPS_PATH}/app/Filament/Resources/"

# Copy built React assets
Write-Host "📤 Copying built React assets..." -ForegroundColor Yellow
scp -i $SSH_KEY -r public/daily/assets "${VPS_HOST}:${VPS_PATH}/public/daily/"

Write-Host ""
Write-Host "✅ Deployment complete!" -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "1. SSH into VPS and verify files"
Write-Host "2. Clear Filament cache if needed"
Write-Host "3. Test the forms work correctly"
