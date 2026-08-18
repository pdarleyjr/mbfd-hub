<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

final class PersonnelCatalog
{
    /** @return array<string, array{label: string, sizes: array<int, string>}> */
    public function uniforms(): array
    {
        return config('personnel_requests.uniform_catalog', []);
    }

    /** @return array<string, string> */
    public function equipment(): array
    {
        return config('personnel_requests.equipment_catalog', []);
    }

    /** @return array<string, string> */
    public function equipmentReasons(): array
    {
        return config('personnel_requests.equipment_reasons', []);
    }

    public function uniform(string $code): ?array
    {
        return $this->uniforms()[$code] ?? null;
    }

    public function equipmentLabel(string $code): ?string
    {
        return $this->equipment()[$code] ?? null;
    }
}
