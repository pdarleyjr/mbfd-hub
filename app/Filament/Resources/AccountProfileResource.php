<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AccountProfileResource\Pages;
use App\Filament\Support\EmployeeAccessSchema;
use App\Models\User;
use Filament\Forms\Components as Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccountProfileResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'employees/accounts';

    protected static ?string $pluralModelLabel = 'Account exceptions';

    protected static ?string $modelLabel = 'Account profile';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Tabs::make('Account profile')->persistTabInQueryString()->tabs([
                Forms\Tabs\Tab::make('Profile')->schema([
                    Forms\Placeholder::make('classification')->label('Identity classification')
                        ->content(fn (?User $record): string => $record?->getRawOriginal('account_classification') === 'approved_nonemployee' ? 'Approved nonemployee — separately managed account; no employee record is created.' : 'Unresolved account — an administrator must verify an exact employee link or approve nonemployee status.'),
                    Forms\TextInput::make('name')->required()->maxLength(255)
                        ->disabled(fn (?User $record): bool => auth()->id() === $record?->id || ! static::canUpdateProfile($record)),
                    Forms\TextInput::make('display_name')->maxLength(255)->disabled(fn (?User $record): bool => ! static::canUpdateProfile($record)),
                    Forms\TextInput::make('phone')->tel()->maxLength(255)->disabled(fn (?User $record): bool => ! static::canUpdateProfile($record)),
                    Forms\Placeholder::make('employee_id')->label('Recorded Employee ID')->content(fn (?User $record): string => $record->employee_id ?? 'None — do not invent an Employee ID.'),
                    Forms\Placeholder::make('email')->label('Connected email')->content(fn (?User $record): string => $record->email ?? 'None'),
                ]),
                ...EmployeeAccessSchema::tabs(),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('employee_id')->label('Recorded Employee ID')->searchable(),
            Tables\Columns\TextColumn::make('email')->searchable(),
            Tables\Columns\TextColumn::make('account_classification')->label('Classification')->badge()
                ->formatStateUsing(fn (?string $state): string => $state === 'approved_nonemployee' ? 'Approved nonemployee' : 'Unresolved account'),
            Tables\Columns\TextColumn::make('account_status')->badge(),
        ])->actions([Tables\Actions\EditAction::make()->label('Open profile')])->bulkActions([]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->whereNull('employee_profile_id');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAccountProfiles::route('/'), 'edit' => Pages\EditAccountProfile::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isAuthenticationAllowed() && $actor->can('admin.members.view');
    }

    public static function canEdit($record): bool
    {
        return static::canUpdateProfile($record) || ($record instanceof User && $record->employee_profile_id === null && static::canViewAny()
            && (! $record->hasRole('super_admin') || auth()->user()?->hasRole('super_admin')));
    }

    public static function canUpdateProfile(?User $record): bool
    {
        $actor = auth()->user();

        return $record instanceof User && $record->employee_profile_id === null && $actor instanceof User && $actor->isAuthenticationAllowed()
            && (! $record->hasRole('super_admin') || $actor->hasRole('super_admin'))
            && ($actor->is($record) || $actor->can('admin.members.manage'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
