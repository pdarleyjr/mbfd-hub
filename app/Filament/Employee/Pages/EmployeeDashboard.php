<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\PersonnelRequestStatus;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class EmployeeDashboard extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationGroup = 'My work';

    protected static string $view = 'filament.employee.pages.dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Home';

    protected static ?string $slug = 'dashboard';

    private ?Employee $member = null;

    private function member(): Employee
    {
        return $this->member ??= $this->authenticatedEmployee();
    }

    /** The member's identity is the page title: name as the heading, rank and ID as its subheading. */
    public function getHeading(): string|Htmlable
    {
        return $this->member()->name ?: $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        $employee = $this->member();

        return collect([$employee->rank ?: null, 'ID: '.($employee->employee_id ?? 'Not assigned')])->filter()->implode(' · ');
    }

    public function getViewData(): array
    {
        $employee = $this->member();
        $bidAssignment = $employee->bidAssignments()->whereNull('superseded_at')->orderByDesc('bid_year')->first();

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

        $authUser = auth()->user();
        $canMessageLogistics = \App\Filament\Employee\Pages\MyMessages::canAccess();
        $messageUnread = $canMessageLogistics && $authUser instanceof \App\Models\User
            ? app(\App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService::class)->memberUnreadCount($authUser)
            : 0;

        // For blade compatibility — use $user variable name but it's an Employee
        $user = $employee;

        return compact('user', 'bidAssignment', 'equipmentCount', 'pendingRequests', 'recentEquipment', 'recentRequests', 'messageUnread', 'canMessageLogistics');
    }
}
