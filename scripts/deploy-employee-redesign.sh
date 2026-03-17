#!/bin/bash
WIN="/mnt/c/Users/Peter Darley/Desktop/Support Services"
DEST="$HOME/src/mbfd-hub"

cp "$WIN/app/Providers/Filament/EmployeePanelProvider.php" "$DEST/app/Providers/Filament/"
cp "$WIN/app/Filament/Employee/Pages/EmployeeDashboard.php" "$DEST/app/Filament/Employee/Pages/"
cp "$WIN/resources/views/filament/employee/pages/dashboard.blade.php" "$DEST/resources/views/filament/employee/pages/"
cp "$WIN/resources/views/filament/employee/pages/my-equipment.blade.php" "$DEST/resources/views/filament/employee/pages/"
cp "$WIN/resources/views/filament/employee/pages/request-equipment.blade.php" "$DEST/resources/views/filament/employee/pages/"

cd "$DEST"
git add app/Providers/Filament/EmployeePanelProvider.php \
    app/Filament/Employee/Pages/EmployeeDashboard.php \
    resources/views/filament/employee/pages/

git commit -m "feat: Employee Portal Impeccable redesign - dashboard with hero strip, stats bar, quick actions, proper equipment table, request form"
git push origin main
echo "All done: $?"
