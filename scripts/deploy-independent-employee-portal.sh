#!/bin/bash
WIN="/mnt/c/Users/Peter Darley/Desktop/Support Services"
DEST="$HOME/src/mbfd-hub"

# Migration
cp "$WIN/database/migrations/2026_03_16_150000_create_employees_table.php" "$DEST/database/migrations/"

# Models
cp "$WIN/app/Models/Employee.php" "$DEST/app/Models/"
cp "$WIN/app/Models/AssignedEquipment.php" "$DEST/app/Models/"
cp "$WIN/app/Models/EmployeeEquipmentRequest.php" "$DEST/app/Models/"

# Config
cp "$WIN/config/auth.php" "$DEST/config/"

# Filament Employee pages
cp "$WIN/app/Filament/Employee/Pages/EmployeeDashboard.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/app/Filament/Employee/Pages/MyEquipmentPage.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/app/Filament/Employee/Pages/RequestEquipmentPage.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/app/Filament/Employee/Pages/ChangePasswordPage.php" "$DEST/app/Filament/Employee/Pages/"

# Auth login page
mkdir -p "$DEST/app/Filament/Employee/Pages/Auth"
cp "$WIN/app/Filament/Employee/Pages/Auth/EmployeeLogin.php" "$DEST/app/Filament/Employee/Pages/Auth/"

# Provider + middleware
cp "$WIN/app/Providers/Filament/EmployeePanelProvider.php" "$DEST/app/Providers/Filament/"
cp "$WIN/app/Http/Middleware/ForcePasswordChangeMiddleware.php" "$DEST/app/Http/Middleware/"

# Admin resources
cp "$WIN/app/Filament/Resources/EmployeeEquipmentRequestResource.php" "$DEST/app/Filament/Resources/"
cp "$WIN/app/Filament/Resources/UniformResource/Pages/ListUniforms.php" "$DEST/app/Filament/Resources/UniformResource/Pages/"

# Console command
cp "$WIN/app/Console/Commands/ImportPersonnel.php" "$DEST/app/Console/Commands/"

cd "$DEST"
git add \
    database/migrations/2026_03_16_150000_create_employees_table.php \
    app/Models/Employee.php \
    app/Models/AssignedEquipment.php \
    app/Models/EmployeeEquipmentRequest.php \
    config/auth.php \
    app/Filament/Employee/ \
    app/Providers/Filament/EmployeePanelProvider.php \
    app/Http/Middleware/ForcePasswordChangeMiddleware.php \
    app/Filament/Resources/EmployeeEquipmentRequestResource.php \
    app/Filament/Resources/UniformResource/Pages/ListUniforms.php \
    app/Console/Commands/ImportPersonnel.php

git commit -m "feat: Employee Portal architecture overhaul — independent employees table, custom auth guard, Employee ID login, MBFD1! password, no overlap with users table"
git push origin main
echo "Done: $?"
