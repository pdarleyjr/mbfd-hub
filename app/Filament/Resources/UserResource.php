<?php

namespace App\Filament\Resources;

use App\Enums\AccountStatus;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Policies\RoleAssignmentPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Members & Access';

    protected static ?string $modelLabel = 'Member';

    protected static ?string $pluralModelLabel = 'Members & Access';

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('User Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Login email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Canonical identity')
                    ->schema([
                        Forms\Components\TextInput::make('employee_id')
                            ->label('Employee ID')
                            ->required()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('city_email')
                            ->label('Authoritative city email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\Select::make('account_status')
                            ->options([
                                AccountStatus::PendingActivation->value => 'Pending activation',
                                AccountStatus::Active->value => 'Active',
                                AccountStatus::Disabled->value => 'Disabled',
                            ])
                            ->default(AccountStatus::Active->value)
                            ->required(),
                        Forms\Components\TextInput::make('password')
                            ->label('One-time temporary password')
                            ->password()
                            ->visibleOn('create')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(12)
                            ->helperText('Write-only. The member must change it at first sign-in.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Profile')
                    ->schema([
                        Forms\Components\TextInput::make('display_name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rank')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('station')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Roles')
                    ->schema([
                        Forms\Components\Select::make('roles')
                            ->multiple()
                            ->relationship('roles', 'name')
                            ->saveRelationshipsUsing(function (User $record, ?array $state): void {
                                $actor = auth()->user();
                                abort_unless($actor instanceof User, 403);

                                $roleNames = \Spatie\Permission\Models\Role::query()
                                    ->where('guard_name', 'web')
                                    ->whereKey($state ?? [])
                                    ->pluck('name')
                                    ->all();

                                app(\App\Services\Security\RoleAssignmentService::class)
                                    ->sync($actor, $record, $roleNames);
                            })
                            ->disabled(fn (): bool => ! static::canManageRoles())
                            ->preload(),
                    ]),

                Forms\Components\Section::make('Direct permissions and app entitlements')
                    ->description('App access is explicit and independent from Admin access.')
                    ->schema([
                        Forms\Components\CheckboxList::make('permissions')
                            ->relationship('permissions', 'name')
                            ->options(fn (): array => \Spatie\Permission\Models\Permission::query()
                                ->where('guard_name', 'web')
                                ->where(fn ($query) => $query
                                    ->where('name', 'like', 'admin.%')
                                    ->orWhere('name', 'like', 'app.%'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->columns(2)
                            ->bulkToggleable()
                            ->disabled(fn (): bool => ! static::canManageRoles()),
                    ]),

                Forms\Components\Section::make('Notification subscriptions')
                    ->description('Database, web push, and email are independent. Email defaults off.')
                    ->schema([
                        Forms\Components\Repeater::make('notificationSubscriptions')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('event_key')
                                    ->options(collect(User::notificationPreferenceDefinitions())
                                        ->mapWithKeys(fn (array $definition, string $key): array => [$key => $definition['label']])
                                        ->all())
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                Forms\Components\Toggle::make('database_enabled')->label('Admin inbox'),
                                Forms\Components\Toggle::make('webpush_enabled')->label('Web push'),
                                Forms\Components\Toggle::make('email_enabled')->label('City email'),
                            ])
                            ->columns(4)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('employeeProfile.city_email')
                    ->label('City email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('account_status')
                    ->label('Status')
                    ->badge(),
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rank')
                    ->searchable(),
                Tables\Columns\TextColumn::make('station')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge()
                    ->separator(','),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.members.view') ?? false;
    }

    public static function canManageRoles(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(RoleAssignmentPolicy::class)->canDelegateAny($user);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('admin.members.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $record instanceof User || $actor->is($record)) {
            return false;
        }

        if ($record->hasRole('super_admin') && ! $actor->hasRole('super_admin')) {
            return false;
        }

        return $actor->can('admin.members.manage');
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
