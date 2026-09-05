<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources;

use App\Filament\Clusters\PersonnelUniformsEquipment;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource\Pages;
use App\Models\AssignedEquipment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PersonnelAssignmentResource extends Resource
{
    protected static ?string $model = AssignedEquipment::class;

    protected static ?string $cluster = PersonnelUniformsEquipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Assignments & Expiration';

    protected static ?string $slug = 'assignments';

    protected static ?int $navigationSort = -70;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('item_description')->required()->maxLength(255),
            Select::make('category')->options(collect(AssignedEquipment::categories())->mapWithKeys(fn ($category) => [$category => $category]))->required(),
            TextInput::make('quantity')->numeric()->minValue(1)->maxValue(100)->required(),
            DatePicker::make('issued_at'),
            DatePicker::make('expires_at')->label('Expiration date')->helperText('Use MBFD’s actual date. No default lifespan is invented.'),
            Select::make('status')->options(['active' => 'Active', 'returned' => 'Returned', 'retired' => 'Retired'])->disabled()->dehydrated(),
            Textarea::make('notes')->columnSpanFull()->maxLength(5000),
            DatePicker::make('returned_at')->disabled()->dehydrated(),
            Textarea::make('retirement_reason')->disabled()->dehydrated()->columnSpanFull(),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee.name')->label('Employee')->description(fn (AssignedEquipment $record) => "{$record->employee?->rank} · {$record->employee?->employee_id}")->searchable()->sortable(),
            TextColumn::make('item_description')->label('Item')->searchable()->sortable(),
            TextColumn::make('category')->badge()->searchable(),
            TextColumn::make('status')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            TextColumn::make('issued_at')->date('M j, Y')->sortable(),
            TextColumn::make('expires_at')->date('M j, Y')->sortable()->color(fn (AssignedEquipment $record) => $record->expires_at?->isBefore(today()) ? 'danger' : ($record->expires_at?->lte(today()->addDays(60)) ? 'warning' : null))->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(['active' => 'Active', 'returned' => 'Returned', 'retired' => 'Retired']),
            SelectFilter::make('expiration')->options(['soon' => 'Expiring in 60 days', 'expired' => 'Expired', 'none' => 'No expiration date'])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'soon' => $query->where('status', 'active')->whereBetween('expires_at', [today(), today()->addDays(60)]),
                'expired' => $query->where('status', 'active')->whereDate('expires_at', '<', today()),
                'none' => $query->whereNull('expires_at'),
                default => $query,
            }),
            SelectFilter::make('category')->options(fn () => AssignedEquipment::query()->distinct()->orderBy('category')->pluck('category', 'category')->all()),
        ])->actions([EditAction::make()])->bulkActions([])->defaultSort('expires_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPersonnelAssignments::route('/'),
            'edit' => Pages\EditPersonnelAssignment::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.equipment.view') ?? false;
    }
}
