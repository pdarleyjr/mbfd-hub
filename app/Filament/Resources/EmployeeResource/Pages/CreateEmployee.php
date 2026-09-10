<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Services\Identity\EmployeeAccountLifecycle;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EmployeeAccountLifecycle::class)->createActive($data, now());
        } catch (RuntimeException|UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'data.employee_id' => 'The employee and canonical account could not be created safely. Review the Employee ID.',
            ]);
        }
    }
}
