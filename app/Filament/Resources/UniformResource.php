<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\UniformResource\Pages;
use App\Models\Employee;
use App\Models\Uniform;
use App\Services\UniformInventoryService;
use App\Support\HubTimezone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Firefighting PPE / Uniforms stock. Nothing on the form is mandatory: every
 * text field suggests common values and existing entries but accepts free text.
 */
class UniformResource extends Resource
{
    use HasExportActions;

    protected static ?int $navigationSort = 40;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $model = Uniform::class;

    protected static ?string $modelLabel = 'PPE / uniform item';

    protected static ?string $pluralModelLabel = 'Firefighting PPE / Uniforms';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Logistics';

    public static function canManageInventory(): bool
    {
        return auth()->user()?->can('admin.personnel.manage') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Item')
                    ->description('Nothing here is required. Pick a suggestion or type your own.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        self::suggestedText('category', 'Category', 'categories', 100)
                            ->placeholder('e.g. Firefighting PPE'),
                        self::suggestedText('item_type', 'Type', 'item_types', 150)
                            ->placeholder('e.g. Structural Gloves'),
                        self::suggestedText('manufacturer', 'Make / manufacturer', 'manufacturers', 150)
                            ->placeholder('e.g. Globe'),
                        self::suggestedText('model', 'Model', null, 150)
                            ->placeholder('e.g. G-Xtreme 3.0'),
                        self::suggestedText('size', 'Size', 'sizes', 100)
                            ->placeholder('e.g. L, 10.5W, 34x32'),
                        self::suggestedText('color', 'Color', 'colors', 100),
                        Forms\Components\TextInput::make('item_name')
                            ->label('Item name (optional)')
                            ->helperText('Left blank, the name is built from the make, model and type.')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Stock')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Forms\Components\TextInput::make('quantity_on_hand')
                            ->label('Quantity on hand')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(1)
                            ->dehydrateStateUsing(fn ($state): int => max(0, (int) ($state ?? 0))),
                        Forms\Components\TextInput::make('reorder_level')
                            ->label('Low-stock alert at')
                            ->helperText('0 turns the alert off.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state): int => max(0, (int) ($state ?? 0))),
                        self::suggestedText('storage_location', 'Storage location', 'storage_locations', 150),
                    ]),
                Forms\Components\Section::make('Details')
                    ->collapsible()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        self::suggestedText('serial_number', 'Serial number', null, 150),
                        Forms\Components\DatePicker::make('manufacture_date')
                            ->label('Manufacture date')
                            ->helperText('Turnout gear is retired 10 years after manufacture (NFPA 1851).'),
                        self::suggestedText('condition', 'Condition', 'conditions', 100),
                        self::suggestedText('supplier', 'Supplier / vendor', null, 255),
                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Unit cost')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * A text field with a dropdown of suggestions: the configured list plus
     * values already used in the inventory. Typing any other value is allowed.
     */
    private static function suggestedText(string $column, string $label, ?string $configKey, int $maxLength): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($column)
            ->label($label)
            ->maxLength($maxLength)
            ->autocomplete('off')
            ->datalist(fn (): array => self::suggestions($column, $configKey));
    }

    /** @return list<string> */
    public static function suggestions(string $column, ?string $configKey): array
    {
        $configured = $configKey === null ? [] : (array) config('ppe_inventory.'.$configKey, []);
        $used = Uniform::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->limit(200)->pluck($column)->map(fn ($value): string => (string) $value)->all();

        return array_values(array_unique([...$configured, ...$used]));
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions($table
            ->defaultSort('item_name')
            ->columns([
                Tables\Columns\TextColumn::make('item_name')
                    ->label('Item')
                    ->description(fn (Uniform $record): ?string => collect([$record->manufacturer, $record->model])->filter()->implode(' ') ?: null)
                    ->searchable(['item_name', 'manufacturer', 'model', 'item_type', 'serial_number'])
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('item_type')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('size')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity_on_hand')
                    ->label('On hand')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (Uniform $record): string => $record->quantity_on_hand === 0
                        ? 'danger'
                        : ($record->reorder_level > 0 && $record->quantity_on_hand <= $record->reorder_level ? 'warning' : 'success')),
                Tables\Columns\TextColumn::make('storage_location')
                    ->label('Location')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('condition')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Serial')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('manufacture_date')
                    ->label('Manufactured')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('unit_cost')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn (): array => self::filterOptions('category')),
                Tables\Filters\SelectFilter::make('item_type')
                    ->label('Type')
                    ->options(fn (): array => self::filterOptions('item_type')),
                Tables\Filters\TernaryFilter::make('in_stock')
                    ->label('Stock')
                    ->placeholder('All items')
                    ->trueLabel('In stock')
                    ->falseLabel('Out of stock')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('quantity_on_hand', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('quantity_on_hand', '<=', 0),
                    ),
            ])
            ->actions([
                self::issueAction(),
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('No PPE or uniforms in stock yet')
            ->emptyStateDescription('Add items you have on hand so they can be assigned to members.'));
    }

    /** @return array<string, string> */
    private static function filterOptions(string $column): array
    {
        return Uniform::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column, $column)->all();
    }

    /** Assign stock to a member straight from the inventory list; on-hand quantity drops atomically. */
    public static function issueAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('issue')
            ->label('Assign to member')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (Uniform $record): bool => self::canManageInventory() && $record->quantity_on_hand > 0)
            ->modalHeading(fn (Uniform $record): string => 'Assign '.$record->issueDescription())
            ->form(fn (Uniform $record): array => [
                Forms\Components\Select::make('employee_portal_id')
                    ->label('Member')
                    ->options(fn (): array => Employee::query()->orderBy('name')->get()
                        ->mapWithKeys(fn (Employee $employee): array => [
                            $employee->id => trim("{$employee->name} — {$employee->rank} (ID: {$employee->employee_id})"),
                        ])->all())
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue($record->quantity_on_hand)
                    ->default(1)
                    ->required()
                    ->helperText("{$record->quantity_on_hand} on hand"),
                Forms\Components\DatePicker::make('issued_at')
                    ->label('Date issued')
                    ->default(HubTimezone::today())
                    ->required(),
                Forms\Components\DatePicker::make('expires_at')
                    ->label('Expiration date (optional)')
                    ->afterOrEqual('issued_at'),
                Forms\Components\Textarea::make('notes')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(function (Uniform $record, array $data): void {
                abort_unless(self::canManageInventory(), 403);
                $employee = Employee::query()->findOrFail($data['employee_portal_id']);
                $assignment = app(UniformInventoryService::class)->issue(
                    $record,
                    $employee,
                    (int) $data['quantity'],
                    (string) $data['issued_at'],
                    $data['notes'] ?? null,
                    null,
                    $data['expires_at'] ?? null,
                );
                Notification::make()
                    ->title('New Equipment Assigned')
                    ->body("You have been assigned: {$assignment->quantity}x {$assignment->item_description}")
                    ->icon('heroicon-o-shield-check')
                    ->iconColor('success')
                    ->sendToDatabase($employee);
                Notification::make()
                    ->success()
                    ->title('Assigned and inventory updated')
                    ->body("{$assignment->quantity}x {$assignment->item_description} to {$employee->name}.")
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUniforms::route('/'),
            'create' => Pages\CreateUniform::route('/create'),
            'edit' => Pages\EditUniform::route('/{record}/edit'),
        ];
    }
}
