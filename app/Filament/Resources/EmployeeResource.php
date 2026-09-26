<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Support\EmployeeAccessSchema;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

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
                            EmployeeAccessSchema::controls('profile-identity', 'Employee record', [
                                'correctEmployeeId' => 'Correct Employee ID', 'changeEmploymentStatus' => 'Employment status',
                            ], 'One verified Employee ID connects this profile, login account, workgroups and history. Identity and employment changes use separate protected controls.'),
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
                            EmployeeAccessSchema::controls('profile-save', 'Profile changes', ['save' => 'Save profile changes'], 'Save applies only to editable profile fields in this tab. Roles, login and access changes are saved separately in their protected dialogs.'),
                        ])
                        ->columns(2),
                    ...EmployeeAccessSchema::tabs(),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            // Avoid a deferred Livewire rerender racing the bulk-selection Alpine component.
            ->deferLoading(false)
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
                Tables\Columns\TextColumn::make('invitation_delivery')->label('Invitation email')->badge()
                    ->state(fn (Employee $record): string => $record->user?->memberOnboardingInvitation?->emailDeliveryLabel() ?? 'Invitation not sent'),
                Tables\Columns\TextColumn::make('invitation_status')->label('Account activation')->badge()
                    ->state(function (Employee $record): string {
                        $status = $record->user?->getRawOriginal('account_status');
                        if ($status === 'active') {
                            return 'Account activated';
                        }
                        if ($status !== 'pending_activation') {
                            return 'Not applicable';
                        }

                        $invitation = $record->user->memberOnboardingInvitation;
                        if (in_array($invitation?->delivery_status, ['pending', 'queued', 'redeemed'], true)
                            && $invitation->expires_at?->lessThanOrEqualTo(now()) === true) {
                            return 'Expired — ready to resend';
                        }

                        return $invitation?->redeemed_at !== null || $invitation?->delivery_status === 'redeemed'
                            ? 'Opened / redeemed' : 'Awaiting activation';
                    }),
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
                Tables\Actions\Action::make('sendMemberInvitation')
                    ->label(fn (Employee $record): string => $record->user?->memberOnboardingInvitation === null ? 'Send invitation' : 'Resend invitation')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (Employee $record): bool => Pages\ListEmployees::canIssueInvitations()
                        && $record->user?->getRawOriginal('account_status') === 'pending_activation'
                        && app(MemberOnboardingInvitationService::class)->assess($record)['status'] === 'ready')
                    ->modalHeading('Invite this member')
                    ->modalSubmitActionLabel('Queue this invitation')
                    ->fillForm(function (Employee $record): array {
                        $user = $record->user;

                        return ['binding_hash' => $user === null ? '' : IssueMemberOnboardingInvitation::bindingHash(
                            $user->id, $record->employee_id, (string) $record->city_email, $user->security_version,
                        )];
                    })
                    ->form([
                        Forms\Components\Placeholder::make('recipient')->label('Exact recipient')
                            ->content(fn (Employee $record): HtmlString => new HtmlString(
                                '<strong>'.e($record->employee_id).'</strong> — '.e(strtolower(trim((string) $record->city_email))),
                            )),
                        Forms\Components\Hidden::make('binding_hash')->required(),
                        Forms\Components\Checkbox::make('confirm_member')
                            ->label('I reviewed this Employee ID and City email and want to send one invitation.')
                            ->rule('accepted')->required(),
                        Forms\Components\TextInput::make('current_password')->label('Your administrator password')
                            ->password()->autocomplete('current-password')->required(),
                    ])
                    ->action(fn (array $data, Employee $record, Pages\ListEmployees $livewire) => $livewire->queueMemberInvitation($record, $data)),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sendSelectedOnboardingInvitations')
                    ->label('Send onboarding invitations to selected members')
                    ->extraAttributes(['style' => 'max-width: calc(100vw - 10rem); white-space: normal;'])
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (): bool => Pages\ListEmployees::canIssueInvitations())
                    ->modalHeading('Invite selected members')
                    ->modalDescription('Review this exact selection. Each eligible member receives a separate private invitation that expires after 30 minutes.')
                    ->modalSubmitActionLabel('Queue selected invitations')
                    ->fillForm(fn (Collection $records, Pages\ListEmployees $livewire): array => $livewire->previewSelectedInvitations($records))
                    ->form([
                        Forms\Components\Placeholder::make('selected_invitation_preview')->label('Selected members')
                            ->content(fn (Pages\ListEmployees $livewire): HtmlString => $livewire->selectedInvitationPreview()),
                        Forms\Components\Checkbox::make('confirm_recipients')
                            ->label('I reviewed the eligible recipients and skipped members in this exact selection.')
                            ->rule('accepted')->required(),
                        Forms\Components\TextInput::make('current_password')->label('Your administrator password')
                            ->password()->autocomplete('current-password')->required(),
                    ])
                    ->action(fn (array $data, Collection $records, Pages\ListEmployees $livewire) => $livewire->queueSelectedInvitations($records, $data)),
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
        return parent::getEloquentQuery()->with('user.memberOnboardingInvitation.outboundEmail');
    }
}
