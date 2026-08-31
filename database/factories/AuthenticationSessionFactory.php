<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SessionContextClass;
use App\Models\AuthenticationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuthenticationSession> */
final class AuthenticationSessionFactory extends Factory
{
    protected $model = AuthenticationSession::class;

    public function definition(): array
    {
        $issuedAt = now();

        return [
            'user_id' => User::factory(),
            'session_id_hash' => hash('sha256', fake()->uuid()),
            'security_version' => 1,
            'context_class' => SessionContextClass::UnmanagedBrowser,
            'issued_at' => $issuedAt,
            'last_activity_at' => $issuedAt,
            'idle_expires_at' => $issuedAt->copy()->addHour(),
            'absolute_expires_at' => $issuedAt->copy()->addDay(),
            'recent_auth_at' => $issuedAt,
        ];
    }
}
