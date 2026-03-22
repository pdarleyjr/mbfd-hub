#!/bin/bash
# Sync Employee Portal files from Windows workspace to WSL mbfd-hub repo
DEST="$HOME/src/mbfd-hub"
WIN="/mnt/c/Users/Peter Darley/Desktop/Support Services"

set -e

cp "$WIN/database/migrations/2026_03_16_120000_add_employee_id_to_users_table.php" "$DEST/database/migrations/"
cp "$WIN/database/migrations/2026_03_16_120001_create_assigned_equipment_table.php" "$DEST/database/migrations/"
cp "$WIN/database/migrations/2026_03_16_120002_create_employee_equipment_requests_table.php" "$DEST/database/migrations/"
cp "$WIN/app/Models/AssignedEquipment.php" "$DEST/app/Models/"
cp "$WIN/app/Models/EmployeeEquipmentRequest.php" "$DEST/app/Models/"
cp "$WIN/app/Models/User.php" "$DEST/app/Models/"
cp "$WIN/app/Console/Commands/ImportPersonnel.php" "$DEST/app/Console/Commands/"
mkdir -p "$DEST/app/Http/Middleware"
cp "$WIN/app/Http/Middleware/ForcePasswordChangeMiddleware.php" "$DEST/app/Http/Middleware/"
cp "$WIN/app/Providers/Filament/EmployeePanelProvider.php" "$DEST/app/Providers/Filament/"
cp "$WIN/bootstrap/providers.php" "$DEST/bootstrap/"
mkdir -p "$DEST/app/Filament/Employee/Pages"
cp "$WIN/app/Filament/Employee/Pages/ChangePasswordPage.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/app/Filament/Employee/Pages/MyEquipmentPage.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/app/Filament/Employee/Pages/RequestEquipmentPage.php" "$DEST/app/Filament/Employee/Pages/"
mkdir -p "$DEST/resources/views/filament/employee/pages"
cp "$WIN/resources/views/filament/employee/pages/change-password.blade.php" "$DEST/resources/views/filament/employee/pages/"
cp "$WIN/resources/views/filament/employee/pages/my-equipment.blade.php" "$DEST/resources/views/filament/employee/pages/"
cp "$WIN/resources/views/filament/employee/pages/request-equipment.blade.php" "$DEST/resources/views/filament/employee/pages/"
mkdir -p "$DEST/app/Filament/Resources/EmployeeEquipmentRequestResource/Pages"
cp "$WIN/app/Filament/Resources/EmployeeEquipmentRequestResource.php" "$DEST/app/Filament/Resources/"
cp "$WIN/app/Filament/Resources/EmployeeEquipmentRequestResource/Pages/ListEmployeeEquipmentRequests.php" "$DEST/app/Filament/Resources/EmployeeEquipmentRequestResource/Pages/"
cp "$WIN/app/Filament/Resources/EmployeeEquipmentRequestResource/Pages/ViewEmployeeEquipmentRequest.php" "$DEST/app/Filament/Resources/EmployeeEquipmentRequestResource/Pages/"
cp "$WIN/resources/views/welcome.blade.php" "$DEST/resources/views/"

echo "✅ All Employee Portal files synced to $DEST"
