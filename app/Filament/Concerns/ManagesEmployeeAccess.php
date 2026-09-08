<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\Security\AccountSecurityAction;
use App\Exceptions\CurrentPasswordMismatch;
use App\Filament\Support\EmployeeAccessSchema;
use App\Models\User;
use App\Services\Security\AccountSecurityService;
use App\Services\Security\ApplicationAccessService;
use App\Support\ApplicationAccessRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait ManagesEmployeeAccess
{
    protected function accountActions(): array
    {
        $actions = [];
        foreach ([
            'manageApplicationAccess' => ['Application access', 'applications', 'applicationOptions', 'selectedApplications', 'syncApplications'],
            'manageApplicationAdministration' => ['Application administration', 'administrations', 'applicationAdministrationOptions', 'selectedApplicationAdministrations', 'syncApplicationAdministrations'],
            'manageAdministrationCapabilities' => ['Hub capabilities', 'capabilities', 'capabilityOptions', null, 'syncAdministrationCapabilities'],
        ] as $name => [$label, $field, $options, $selected, $method]) {
            $actions[] = Action::make($name)->label($label)->visible(fn (): bool => $this->canManageAccountAccess())
                ->fillForm(function () use ($field, $selected): array {
                    $registry = app(ApplicationAccessRegistry::class);

                    return [$field => $selected !== null ? $registry->$selected($this->targetAccount())
                        : $this->targetAccount()->permissions()->where('guard_name', 'web')->whereIn('name', array_keys($registry->capabilityOptions()))->pluck('name')->all()];
                })
                ->form([
                    Forms\CheckboxList::make($field)->options(fn (): array => app(ApplicationAccessRegistry::class)->$options())
                        ->disableOptionWhen(fn (string $value): bool => $field === 'administrations' && ! $this->targetAccount()->hasDirectWebPermission('app.'.$value.'.access'))
                        ->helperText('Application entry, application administration, and Hub capabilities are separate. Revoking access or elevated roles can sign this member out across Hub applications. Inherited roles cannot be removed with direct-grant controls.'),
                    Forms\Placeholder::make('cloudEnforcementStatus')->label('Cloud enforcement status')
                        ->content(fn (): string => app(ApplicationAccessRegistry::class)->cloudEnforcementStatus($this->targetAccount())),
                    ...$this->securityFields(),
                ])
                ->action(fn (array $data) => $this->runProtected(function () use ($data, $field, $method): void {
                    app(ApplicationAccessService::class)->$method($this->actor(), $this->targetAccount(), $data[$field] ?? [], $data['current_password'], $data['reason']);
                }));
        }

        $actions[] = Action::make('manageRoles')->label('Hub roles')
            ->visible(function (): bool {
                $target = $this->accountOrNull();

                return $target !== null && app(\App\Policies\RoleAssignmentPolicy::class)->allows($this->actor(), $target, $target->roles->pluck('name')->all());
            })
            ->fillForm(fn (): array => ['roles' => $this->targetAccount()->roles->pluck('name')->all()])
            ->form([
                Forms\CheckboxList::make('roles')->options(fn (): array => \Spatie\Permission\Models\Role::query()->where('guard_name', 'web')->pluck('name', 'name')->all()),
                ...$this->securityFields(),
            ])
            ->action(fn (array $data) => $this->runProtected(function () use ($data): void {
                app(\App\Services\Security\RoleAssignmentService::class)->syncWithAuthorization($this->actor(), $this->targetAccount(), $data['roles'] ?? [], $data['current_password'], $data['reason']);
            }));

        $administrationActions = $actions;
        $actions = [];
        foreach ([
            'resetPassword' => ['Issue temporary password', AccountSecurityAction::AdministrativeRecovery, 'resetPassword'],
            'forcePasswordChange' => ['Require password change', AccountSecurityAction::ForcePasswordChange, 'forcePasswordChange'],
            'revokeSessions' => ['Revoke sessions', AccountSecurityAction::RevokeSessions, 'revokeSessions'],
            'disableAccount' => ['Disable account', AccountSecurityAction::Disable, 'disable'],
            'enableAccount' => ['Enable account', AccountSecurityAction::Enable, 'enable'],
        ] as $name => [$label, $permission, $method]) {
            $actions[] = Action::make($name)->label($label)
                ->color($method === 'disable' ? 'danger' : 'gray')
                ->visible(function () use ($permission, $method): bool {
                    $target = $this->accountOrNull();
                    if ($target === null || ! app(AccountSecurityService::class)->canPerform($this->actor(), $target, $permission)) {
                        return false;
                    }

                    return match ($method) {
                        'disable' => $target->getRawOriginal('account_status') !== 'disabled',
                        'enable' => $target->getRawOriginal('account_status') === 'disabled',
                        default => true,
                    };
                })
                ->form($this->securityFields($method === 'resetPassword'))
                ->action(fn (array $data) => $this->runProtected(function () use ($data, $method): void {
                    $service = app(AccountSecurityService::class);
                    if ($method === 'resetPassword') {
                        $service->resetPassword($this->actor(), $this->targetAccount(), $data['temporary_password'], $data['reason'], now(), $data['current_password']);
                    } else {
                        $service->$method($this->actor(), $this->targetAccount(), $data['reason'], now(), $data['current_password']);
                    }
                }));
        }

        return [
            Action::make('manageWorkgroups')->label('Workgroups')->color('gray')
                ->visible(fn (): bool => $this->accountOrNull() !== null && ! $this->targetAccount()->is($this->actor()) && $this->actor()->can('admin.workgroups.manage'))
                ->fillForm(fn (): array => ['memberships' => \App\Models\WorkgroupMember::query()->where('user_id', $this->targetAccount()->id)->orderBy('workgroup_id')->get()->map(fn (\App\Models\WorkgroupMember $membership): array => $membership->only(['workgroup_id', 'role', 'is_active', 'count_evaluations']))->all()])
                ->form([
                    Forms\Repeater::make('memberships')->label('Workgroup memberships')->schema([
                        Forms\Select::make('workgroup_id')->label('Workgroup')->options(fn (): array => \App\Models\Workgroup::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        Forms\Select::make('role')->options(['member' => 'Member', 'facilitator' => 'Facilitator', 'admin' => 'Workgroup administrator'])->required()->default('member'),
                        Forms\Toggle::make('is_active')->label('Active membership')->default(true),
                        Forms\Toggle::make('count_evaluations')->label('Count evaluations')->default(true),
                    ])->columns(2)->reorderable(false)->addActionLabel('Add another workgroup')
                        ->helperText('A member may belong to multiple workgroups. Removing a row disables that membership; evaluation history and membership IDs are retained.'),
                    ...$this->securityFields(),
                ])
                ->action(fn (array $data) => $this->runProtected(fn () => app(\App\Services\Security\EmployeeWorkgroupAdministration::class)->sync($this->actor(), $this->targetAccount(), array_values($data['memberships'] ?? []), $data['current_password'], $data['reason']))),
            Action::make('changeOwnPassword')->label('Change my password')->url(\App\Filament\Pages\SetPasswordPage::getUrl(panel: 'admin'))
                ->visible(fn (): bool => $this->accountOrNull()?->is($this->actor()) ?? false),
            \Filament\Actions\ActionGroup::make($actions)->label('Login & recovery')->color('gray')->button(),
            \Filament\Actions\ActionGroup::make($administrationActions)->label('Administration & access')->color('gray')->button(),
        ];
    }

    protected function securityFields(bool $temporaryPassword = false): array
    {
        $fields = [
            Forms\TextInput::make('current_password')->label('Your current administrator password')->password()->autocomplete('current-password')->required(),
            Forms\Textarea::make('reason')->label('Reason for this change')->required()->maxLength(500),
        ];
        if ($temporaryPassword) {
            $fields[] = Forms\TextInput::make('temporary_password')->label('One-time temporary password')->password()->autocomplete('new-password')->required()->minLength(12)
                ->helperText('Write-only. Deliver privately to the member; they must replace it at next sign-in. Existing sessions will be revoked.');
        }

        return $fields;
    }

    protected function runProtected(callable $mutation): void
    {
        try {
            $mutation();
        } catch (CurrentPasswordMismatch) {
            $path = $this->getMountedActionForm()->getStatePath().'.current_password';
            data_set($this, $path, '');
            throw ValidationException::withMessages([$path => 'Your current password is incorrect.']);
        } catch (ValidationException $exception) {
            $prefix = $this->getMountedActionForm()->getStatePath().'.';
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[str_starts_with($key, $prefix) ? $key : $prefix.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->getRecord()->refresh();
        Notification::make()->success()->title('Change saved and audited')->send();
    }

    protected function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    protected function accountOrNull(): ?User
    {
        return EmployeeAccessSchema::account($this->getRecord());
    }

    protected function targetAccount(): User
    {
        $account = $this->accountOrNull();
        abort_unless($account instanceof User, 404);

        return $account;
    }

    protected function canManageAccountAccess(): bool
    {
        $target = $this->accountOrNull();

        return $target !== null && $this->actor()->isAuthenticationAllowed() && $this->actor()->hasRole('super_admin')
            && ! $this->actor()->is($target) && ! $target->hasRole('super_admin');
    }
}
