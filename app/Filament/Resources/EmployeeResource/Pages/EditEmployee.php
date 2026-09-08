<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    use \App\Filament\Concerns\ManagesEmployeeAccess;

    protected static string $resource = EmployeeResource::class;

    public function getRecord(): \App\Models\Employee
    {
        $record = parent::getRecord();
        assert($record instanceof \App\Models\Employee);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\Action::make('createLoginAccount')->label('Create login account')
                ->visible(fn (): bool => $this->canCorrectIdentity() && $this->accountOrNull() === null && $this->getRecord()->roster_status === 'active')
                ->form($this->securityFields(true))
                ->action(fn (array $data) => $this->runProtected(fn () => app(\App\Services\Security\EmployeeAccountAdministration::class)->createForEmployee($this->actor(), $this->getRecord(), $data['temporary_password'], $data['current_password'], $data['reason']))),
            Actions\Action::make('changeCityEmail')->label('Change city email')
                ->visible(fn (): bool => $this->canCorrectIdentity())
                ->form([
                    \Filament\Forms\Components\TextInput::make('city_email')->email()->required()->maxLength(255)->rules(['regex:/@miamibeachfl\\.gov$/i']),
                    ...$this->securityFields(),
                ])
                ->action(fn (array $data) => $this->runProtected(function () use ($data): void {
                    app(\App\Services\Identity\EmployeeProfileService::class)->update($this->actor(), $this->getRecord(), ['city_email' => $data['city_email']], $data['current_password'], $data['reason']);
                    $this->refreshFormData(['city_email']);
                })),
            Actions\Action::make('correctEmployeeId')->label('Correct Employee ID')
                ->visible(fn (): bool => $this->canCorrectIdentity())
                ->form([
                    \Filament\Forms\Components\TextInput::make('new_employee_id')->label('Correct Employee ID')->required()->maxLength(20),
                    ...$this->securityFields(),
                ])
                ->action(fn (array $data) => $this->runProtected(function () use ($data): void {
                    app(\App\Services\Security\EmployeeIdentityService::class)->correctEmployeeId($this->actor(), $this->getRecord(), $data['new_employee_id'], $data['current_password'], $data['reason']);
                    $this->refreshFormData(['employee_id']);
                })),
            Actions\Action::make('changeEmploymentStatus')->label('Employment status')
                ->visible(fn (): bool => $this->canCorrectIdentity())
                ->form([
                    \Filament\Forms\Components\Select::make('status')->options(['active' => 'Active employee', 'departed' => 'Departed — disable linked access; retain history'])->required(),
                    ...$this->securityFields(),
                ])
                ->action(fn (array $data) => $this->runProtected(function () use ($data): void {
                    app(\App\Services\Security\EmployeeIdentityService::class)->changeEmploymentStatus($this->actor(), $this->getRecord(), $data['status'], $data['current_password'], $data['reason']);
                })),
            ...$this->accountActions(),
        ];

        return [Actions\ActionGroup::make(array_slice($actions, 0, 4))->label('Identity & profile')->color('gray')->button(), ...array_slice($actions, 4)];
    }

    public function getTitle(): string
    {
        return $this->getRecord()->employee_id.' — '.$this->getRecord()->name;
    }

    protected function getSaveFormAction(): \Filament\Actions\Action
    {
        return parent::getSaveFormAction()->visible(fn (): bool => EmployeeResource::canUpdateProfile($this->getRecord()));
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        assert($record instanceof \App\Models\Employee);

        return app(\App\Services\Identity\EmployeeProfileService::class)->update($this->actor(), $record, $data);
    }

    private function canCorrectIdentity(): bool
    {
        return $this->actor()->isAuthenticationAllowed() && $this->actor()->hasRole('super_admin')
            && (int) $this->actor()->employee_profile_id !== (int) $this->getRecord()->id;
    }
}
