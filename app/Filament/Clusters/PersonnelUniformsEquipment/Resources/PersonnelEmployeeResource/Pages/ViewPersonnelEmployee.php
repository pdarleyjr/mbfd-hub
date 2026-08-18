<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\Uniform;
use App\Services\UniformInventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewPersonnelEmployee extends ViewRecord
{
    protected static string $resource = PersonnelEmployeeResource::class;

    protected static string $view = 'filament.clusters.personnel-uniforms-equipment.resources.employee-record';

    protected function getHeaderActions(): array
    {
        /** @var Employee $employee */
        $employee = $this->record;

        return [
            Action::make('issue_uniform')->label('Issue Uniform')->icon('heroicon-o-shopping-bag')->color('primary')->form([
                Select::make('uniform_id')->label('Uniform inventory')->options(fn () => Uniform::query()->where('quantity_on_hand', '>', 0)->orderBy('item_name')->get()->mapWithKeys(fn (Uniform $uniform) => [$uniform->id => "{$uniform->item_name} — {$uniform->size} — {$uniform->quantity_on_hand} on hand"]))->searchable()->required(),
                TextInput::make('quantity')->numeric()->minValue(1)->maxValue(20)->default(1)->required(),
                DatePicker::make('issued_at')->default(today())->required(),
                DatePicker::make('expires_at')->afterOrEqual('issued_at'),
                Textarea::make('notes')->maxLength(2000),
            ])->action(function (array $data) use ($employee): void {
                app(UniformInventoryService::class)->issue(Uniform::findOrFail($data['uniform_id']), $employee, (int) $data['quantity'], $data['issued_at'], $data['notes'] ?? null, null, $data['expires_at'] ?? null);
                Notification::make()->title('Uniform assigned and stock decremented')->success()->send();
            }),
            Action::make('assign_ppe')->label('Assign PPE / Equipment')->icon('heroicon-o-shield-check')->color('success')->form([
                Select::make('category')->options(collect(AssignedEquipment::categories())->mapWithKeys(fn ($category) => [$category => $category]))->required(),
                TextInput::make('item_description')->label('Item description')->required()->maxLength(255),
                TextInput::make('quantity')->numeric()->minValue(1)->maxValue(20)->default(1)->required(),
                DatePicker::make('issued_at')->default(today())->required(),
                DatePicker::make('expires_at')->afterOrEqual('issued_at'),
                Textarea::make('notes')->maxLength(2000),
            ])->action(function (array $data) use ($employee): void {
                DB::transaction(fn () => $employee->assignedEquipment()->create([
                    ...$data,
                    'user_id' => null,
                    'status' => 'active',
                ]));
                Notification::make()->title('Equipment assigned')->success()->send();
            }),
        ];
    }

    protected function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = $this->record;
        $assignments = $employee->assignedEquipment()->latest('issued_at')->get();

        return [
            'employee' => $employee,
            'activeUniforms' => $assignments->where('status', 'active')->filter(fn ($item) => $item->uniform_id || str_contains(strtolower($item->category), 'uniform') || str_contains(strtolower($item->category), 'shirt')),
            'activeEquipment' => $assignments->where('status', 'active')->reject(fn ($item) => $item->uniform_id || str_contains(strtolower($item->category), 'uniform') || str_contains(strtolower($item->category), 'shirt')),
            'expiringSoon' => $assignments->where('status', 'active')->filter(fn ($item) => $item->expires_at?->between(today(), today()->addDays(60))),
            'expired' => $assignments->where('status', 'active')->filter(fn ($item) => $item->expires_at?->isBefore(today())),
            'history' => $assignments->where('status', '!=', 'active'),
            'requests' => $employee->personnelRequests()->with('items')->latest()->get(),
            'legacyRequests' => $employee->equipmentRequests()->latest()->get(),
        ];
    }
}
