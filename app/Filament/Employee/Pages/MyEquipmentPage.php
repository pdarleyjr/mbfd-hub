<?php

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Support\HubTimezone;
use Filament\Pages\Page;

class MyEquipmentPage extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'My work';

    protected static string $view = 'filament.employee.pages.my-equipment';

    protected static ?string $title = 'My Assigned Equipment';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'My Equipment';

    public function getViewData(): array
    {
        $employee = $this->authenticatedEmployee();

        $equipment = AssignedEquipment::query()
            ->where('employee_portal_id', $employee->id)
            ->orderBy('category')
            ->orderBy('issued_at', 'desc')
            ->get();

        $activeEquipment = $equipment->where('status', 'active');
        $history = $equipment->where('status', '!=', 'active');
        $byCategory = $activeEquipment->groupBy('category');
        $expired = $activeEquipment->filter(fn (AssignedEquipment $item) => $item->expires_at?->isBefore(HubTimezone::today()));
        $expiringSoon = $activeEquipment->filter(fn (AssignedEquipment $item) => $item->expires_at?->between(HubTimezone::today(), HubTimezone::today()->addDays(60)));

        // Use $user in blade for compatibility — it's an Employee instance
        $user = $employee;

        return compact('equipment', 'activeEquipment', 'history', 'byCategory', 'expired', 'expiringSoon', 'user');
    }
}
