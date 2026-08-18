<?php

namespace App\Filament\Employee\Pages;

use App\Enums\PersonnelRequestStatus;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use Filament\Pages\Page;

class EmployeeDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static string $view = 'filament.employee.pages.dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Home';

    protected static ?string $slug = 'dashboard';

    public function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();

        $equipmentCount = AssignedEquipment::where('employee_portal_id', $employee->id)->where('status', 'active')->count();
        $pendingRequests = $employee->personnelRequests()
            ->whereNotIn('status', [PersonnelRequestStatus::Completed, PersonnelRequestStatus::Denied, PersonnelRequestStatus::Cancelled])
            ->count();
        $recentEquipment = AssignedEquipment::where('employee_portal_id', $employee->id)
            ->latest('issued_at')
            ->take(3)
            ->get();
        $recentRequests = $employee->personnelRequests()->with('items')
            ->latest()
            ->take(3)
            ->get();

        // For blade compatibility — use $user variable name but it's an Employee
        $user = $employee;

        return compact('user', 'equipmentCount', 'pendingRequests', 'recentEquipment', 'recentRequests');
    }
}
