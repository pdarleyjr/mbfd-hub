<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DevicePrincipal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DevicePrincipal> */
final class DevicePrincipalFactory extends Factory
{
    protected $model = DevicePrincipal::class;

    public function definition(): array
    {
        return [
            'type' => 'test_device',
            'abilities' => [],
            'credential_key_hash' => hash('sha256', fake()->uuid()),
            'credential_key_id' => fake()->unique()->uuid(),
            'status' => 'active',
            'security_version' => 1,
            'issued_at' => now(),
        ];
    }
}
