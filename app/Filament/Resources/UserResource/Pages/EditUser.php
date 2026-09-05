<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private ?string $cityEmail = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resetPassword')
                ->label('Reset password')
                ->visible(fn (): bool => auth()->user()?->can('admin.members.security') ?? false)
                ->form($this->securityForm(includeTemporaryPassword: true))
                ->action(function (array $data): void {
                    $actor = $this->confirmedActor($data['current_password']);
                    app(\App\Services\Security\AccountSecurityService::class)->resetPassword(
                        $actor, $this->targetUser(), $data['temporary_password'], $data['reason'], now(),
                    );
                }),
            Actions\Action::make('forcePasswordChange')
                ->label('Force password change')
                ->visible(fn (): bool => auth()->user()?->can('admin.members.security') ?? false)
                ->form($this->securityForm())
                ->action(function (array $data): void {
                    $actor = $this->confirmedActor($data['current_password']);
                    app(\App\Services\Security\AccountSecurityService::class)->forcePasswordChange(
                        $actor, $this->targetUser(), $data['reason'], now(),
                    );
                }),
            Actions\Action::make('revokeSessions')
                ->label('Revoke sessions')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('admin.members.security') ?? false)
                ->form($this->securityForm())
                ->action(function (array $data): void {
                    $actor = $this->confirmedActor($data['current_password']);
                    app(\App\Services\Security\AccountSecurityService::class)->revokeSessions(
                        $actor, $this->targetUser(), $data['reason'], now(),
                    );
                }),
            Actions\Action::make('disableAccount')
                ->label('Disable account')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->targetUser()->getRawOriginal('account_status') !== 'disabled'
                    && (auth()->user()?->can('admin.members.security') ?? false))
                ->form($this->securityForm())
                ->action(function (array $data): void {
                    $actor = $this->confirmedActor($data['current_password']);
                    app(\App\Services\Security\AccountSecurityService::class)->disable(
                        $actor, $this->targetUser(), $data['reason'], now(),
                    );
                }),
            Actions\Action::make('enableAccount')
                ->label('Enable account')
                ->visible(fn (): bool => $this->targetUser()->getRawOriginal('account_status') === 'disabled'
                    && (auth()->user()?->can('admin.members.security') ?? false))
                ->form($this->securityForm())
                ->action(function (array $data): void {
                    $actor = $this->confirmedActor($data['current_password']);
                    app(\App\Services\Security\AccountSecurityService::class)->enable(
                        $actor, $this->targetUser(), $data['reason'], now(),
                    );
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['city_email'] = $this->targetUser()->employeeProfile?->city_email;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->cityEmail = isset($data['city_email']) && filled($data['city_email'])
            ? (string) $data['city_email']
            : null;
        unset($data['city_email']);
        $target = $this->targetUser();

        if (isset($data['employee_id']) && $data['employee_id'] !== $target->employee_id) {
            $employee = Employee::query()->where('employee_id', $data['employee_id'])->first();
            if ($employee === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Add the Employee profile before linking this member.',
                ]);
            }

            $collision = User::query()
                ->whereKeyNot($target->getKey())
                ->where(function ($query) use ($employee, $data): void {
                    $query->where('employee_profile_id', $employee->getKey())
                        ->orWhere('employee_id', $data['employee_id']);
                })
                ->exists();
            if ($collision) {
                throw ValidationException::withMessages([
                    'employee_id' => 'That Employee ID is already linked to another member.',
                ]);
            }

            $data['employee_profile_id'] = $employee->getKey();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $target = $this->targetUser();
        $employee = $target->employeeProfile;
        if ($employee !== null && $this->cityEmail !== null) {
            app(\App\Services\Identity\CanonicalCityEmailService::class)
                ->sync($employee, $target, $this->cityEmail);
        }
    }

    /** @return array<int, \Filament\Forms\Components\Component> */
    private function securityForm(bool $includeTemporaryPassword = false): array
    {
        $fields = [
            Forms\Components\TextInput::make('current_password')
                ->label('Your current password')
                ->password()
                ->required(),
            Forms\Components\Textarea::make('reason')
                ->required()
                ->maxLength(500),
        ];
        if ($includeTemporaryPassword) {
            array_splice($fields, 1, 0, [
                Forms\Components\TextInput::make('temporary_password')
                    ->label('One-time temporary password')
                    ->password()
                    ->required()
                    ->minLength(12),
            ]);
        }

        return $fields;
    }

    private function confirmedActor(string $password): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        session()->put('auth.password_confirmed_at', time());

        return $actor;
    }

    private function targetUser(): User
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            throw new LogicException('The user record is unavailable.');
        }

        return $record;
    }
}
