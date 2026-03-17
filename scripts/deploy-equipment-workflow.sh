#!/bin/bash
WIN="/mnt/c/Users/Peter Darley/Desktop/Support Services"
DEST="$HOME/src/mbfd-hub"

# Migration
cp "$WIN/database/migrations/2026_03_16_160000_add_reason_and_update_statuses_to_equipment_requests.php" "$DEST/database/migrations/"

# Models
cp "$WIN/app/Models/EmployeeEquipmentRequest.php" "$DEST/app/Models/"

# Admin resource
cp "$WIN/app/Filament/Resources/EmployeeEquipmentRequestResource.php" "$DEST/app/Filament/Resources/"
cp "$WIN/app/Filament/Resources/UniformResource/Pages/ListUniforms.php" "$DEST/app/Filament/Resources/UniformResource/Pages/"

# Admin modal view
mkdir -p "$DEST/resources/views/filament/admin/modals"
cp "$WIN/resources/views/filament/admin/modals/employee-record.blade.php" "$DEST/resources/views/filament/admin/modals/"

# Employee pages
cp "$WIN/app/Filament/Employee/Pages/RequestEquipmentPage.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/resources/views/filament/employee/pages/request-equipment.blade.php" "$DEST/resources/views/filament/employee/pages/"

cd "$DEST"
git add \
    database/migrations/2026_03_16_160000_add_reason_and_update_statuses_to_equipment_requests.php \
    app/Models/EmployeeEquipmentRequest.php \
    app/Filament/Resources/EmployeeEquipmentRequestResource.php \
    app/Filament/Resources/UniformResource/Pages/ListUniforms.php \
    resources/views/filament/admin/modals/employee-record.blade.php \
    app/Filament/Employee/Pages/RequestEquipmentPage.php \
    resources/views/filament/employee/pages/request-equipment.blade.php

git commit -m "feat: Equipment request workflow — Pending→Ordered→Ready for Pickup→Completed, Decline with reason, View Employee Record modal, archived request history"
git push origin main
echo "Done: $?"
