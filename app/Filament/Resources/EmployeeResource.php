<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\Employee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

use App\Filament\Concerns\EnterpriseTable;
class EmployeeResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Employee Portal Accounts';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Employee Information')
                    ->schema([
                        Forms\Components\TextInput::make('employee_id')
                            ->label('Employee ID')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rank')
                            ->maxLength(255),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Password')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn ($context) => $context === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->helperText(fn ($context) => $context === 'edit'
                                ? 'Leave blank to keep current password.'
                                : null)
                            ->maxLength(255),
                        Forms\Components\Toggle::make('must_change_password')
                            ->label('Force password change on next login')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rank')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('must_change_password')
                    ->label('Needs PW Change')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('must_change_password')
                    ->label('Password Status')
                    ->trueLabel('Needs change')
                    ->falseLabel('Password set')
                    ->placeholder('All'),
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
                Tables\Actions\Action::make('resetPassword')
                    ->label('Reset Password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Employee $record) => "Reset Password for {$record->name}")
                    ->modalDescription('This will reset the password and force the member to set a new one on next login.')
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('New Password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(6)
                            ->default(config('employee.default_temp_password', '')),
                    ])
                    ->action(function (Employee $record, array $data): void {
                        $record->update([
                            'password' => Hash::make($data['new_password']),
                            'must_change_password' => true,
                        ]);
                        Notification::make()
                            ->title("Password reset for {$record->name} (ID: {$record->employee_id})")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('resetPasswords')
                    ->label('Reset Passwords')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reset Passwords for Selected Employees')
                    ->modalDescription('All selected employees will have their password reset to the configured default and will be forced to change it on next login.')
                    ->action(function ($records): void {
                        $defaultPassword = config('employee.default_temp_password', '');
                        if ($defaultPassword === '') {
                            Notification::make()
                                ->title('EMPLOYEE_DEFAULT_TEMP_PASSWORD is not configured')
                                ->danger()
                                ->send();
                            return;
                        }
                        $hashed = Hash::make($defaultPassword);
                        $count = 0;
                        foreach ($records as $record) {
                            $record->update([
                                'password' => $hashed,
                                'must_change_password' => true,
                            ]);
                            $count++;
                        }
                        Notification::make()
                            ->title("Password reset for {$count} employees")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
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
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
