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
            Forms\Tabs\Tab::make('Login & recovery')->schema([
                Forms\Placeholder::make('account_status_summary')->label('Login account')
                    ->content(fn (?Model $record): string => self::account($record)?->getRawOriginal('account_status') ?? 'Awaiting account — personnel record retained; first-login provisioning is separate.'),
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
                    ->content('Use the protected actions above to issue a one-time temporary password, require a change, revoke sessions, or disable access. Each action requires your current password and an audit reason. No email is sent by temporary-password replacement.'),
            ]),
            Forms\Tabs\Tab::make('Administration')->schema([
                Forms\Placeholder::make('authorized_roles')->label('Hub roles')
                    ->content(fn (?Model $record): string => self::account($record)?->roles->pluck('name')->implode(', ') ?: 'No assigned roles'),
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
            Forms\Tabs\Tab::make('Ecosystem access')->schema([Forms\View::make('filament.employees.application-access')]),
            Forms\Tabs\Tab::make('Workgroups & history')->schema([
                Forms\View::make('filament.employees.workgroups-history')->viewData([]),
            ]),
        ];
    }
}
