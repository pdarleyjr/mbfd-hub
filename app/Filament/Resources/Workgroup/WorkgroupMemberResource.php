<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Concerns\ResolvesWorkgroupAccess;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class WorkgroupMemberResource extends Resource
{
    use ResolvesWorkgroupAccess;

    protected static ?string $model = WorkgroupMember::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Member Information')
                    ->schema([
                        Forms\Components\Toggle::make('create_new_user')
                            ->label('Create New User Account')
                            ->default(false)
                            ->reactive()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('user_id')
                            ->label('Select Existing User')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(fn (callable $get) => ! $get('create_new_user'))
                            ->visible(fn (callable $get) => ! $get('create_new_user')),
                        Forms\Components\TextInput::make('new_user_name')
                            ->label('Full Name')
                            ->required(fn (callable $get) => $get('create_new_user'))
                            ->visible(fn (callable $get) => $get('create_new_user'))
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('new_user_email')
                            ->label('Email')
                            ->email()
                            ->required(fn (callable $get) => $get('create_new_user'))
                            ->visible(fn (callable $get) => $get('create_new_user'))
                            ->unique('users', 'email')
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('new_user_password')
                            ->label('Password')
                            ->password()
                            ->required(fn (callable $get) => $get('create_new_user'))
                            ->visible(fn (callable $get) => $get('create_new_user'))
                            ->minLength(6)
                            ->dehydrated(false),
                        Forms\Components\Select::make('workgroup_id')
                            ->label('Workgroup')
                            ->options(fn () => self::workgroupAccess()->scopeManageWorkgroups(Workgroup::query(), self::currentWorkgroupUser())->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('role')
                            ->label('Role')
                            ->options([
                                'admin' => 'Admin',
                                'facilitator' => 'Facilitator',
                                'member' => 'Member',
                            ])
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\Toggle::make('count_evaluations')
                            ->label('Count Evaluations')
                            ->helperText('When OFF, this member\'s submissions are excluded from results, rankings, analytics, and AI reports.')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable()
                    ->label('Name'),
                Tables\Columns\TextColumn::make('user.email')
                    ->searchable()
                    ->label('Email'),
                Tables\Columns\TextColumn::make('workgroup.name')
                    ->searchable()
                    ->sortable()
                    ->label('Workgroup'),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'facilitator' => 'warning',
                        'member' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\ToggleColumn::make('count_evaluations')
                    ->label('Count Evals')
                    ->sortable()
                    ->disabled(fn (WorkgroupMember $record): bool => ! self::canEdit($record))
                    ->beforeStateUpdated(function (WorkgroupMember $record): void {
                        abort_unless(self::canEdit($record), 404);
                    })
                    ->afterStateUpdated(function ($record) {
                        // Clear AI caches when toggling so reports refresh
                        \Illuminate\Support\Facades\Cache::forget("workgroup_ai_exec_report_{$record->workgroup_id}");
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_id')
                    ->label('Workgroup')
                    ->options(fn () => self::workgroupAccess()->scopeWorkgroups(Workgroup::query(), self::currentWorkgroupUser())->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('role')
                    ->label('Role')
                    ->options([
                        'admin' => 'Admin',
                        'facilitator' => 'Facilitator',
                        'member' => 'Member',
                    ]),
                Tables\Filters\Filter::make('active')
                    ->query(fn (Builder $query) => $query->where('is_active', true))
                    ->label('Active Only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('setPassword')
                    ->label('Set Password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (WorkgroupMember $record): bool => self::canEdit($record))
                    ->authorize(fn (WorkgroupMember $record): bool => self::canEdit($record))
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('New Password')
                            ->required()
                            ->minLength(4),
                    ])
                    ->action(function (WorkgroupMember $record, array $data): void {
                        abort_unless(self::canEdit($record), 404);

                        $user = $record->user;
                        if ($user) {
                            $user->update([
                                'password' => Hash::make($data['new_password']),
                            ]);
                            Notification::make()
                                ->title("Password set for {$user->name}")
                                ->success()
                                ->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkgroupMembers::route('/'),
            'create' => Pages\CreateWorkgroupMember::route('/create'),
            'edit' => Pages\EditWorkgroupMember::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::currentWorkgroupUser();

        return $user !== null && self::workgroupAccess()->canEnterPanel($user);
    }

    public static function canCreate(): bool
    {
        $user = self::currentWorkgroupUser();

        return $user !== null && self::workgroupAccess()->canManageAnyWorkgroup($user);
    }

    public static function canEdit($record): bool
    {
        $user = self::currentWorkgroupUser();

        return $user !== null
            && $record instanceof WorkgroupMember
            && $record->workgroup !== null
            && self::workgroupAccess()->canManageWorkgroup($user, $record->workgroup);
    }

    public static function canDelete($record): bool
    {
        return self::canEdit($record);
    }

    public static function canView($record): bool
    {
        $user = self::currentWorkgroupUser();

        return $user !== null
            && $record instanceof WorkgroupMember
            && $record->workgroup !== null
            && self::workgroupAccess()->canViewWorkgroup($user, $record->workgroup);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::workgroupAccess()->scopeWorkgroupRecords(parent::getEloquentQuery(), self::currentWorkgroupUser());
    }
}
