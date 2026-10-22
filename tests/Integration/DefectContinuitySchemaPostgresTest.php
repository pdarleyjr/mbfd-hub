<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Apparatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The continuity migrations contain PostgreSQL-only DDL (partial unique indexes, a CHECK
 * constraint) that the SQLite suites cannot prove. This runs against the guarded disposable
 * PostgreSQL database only, on rows it creates and removes itself (Agent F).
 */
#[Group('postgres')]
final class DefectContinuitySchemaPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_partial_unique_indexes_check_constraint_and_dropped_legacy_index_behave_on_postgresql(): void
    {
        $this->requireDisposablePostgres();

        $suffix = strtoupper(substr(uniqid(), -6));
        $apparatus = Apparatus::query()->create([
            'unit_id' => "E-SCHEMA-{$suffix}", 'name' => "Schema fixture {$suffix}", 'type' => 'Engine', 'status' => 'In Service',
            'designation' => "ES{$suffix}", 'slug' => "schema-{$suffix}", 'vehicle_number' => "SCH-{$suffix}",
        ]);
        $defectIds = [];

        try {
            // The legacy index is gone and both partial indexes exist with their predicates.
            $indexes = DB::table('pg_indexes')->where('tablename', 'apparatus_defects')->pluck('indexdef', 'indexname')->all();
            $this->assertArrayNotHasKey('defect_dedup', $indexes, 'The legacy defect_dedup index must be dropped.');
            $this->assertArrayHasKey('apparatus_defects_open_name_unique', $indexes);
            $this->assertArrayHasKey('apparatus_defects_open_key_unique', $indexes);
            $this->assertStringContainsString('WHERE', $indexes['apparatus_defects_open_name_unique']);
            $this->assertStringContainsString('WHERE', $indexes['apparatus_defects_open_key_unique']);

            // One unresolved row per apparatus/compartment/item name.
            $defectIds[] = $this->defect($apparatus->id, 'Cab', 'Radio', false, 'cab', 'cab-radio');
            $this->assertUniqueViolation(fn () => $this->defect($apparatus->id, 'Cab', 'Radio', false));

            // One unresolved row per stable key, even when the display names differ.
            $this->assertUniqueViolation(fn () => $this->defect($apparatus->id, 'Cab (renamed)', 'Radio (renamed)', false, 'cab', 'cab-radio'));

            // Rows without an item key are constrained by name only.
            $defectIds[] = $this->defect($apparatus->id, 'Cab', 'Mirror', false);
            $defectIds[] = $this->defect($apparatus->id, 'Cab', 'Horn', false);

            // Resolved episodes may repeat freely: this is what the legacy index forbade.
            $defectIds[] = $this->defect($apparatus->id, 'Cab', 'Radio', true, 'cab', 'cab-radio');
            $defectIds[] = $this->defect($apparatus->id, 'Cab', 'Radio', true, 'cab', 'cab-radio');
            $this->assertSame(3, DB::table('apparatus_defects')->where('apparatus_id', $apparatus->id)->where('compartment', 'Cab')->where('item', 'Radio')->count());

            // Resolving the open row frees the name for a recurrence.
            DB::table('apparatus_defects')->where('id', $defectIds[0])->update(['resolved' => true]);
            $defectIds[] = $this->defect($apparatus->id, 'Cab', 'Radio', false, 'cab', 'cab-radio');

            // A message must belong to an inspection or an issue.
            $this->assertCheckViolation(fn () => DB::table('apparatus_issue_messages')->insert([
                'apparatus_inspection_id' => null, 'apparatus_defect_id' => null, 'visibility' => 'member', 'body' => 'orphan',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table('apparatus_issue_messages')->insert([
                'apparatus_inspection_id' => null, 'apparatus_defect_id' => $defectIds[1], 'visibility' => 'member', 'body' => 'attached',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // Event idempotency keys are unique.
            $event = [
                'apparatus_defect_id' => $defectIds[1], 'event_type' => 'acknowledged', 'idempotency_key' => "key-{$suffix}",
                'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('apparatus_defect_events')->insert($event);
            $this->assertUniqueViolation(fn () => DB::table('apparatus_defect_events')->insert($event));
        } finally {
            DB::table('apparatus_issue_messages')->whereIn('apparatus_defect_id', $defectIds)->delete();
            DB::table('apparatus_defect_events')->whereIn('apparatus_defect_id', $defectIds)->delete();
            DB::table('apparatus_defects')->where('apparatus_id', $apparatus->id)->delete();
            DB::table('apparatuses')->where('id', $apparatus->id)->delete();
        }
    }

    private function defect(int $apparatusId, string $compartment, string $item, bool $resolved, ?string $compartmentKey = null, ?string $itemKey = null): int
    {
        return (int) DB::table('apparatus_defects')->insertGetId([
            'apparatus_id' => $apparatusId, 'compartment' => $compartment, 'item' => $item, 'status' => $resolved ? 'resolved' : 'open',
            'resolved' => $resolved, 'compartment_key' => $compartmentKey, 'item_key' => $itemKey,
            'created_at' => now()->format('Y-m-d H:i:s'), 'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function assertUniqueViolation(callable $call): void
    {
        try {
            $call();
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode(), 'Expected a unique violation, got: '.$exception->getMessage());

            return;
        }
        $this->fail('Expected a PostgreSQL unique violation.');
    }

    private function assertCheckViolation(callable $call): void
    {
        try {
            $call();
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode(), 'Expected a check violation, got: '.$exception->getMessage());

            return;
        }
        $this->fail('Expected a PostgreSQL check violation.');
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing')
            && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql'
            && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }

        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('PostgreSQL integration tests require the explicit loopback disposable database configuration.');
        }

        $this->markTestSkipped('This regression requires the explicitly configured disposable PostgreSQL test database.');
    }
}
