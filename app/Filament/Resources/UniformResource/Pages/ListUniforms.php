<?php

namespace App\Filament\Resources\UniformResource\Pages;

use App\Filament\Resources\UniformResource;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\EmployeeEquipmentRequest;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListUniforms extends ListRecords
{
    protected static string $resource = UniformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view_employee_record')
                ->label('View Employee Record')
                ->icon('heroicon-o-identification')
                ->color('info')
                ->modalHeading('Employee Equipment Record')
                ->modalWidth('4xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->form([
                    \Filament\Forms\Components\Select::make('employee_portal_id')
                        ->label('Select Employee')
                        ->options(
                            \App\Models\Employee::orderBy('rank')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($e) => [
                                    $e->id => "{$e->name} — {$e->rank} (ID: {$e->employee_id})"
                                ])
                        )
                        ->searchable()
                        ->required()
                        ->live(),
                ])
                ->action(function (array $data, Actions\Action $action): void {
                    // No-op — record-viewing is shown via the modal content below
                })
                ->modalContent(function ($livewire) {
                    $empId = data_get($livewire->mountedActionsData[0] ?? [], 'employee_portal_id');
                    if (!$empId) {
                        return new \Illuminate\Support\HtmlString('<div class="p-4 text-sm text-neutral-500">Select an employee above to view their record.</div>');
                    }
                    $employee = \App\Models\Employee::find($empId);
                    if (!$employee) {
                        return new \Illuminate\Support\HtmlString('<div class="p-4 text-sm text-red-500">Employee not found.</div>');
                    }

                    $equipment = \App\Models\AssignedEquipment::where('employee_portal_id', $empId)
                        ->orderBy('category')->orderBy('issued_at', 'desc')->get();
                    $requests = \App\Models\EmployeeEquipmentRequest::where('employee_portal_id', $empId)
                        ->latest()->get();

                    return view('filament.admin.modals.employee-record', compact('employee', 'equipment', 'requests'));
                }),

            Actions\Action::make('assign_equipment')
                ->label('Assign Gear/Uniform')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->modalHeading('Assign Equipment to Employee')
                ->modalDescription('Select a fire department employee and record the gear or uniform being issued.')
                ->modalWidth('lg')
                ->form([
                    Select::make('employee_portal_id')
                        ->label('Employee')
                        ->options(
                            Employee::orderBy('rank')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($e) => [
                                    $e->id => "{$e->name} — {$e->rank} (ID: {$e->employee_id})"
                                ])
                        )
                        ->searchable()
                        ->required(),
                    Select::make('category')
                        ->label('Category')
                        ->options(array_combine(
                            AssignedEquipment::categories(),
                            AssignedEquipment::categories()
                        ))
                        ->required()
                        ->searchable(),
                    TextInput::make('item_description')
                        ->label('Item Description')
                        ->placeholder('e.g., Class B Polo - Size Large, or MSA G1 SCBA Mask')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('quantity')
                        ->label('Quantity')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required(),
                    DatePicker::make('issued_at')
                        ->label('Date Issued')
                        ->default(now())
                        ->required(),
                    Textarea::make('notes')
                        ->label('Notes (optional)')
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    // Assign via employee_portal_id (employees table)
                    AssignedEquipment::create([
                        'employee_portal_id' => $data['employee_portal_id'],
                        'user_id'            => null,
                        'category'           => $data['category'],
                        'item_description'   => $data['item_description'],
                        'quantity'           => $data['quantity'],
                        'issued_at'          => $data['issued_at'],
                        'notes'              => $data['notes'] ?? null,
                    ]);

                    $employee = Employee::find($data['employee_portal_id']);

                    // Send Filament database notification to the employee (employee guard)
                    Notification::make()
                        ->title('New Equipment Assigned')
                        ->body("You have been assigned: {$data['quantity']}x {$data['item_description']} ({$data['category']})")
                        ->icon('heroicon-o-shield-check')
                        ->iconColor('success')
                        ->sendToDatabase($employee);

                    Notification::make()
                        ->title('Equipment assigned successfully')
                        ->body("Assigned {$data['quantity']}x {$data['item_description']} to {$employee->name}")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
