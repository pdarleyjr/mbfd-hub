<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Services\Identity\EmployeeBootstrapCredentialProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            return array_merge(
                $data,
                app(EmployeeBootstrapCredentialProvisioner::class)->attributesForNewEmployee(),
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'data.employee_id' => 'Initial login provisioning is unavailable. Contact the system owner.',
            ]);
        }
    }
}
