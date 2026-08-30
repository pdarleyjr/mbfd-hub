<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controllers\Api\ApparatusController;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\DailyCheckoutInspectionSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * This regression intentionally uses a second PostgreSQL connection. It writes
 * the winning row after the primary request has checked the UUID but before its
 * insert, forcing PostgreSQL's real unique constraint path instead of merely
 * replaying a completed HTTP request.
 *
 * Run only against a disposable migrated PostgreSQL database.
 */
#[Group('postgres')]
final class DailyCheckoutPostgresIdempotencyRaceTest extends TestCase
{
    public function test_database_unique_constraint_collision_returns_the_single_existing_receipt(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This regression requires a disposable PostgreSQL database.');
        }

        $station = Station::query()->firstOrCreate(
            ['station_number' => 901],
            [
                'name' => 'PostgreSQL Race Test Station',
                'address' => '901 Test Way',
                'is_active' => true,
            ],
        );
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E-RACE-'.uniqid(),
            'name' => 'Engine Race Test',
            'type' => 'Engine',
            'vehicle_number' => 'RACE-1',
            'designation' => 'E901',
            'slug' => 'engine-race-'.uniqid(),
            'make' => 'Test',
            'model' => 'Race',
            'year' => 2026,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);

        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $this->assertTrue($resolution['usable']);
        $this->assertIsString($resolution['checklist_version']);

        $raceConnection = 'daily_checkout_race_winner';
        config([
            "database.connections.{$raceConnection}" => config('database.connections.'.config('database.default')),
        ]);
        DB::purge($raceConnection);
        DB::reconnect($raceConnection);

        $clientSubmissionId = (string) Str::uuid();
        $controller = new class($raceConnection) extends ApparatusController
        {
            private bool $winnerCreated = false;

            public function __construct(private readonly string $winnerConnection) {}

            public function winnerWasCreated(): bool
            {
                return $this->winnerCreated;
            }

            /** @param array<string, mixed> $attributes */
            protected function createInspection(array $attributes): ApparatusInspection
            {
                if (! $this->winnerCreated) {
                    $winner = new ApparatusInspection;
                    $winner->setConnection($this->winnerConnection);
                    $winner->fill($attributes);
                    $winner->save();
                    $this->winnerCreated = true;
                }

                return parent::createInspection($attributes);
            }

            protected function lockApparatusForInspection(int $id): Apparatus
            {
                // The production controller retains lockForUpdate(). This test
                // seam leaves the FK row unlockable to the external winning
                // connection, so PostgreSQL can reach the unique collision.
                return Apparatus::query()->findOrFail($id);
            }
        };

        $request = Request::create(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            'POST',
            $this->submissionPayload($apparatus, $clientSubmissionId, $resolution),
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response = $controller->storeInspection(
            $request,
            $apparatus->id,
            app(DailyCheckoutChecklistResolver::class),
            app(DailyCheckoutInspectionSessionService::class),
        );

        $this->assertTrue($controller->winnerWasCreated());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, ApparatusInspection::query()
            ->where('client_submission_id', $clientSubmissionId)
            ->count());
        $this->assertSame(
            $resolution['checklist_version'],
            ApparatusInspection::query()->where('client_submission_id', $clientSubmissionId)->sole()->checklist_version,
        );
    }

    /**
     * @param  array{checklist: array<string, mixed>|null, checklist_version: string|null}  $resolution
     * @return array<string, mixed>
     */
    private function submissionPayload(Apparatus $apparatus, string $clientSubmissionId, array $resolution): array
    {
        $checklist = $resolution['checklist'];
        $this->assertIsArray($checklist);

        return [
            'client_submission_id' => $clientSubmissionId,
            'checklist_version' => $resolution['checklist_version'],
            'operator_name' => 'PostgreSQL Race Tester',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->vehicle_number,
            'compartments' => array_map(static function (array $compartment): array {
                $compartmentId = (string) $compartment['id'];

                return [
                    'id' => $compartmentId,
                    'name' => (string) ($compartment['name'] ?? $compartment['title']),
                    'items' => array_map(static fn (array $item, int $index): array => [
                        'id' => (string) ($item['id'] ?? "{$compartmentId}-item-".($index + 1)),
                        'name' => (string) $item['name'],
                        'status' => 'Present',
                        'notes' => null,
                    ], $compartment['items'], array_keys($compartment['items'])),
                ];
            }, $checklist['compartments']),
            'defects' => [],
        ];
    }
}
