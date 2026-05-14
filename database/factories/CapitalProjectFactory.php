<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\CapitalProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapitalProject>
 */
class CapitalProjectFactory extends Factory
{
    protected $model = CapitalProject::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-1 year', '+1 month');
        // Cast to immutable so we can safely do +N months without mutating $startDate.
        $startImmutable = \DateTimeImmutable::createFromMutable($startDate);

        return [
            'project_number' => 'CP-' . strtoupper($this->faker->bothify('??##')),
            'name' => $this->faker->randomElement([
                'Station',
                'Apparatus Bay',
                'Training Tower',
                'Dispatch',
                'EOC',
            ]) . ' ' . $this->faker->randomElement([
                'Upgrade',
                'Renovation',
                'Replacement',
                'Expansion',
                'Modernization',
            ]),
            'description' => $this->faker->paragraph(2),
            'budget_amount' => $this->faker->numberBetween(25_000, 2_500_000),
            'status' => $this->faker->randomElement(ProjectStatus::cases()),
            'priority' => $this->faker->randomElement(ProjectPriority::cases()),
            'start_date' => $startDate,
            'target_completion_date' => $startImmutable->modify('+' . $this->faker->numberBetween(3, 18) . ' months'),
            'actual_completion_date' => null,
            'percent_complete' => $this->faker->numberBetween(0, 90),
            'notes' => null,
            'attachments' => [],
            'station_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Pending]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::InProgress]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::Completed,
            'actual_completion_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'percent_complete' => 100,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::InProgress,
            'target_completion_date' => $this->faker->dateTimeBetween('-90 days', '-1 day'),
            'actual_completion_date' => null,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['priority' => ProjectPriority::Critical]);
    }
}
