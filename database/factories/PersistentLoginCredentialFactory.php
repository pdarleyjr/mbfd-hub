<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SessionContextClass;
use App\Models\PersistentLoginCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersistentLoginCredential> */
final class PersistentLoginCredentialFactory extends Factory
{
    protected $model = PersistentLoginCredential::class;

    public function definition(): array
    {
        $issuedAt = now();

        return [
            'user_id' => User::factory(),
            'selector_hash' => hash('sha256', fake()->uuid()),
            'validator_hash' => hash('sha256', fake()->uuid()),
            'context_class' => SessionContextClass::UnmanagedBrowser,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addWeek(),
        ];
    }
}
