<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountProfileResource\Pages;

use App\Filament\Concerns\ManagesEmployeeAccess;
use App\Filament\Resources\AccountProfileResource;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAccountProfile extends EditRecord
{
    use ManagesEmployeeAccess;

    protected static string $resource = AccountProfileResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name.' — Account profile';
    }

    public function getRecord(): \App\Models\User
    {
        $record = parent::getRecord();
        assert($record instanceof \App\Models\User);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeRecoveryEmail')->label('Change recovery email')
                ->visible(fn (): bool => $this->canManageAccountAccess())
                ->form([
                    \Filament\Forms\Components\TextInput::make('email')->label('Recovery email')->email()->required()->maxLength(254)
                        ->helperText('Verify this destination with the account owner. Changing it clears mailbox proof, recovery tokens, and active sessions.'),
                    ...$this->securityFields(),
                ])
                ->action(fn (array $data) => $this->runProtected(fn () => app(\App\Services\Security\EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($this->actor(), $this->targetAccount(), $data['email'], $data['current_password'], $data['reason']))),
            Action::make('linkEmployee')->label('Link verified employee')
                ->visible(fn (): bool => $this->canManageAccountAccess())
                ->form([
                    Select::make('employee_profile_id')->label('Exact employee record')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => Employee::query()->whereDoesntHave('user')
                            ->where(fn ($query) => $query->where('employee_id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
                            ->limit(30)->get()->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->employee_id.' — '.$employee->name])->all())
                        ->helperText('Verify the Employee ID against the authoritative roster. This preserves the existing account and employee primary keys; it revokes sessions.'),
                    ...$this->securityFields(),
                ])
                ->action(function (array $data): void {
                    $employee = Employee::query()->findOrFail($data['employee_profile_id']);
                    $this->runProtected(fn () => app(\App\Services\Security\EmployeeIdentityService::class)->link($this->actor(), $this->targetAccount(), $employee, $data['current_password'], $data['reason']));
                    $this->redirect(\App\Filament\Resources\EmployeeResource::getUrl('edit', ['record' => $employee]));
                }),
            Action::make('approveNonemployee')->label('Approve nonemployee')
                ->visible(fn (): bool => $this->canManageAccountAccess() && $this->getRecord()->getRawOriginal('account_classification') !== 'approved_nonemployee')
                ->form($this->securityFields())
                ->action(fn (array $data) => $this->runProtected(fn () => app(\App\Services\Security\EmployeeAccountAdministration::class)->approveNonemployee($this->actor(), $this->targetAccount(), $data['current_password'], $data['reason']))),
            ...$this->accountActions(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof \App\Models\User);

        return app(\App\Services\Security\EmployeeAccountAdministration::class)->updateUnlinkedProfile($this->actor(), $record, $data);
    }

    protected function getSaveFormAction(): \Filament\Actions\Action
    {
        return parent::getSaveFormAction()->label('Save profile changes')->keyBindings([])
            ->visible(fn (): bool => AccountProfileResource::canUpdateProfile($this->getRecord()));
    }
}
