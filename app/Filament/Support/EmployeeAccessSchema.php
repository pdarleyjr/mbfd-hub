<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Employee;
use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Filament\Forms\Components as Forms;
use Illuminate\Database\Eloquent\Model;

final class EmployeeAccessSchema
{
    public static function account(?Model $record): ?User
    {
        return $record instanceof Employee ? $record->user : ($record instanceof User ? $record : null);
    }

    public static function tabs(): array
    {
        return [
            Forms\Tabs\Tab::make('Identity & Security')->schema([
                self::controls('login-recovery', 'Identity & security controls', [
                    'createLoginAccount' => 'Create login account', 'changeOwnPassword' => 'Change my password',
                    'changeCityEmail' => 'Change city email', 'changeRecoveryEmail' => 'Change recovery email',
                    'resetPassword' => 'Issue temporary password', 'forcePasswordChange' => 'Require password change',
                    'revokeSessions' => 'Revoke sessions', 'disableAccount' => 'Disable account', 'enableAccount' => 'Enable account',
                ], 'Current login and recovery settings are shown below. Protected changes require your current password and a reason.'),
                Forms\Placeholder::make('account_status_summary')->label('Login account')
                    ->content(fn (?Model $record): string => self::account($record)?->getRawOriginal('account_status') ?? 'Awaiting account — personnel record retained; first-login provisioning is separate.'),
                Forms\Placeholder::make('identity_provider_status')->label('Identity provider')
                    ->content(function (?Model $record): string {
                        $link = self::account($record)?->identityLinks()->where('provider', 'authentik')->first();
                        if ($link === null) {
                            return 'Hub local — not enrolled in the MBFD Identity canary.';
                        }

                        return 'MBFD Identity · '.ucfirst($link->status).' · last synchronized '.($link->last_synced_at?->format('M j, Y g:i A') ?? 'not confirmed');
                    }),
                Forms\Placeholder::make('identity_username')->label('Identity username')
                    ->content(fn (?Model $record): string => self::account($record)?->employee_id ?: 'No canonical Employee ID — provisioning blocked.'),
                Forms\Placeholder::make('identity_mfa')->label('MFA and passkeys')
                    ->content(function (?Model $record): string {
                        $state = self::account($record)?->identityLinks()->where('provider', 'authentik')->first()?->security_state;
                        if (! is_array($state)) {
                            return 'Not reported — no upstream enrollment has been verified.';
                        }

                        return ($state['mfa_enrolled'] ?? false) ? 'MFA enrolled · '.(int) ($state['passkey_count'] ?? 0).' passkey(s)' : 'No MFA enrollment reported.';
                    }),
                Forms\Placeholder::make('identity_sessions')->label('Sessions')
                    ->content(function (?Model $record): string {
                        $account = self::account($record);
                        if ($account === null) {
                            return 'No login account.';
                        }
                        $local = $account->authenticationSessions()->whereNull('revoked_at')->count();

                        return $local.' active Hub session(s). Revocation also queues upstream enforcement for linked identities.';
                    }),
                Forms\Placeholder::make('account_email')->label('Connected email')
                    ->content(fn (?Model $record): string => self::account($record)->email ?? 'No connected account'),
                Forms\Placeholder::make('email_confirmation')->label('Email confirmation')
                    ->content(function (?Model $record): string {
                        $user = self::account($record);
                        if ($user === null) {
                            return 'Not applicable until a login account exists.';
                        }
                        $verification = app(\App\Services\Identity\CityEmailVerificationService::class)->status($user);
                        if ($verification?->verified_at !== null) {
                            return 'Mailbox verified '.$verification->verified_at->format('M j, Y g:i A');
                        }

                        return app(\App\Services\Identity\CityEmailVerificationService::class)->connectedEmail($user) !== null
                            ? 'Existing connected email — one-time sign-in confirmation excluded; mailbox ownership is not independently verified.'
                            : 'Member confirmation required at sign-in; mailbox ownership not yet verified.';
                    }),
                Forms\Placeholder::make('password_status')->label('Password')
                    ->content(fn (?Model $record): string => self::account($record) === null ? 'No canonical account password.' : (self::account($record)->must_change_password ? 'Change required at next sign-in.' : 'Password set. Existing passwords cannot be viewed.')),
                Forms\Placeholder::make('recovery_help')->label('Recovery controls')
                    ->content('Local accounts can receive a one-time temporary password. MBFD Identity accounts use private, time-limited recovery links sent only to the authoritative recovery address. Session revocation and disable actions remain locally authoritative and queue upstream enforcement.'),
            ]),
            Forms\Tabs\Tab::make('Administration')->schema([
                self::controls('hub-roles', 'Hub roles', ['manageRoles' => 'Edit Hub roles'], 'Current assigned roles. Hub roles can provide inherited administrative capabilities.'),
                Forms\Placeholder::make('authorized_roles')->label('Current Hub roles')
                    ->content(fn (?Model $record): string => self::account($record)?->roles->pluck('name')->implode(', ') ?: 'No assigned roles'),
                self::controls('hub-capabilities', 'Hub capabilities', ['manageAdministrationCapabilities' => 'Edit direct Hub capabilities'], 'The effective summary includes direct and role-inherited capabilities. Role-inherited capabilities must be changed through Hub roles, not direct grants.'),
                Forms\Placeholder::make('authorized_capabilities')->label('Effective administrative capabilities')
                    ->content(function (?Model $record): string {
                        $user = self::account($record);
                        if ($user?->hasRole('super_admin')) {
                            return 'Super Administrator — all Hub capabilities. Application enforcement is shown separately.';
                        }
                        $labels = app(ApplicationAccessRegistry::class)->capabilityOptions();

                        return $user?->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => isset($labels[$name]))
                            ->map(fn (string $name): string => $labels[$name])->implode('; ') ?: 'No administrative capabilities';
                    }),
            ]),
            Forms\Tabs\Tab::make('Ecosystem access')->schema([
                self::controls('ecosystem', 'Application access & administrator roles', [
                    'manageApplicationAccess' => 'Edit application access',
                    'manageApplicationAdministration' => 'Edit application administrator roles',
                ], 'Current settings are shown below. Application entry and application administrator roles are separate from Hub capabilities.'),
                Forms\View::make('filament.employees.application-access'),
            ]),
            Forms\Tabs\Tab::make('Workgroups & history')->schema([
                self::controls('workgroups', 'Workgroup memberships', ['manageWorkgroups' => 'Manage workgroups'], 'Add multiple workgroups or change workgroup roles. Removing a membership keeps its history.'),
                Forms\View::make('filament.employees.workgroups-history')->viewData([]),
            ]),
        ];
    }

    /** @param array<string, string> $actions */
    public static function controls(string $context, string $title, array $actions, string $description): Forms\View
    {
        return Forms\View::make('filament.employees.contextual-controls')
            ->viewData(compact('context', 'title', 'actions', 'description'))
            ->visibleOn('edit')->columnSpanFull();
    }
}
