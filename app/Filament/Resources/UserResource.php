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
                            ->label('Account email')
                            ->email()
                            ->disabled(fn (?User $record): bool => $record?->employee_profile_id !== null)
                            ->dehydrated(fn (?User $record): bool => $record?->employee_profile_id === null)
                            ->helperText('Linked accounts use Employee ID to sign in. Their city email is managed below; mailbox verification is completed by the member.')
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
                            ->label('City email on record')
                            ->email()
                            ->rules(['regex:/@miamibeachfl\.gov$/i'])
                            ->helperText('Administrative entry does not verify mailbox ownership. Changing this address clears previous verification; the member must verify the new mailbox.')
                            ->maxLength(255),
                        Forms\Components\Placeholder::make('city_email_verification_status')
                            ->label('Mailbox ownership')
                            ->visible(fn (?User $record): bool => $record?->employee_profile_id !== null)
                            ->content(function (?User $record): string {
                                $verification = $record === null ? null : app(\App\Services\Identity\CityEmailVerificationService::class)->status($record);

                                return $verification?->verified_at !== null
                                    ? 'Verified by the member on '.$verification->verified_at->format('M j, Y g:i A')
                                    : 'Mailbox ownership has not been verified through city email confirmation.';
                            }),
                        Forms\Components\Placeholder::make('city_email_pending_status')
                            ->label('Member confirmation')
                            ->visible(fn (?User $record): bool => $record?->employee_profile_id !== null)
                            ->content(function (?User $record): string {
                                $pending = $record === null ? null : app(\App\Services\Identity\CityEmailVerificationService::class)->status($record);

                                if ($pending === null) {
                                    $connected = $record === null ? null : app(\App\Services\Identity\CityEmailVerificationService::class)->connectedEmail($record);

                                    return $connected !== null
                                        ? 'Email already connected: '.$connected.'. No sign-in confirmation is required.'
                                        : 'The member will be asked to review their city email when signing in.';
                                }

                                return $pending->email.' — '.match ($pending->delivery_status) {
                                    'verified' => 'Mailbox verified',
                                    'failed' => 'Member confirmed the address; message delivery failed. Not verified.',
                                    'queued' => 'Verification message submitted; awaiting mailbox verification.',
                                    default => 'Member confirmed the address; awaiting mailbox verification.',
                                };
                            }),
                        Forms\Components\Select::make('account_status')
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Use the protected Enable or Disable account actions to change an existing account status.')
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

                Forms\Components\Section::make('Application access')
                    ->description('Use Manage application access to grant or revoke supported access with an audited reason. Profile Save does not change grants.')
                    ->schema(array_map(
                        fn (array $application, string $key) => Forms\Components\Placeholder::make('application_access_'.$key)
                            ->label($application['label'])
                            ->helperText($application['description'])
                            ->content(fn (?User $record): string => $record === null ? 'Create the member before managing access.' : app(\App\Support\ApplicationAccessRegistry::class)->states($record)[$key]['status']),
                        app(\App\Support\ApplicationAccessRegistry::class)->applications(),
                        array_keys(app(\App\Support\ApplicationAccessRegistry::class)->applications()),
                    ))
                    ->columns(2),

                Forms\Components\Section::make('Administration capabilities')
                    ->description('Capabilities are independent of application access. Use Manage administration capabilities to change direct grants; inherited roles are preserved.')
                    ->schema([
                        Forms\Components\Placeholder::make('administration_capabilities')
                            ->label('Current direct capabilities')
                            ->content(function (?User $record): string {
                                if ($record === null) {
                                    return 'Create the member before managing capabilities.';
                                }
                                if ($record->hasRole('super_admin')) {
                                    return 'All capabilities inherited from Super Administrator.';
                                }
                                $labels = app(\App\Support\ApplicationAccessRegistry::class)->capabilityOptions();

                                return $record->permissions()->where('guard_name', 'web')->whereIn('name', array_keys($labels))->pluck('name')
                                    ->map(fn (string $name): string => $labels[$name])->implode('; ') ?: 'No direct administration capabilities.';
                            }),
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
                    ->url(fn (User $record): ?string => static::canEdit($record) ? static::getUrl('edit', ['record' => $record]) : null)
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
