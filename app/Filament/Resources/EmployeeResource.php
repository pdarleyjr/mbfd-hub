<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Support\EmployeeAccessSchema;
use App\Models\Employee;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Employees & Access';

    protected static ?string $pluralModelLabel = 'Employees & Access';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Employee profile')->persistTabInQueryString()->tabs([
                    Forms\Components\Tabs\Tab::make('Profile')
                        ->schema([
                            Forms\Components\TextInput::make('employee_id')
                                ->label('Employee ID')
                                ->required()
                                ->disabledOn('edit')
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->helperText('Identity corrections use the separately authorized Correct Employee ID action.')
                                ->unique(ignoreRecord: true)
                                ->maxLength(20),
                            Forms\Components\TextInput::make('name')
                                ->disabled(fn (?Employee $record): bool => ! static::canManagePersonnel($record))
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('rank')
                                ->disabled(fn (?Employee $record): bool => ! static::canManagePersonnel($record))
                                ->maxLength(255),
                            Forms\Components\TextInput::make('station')->maxLength(255)
                                ->disabled(fn (?Employee $record): bool => ! static::canManagePersonnel($record)),
                            Forms\Components\TextInput::make('display_name')->maxLength(255)
                                ->disabled(fn (?Employee $record): bool => ! static::canUpdateProfile($record)),
                            Forms\Components\TextInput::make('phone')->tel()->maxLength(255)
                                ->disabled(fn (?Employee $record): bool => ! static::canUpdateProfile($record)),
                            Forms\Components\TextInput::make('city_email')->label('City email on record')->email()
                                ->rules(['regex:/@miamibeachfl\\.gov$/i'])->maxLength(255)
                                ->disabledOn('edit')
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->helperText('Changing this address does not verify mailbox ownership and clears prior verification.'),
                            Forms\Components\Placeholder::make('employment_status')->label('Employment status')
                                ->content(fn (?Employee $record): string => $record->roster_status ?? 'Not recorded')
                                ->helperText('Use Change employment status to archive an employee and disable linked access without deleting history.'),
                        ])
                        ->columns(2),
                    ...EmployeeAccessSchema::tabs(),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->url(fn (Employee $record): string => static::getUrl('edit', ['record' => $record]))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rank')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('station')->searchable(),
                Tables\Columns\TextColumn::make('roster_status')->label('Employment')->badge()->placeholder('Not recorded'),
                Tables\Columns\TextColumn::make('user.account_status')->label('Login')->badge()->placeholder('Awaiting account')
                    ->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', $state instanceof \BackedEnum ? $state->value : (string) $state))),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('rank')
                    ->options(fn () => Employee::query()
                        ->whereNotNull('rank')
                        ->distinct()
                        ->pluck('rank', 'rank')
                        ->sort()
                        ->toArray()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->searchPlaceholder('Search by name, ID, or rank...');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isAuthenticationAllowed()
            && ($actor->can('admin.personnel.view') || $actor->can('admin.members.view'));
    }

    public static function canManagePersonnel(?Employee $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isAuthenticationAllowed()
            && ($record === null || (int) $actor->employee_profile_id !== (int) $record->id)
            && ($actor->can('admin.personnel.manage') || $actor->can('admin.members.manage'));
    }

    public static function canCreate(): bool
    {
        return static::canManagePersonnel(null);
    }

    public static function canEdit($record): bool
    {
        return static::canUpdateProfile($record) || ($record instanceof Employee && static::canViewAny());
    }

    public static function canUpdateProfile(?Employee $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isAuthenticationAllowed()
            && ($record === null ? static::canCreate() : ((int) $actor->employee_profile_id === (int) $record->id || static::canManagePersonnel($record)));
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('user');
    }
}
