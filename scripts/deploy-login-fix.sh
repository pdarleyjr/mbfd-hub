#!/bin/bash
WIN="/mnt/c/Users/Peter Darley/Desktop/Support Services"
DEST="$HOME/src/mbfd-hub"

cp "$WIN/app/Filament/Employee/Pages/Auth/EmployeeLogin.php" "$DEST/app/Filament/Employee/Pages/Auth/"
cp "$WIN/app/Providers/Filament/EmployeePanelProvider.php" "$DEST/app/Providers/Filament/"
mkdir -p "$DEST/app/Http/Responses"
cp "$WIN/app/Http/Responses/EmployeeLoginResponse.php" "$DEST/app/Http/Responses/"

cd "$DEST"
git add app/Filament/Employee/Pages/Auth/EmployeeLogin.php \
    app/Providers/Filament/EmployeePanelProvider.php \
    app/Http/Responses/EmployeeLoginResponse.php
git commit -m "fix: EmployeeLogin redirect via this->redirect() + EmployeeLoginResponse"
git push origin main
echo "Done: $?"
