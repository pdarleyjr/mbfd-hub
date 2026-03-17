#!/bin/bash
cd ~/src/mbfd-hub
git commit -m "feat: Employee Portal — Filament panel, assigned equipment, gear requests, force-password-change

- Migration: add employee_id (unique, nullable) to users table
- Migration: create assigned_equipment table
- Migration: create employee_equipment_requests table  
- Models: AssignedEquipment, EmployeeEquipmentRequest with relationships
- User model: employee_id fillable, employee panel canAccessPanel, relationships
- Command: artisan mbfd:import-personnel {file} CSV import with dry-run
- EmployeePanelProvider: Filament panel at /employee with unified MBFD theme
- ForcePasswordChangeMiddleware: redirects on must_change_password=true
- ChangePasswordPage, MyEquipmentPage, RequestEquipmentPage
- EmployeeEquipmentRequestResource: admin view with Approve/Decline/Ordered actions
- Employee Portal card added to landing page (emerald accent, Impeccable design)
- Registered EmployeePanelProvider in bootstrap/providers.php"
echo "Commit done: $?"
