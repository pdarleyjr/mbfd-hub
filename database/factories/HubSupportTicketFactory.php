<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HubSupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HubSupportTicket> */
final class HubSupportTicketFactory extends Factory
{
    protected $model = HubSupportTicket::class;

    public function definition(): array
    {
        return [
            'client_submission_id' => (string) Str::uuid(),
            'reported_by_user_id' => User::factory(),
            'reporter_name_snapshot' => fake()->name(),
            'description' => fake()->sentence(),
            'generated_title' => fake()->sentence(5),
            'category' => 'other',
            'impact' => 'minor',
            'affected_component' => 'unknown',
            'status' => 'new',
            'diagnostics_schema_version' => 1,
        ];
    }
}
