<?php

namespace App\Filament\Employee\Pages;

use App\Models\AssignedEquipment;
use App\Models\Employee;
use Filament\Pages\Page;

class MyEquipmentPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static string $view = 'filament.employee.pages.my-equipment';
    protected static ?string $title = 'My Assigned Equipment';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'My Equipment';

    public function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();

        $equipment = AssignedEquipment::query()
            ->where('employee_portal_id', $employee->id)
            ->orderBy('category')
            ->orderBy('issued_at', 'desc')
            ->get();

        $byCategory = $equipment->groupBy('category');

        // Use $user in blade for compatibility — it's an Employee instance
        $user = $employee;

        return compact('equipment', 'byCategory', 'user');
    }
}
