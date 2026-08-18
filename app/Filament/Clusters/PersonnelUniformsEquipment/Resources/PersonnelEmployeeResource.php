<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources;

use App\Filament\Clusters\PersonnelUniformsEquipment;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource\Pages;
use App\Models\Employee;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PersonnelEmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $cluster = PersonnelUniformsEquipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Employee Records';

    protected static ?string $modelLabel = 'employee equipment record';

    protected static ?string $slug = 'employee-records';

    protected static ?int $navigationSort = -80;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee_id')->label('Employee ID')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('rank')->searchable()->sortable(),
            TextColumn::make('active_assignments_count')->counts(['assignedEquipment' => fn ($query) => $query->where('status', 'active')])->label('Active Items')->alignCenter(),
            TextColumn::make('open_requests_count')->counts(['personnelRequests' => fn ($query) => $query->whereNotIn('status', ['completed', 'denied', 'cancelled'])])->label('Open Requests')->alignCenter(),
        ])->actions([ViewAction::make()->label('Open Equipment Record')])->bulkActions([])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPersonnelEmployees::route('/'),
            'view' => Pages\ViewPersonnelEmployee::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false;
    }
}
