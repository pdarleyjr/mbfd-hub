<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\Apparatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The apparatus form and the vehicle swap "replace" decision write "Reserve", which the
 * Postgres CHECK refused. SQLite (the test database) has no such CHECK, so the migration is
 * a no-op there; these tests pin the value list, idempotence and the write itself.
 */
final class ApparatusReserveStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function reserveMigration(): Migration
    {
        return require database_path('migrations/2026_10_24_130100_allow_reserve_status_on_apparatuses.php');
    }

    private function linkMigration(): Migration
    {
        return require database_path('migrations/2026_10_24_130000_add_master_vehicle_link_to_apparatuses_table.php');
    }

    public function test_allowed_values_keep_every_previous_value_and_add_reserve(): void
    {
        $migration = $this->reserveMigration();
        $previousMigration = (string) file_get_contents(database_path('migrations/2026_02_17_000005_update_apparatuses_status_constraint.php'));

        foreach ($migration::PREVIOUS_VALUES as $value) {
            $this->assertStringContainsString("'{$value}'", $previousMigration, "{$value} was allowed by the earlier constraint");
            $this->assertContains($value, $migration::ALLOWED_VALUES);
        }
        $this->assertContains('Reserve', $migration::ALLOWED_VALUES);
        $this->assertNotContains('Reserve', $migration::PREVIOUS_VALUES);
        $this->assertSame(count($migration::PREVIOUS_VALUES) + 1, count($migration::ALLOWED_VALUES));
    }

    public function test_migration_is_idempotent_and_down_is_safe(): void
    {
        $migration = $this->reserveMigration();

        $migration->up();
        $migration->up();
        $this->createWithStatus('Reserve', 'res-1');
        $migration->down();
        $migration->up();

        $this->assertSame('Reserve', Apparatus::query()->where('unit_id', 'res-1')->value('status'));
    }

    public function test_every_allowed_status_including_reserve_can_be_saved(): void
    {
        foreach ($this->reserveMigration()::ALLOWED_VALUES as $index => $status) {
            $this->createWithStatus($status, 'status-'.$index);
        }

        $this->assertSame(
            count($this->reserveMigration()::ALLOWED_VALUES),
            Apparatus::query()->where('unit_id', 'like', 'status-%')->count(),
        );
        $this->assertTrue(Apparatus::query()->where('status', 'Reserve')->exists());
    }

    public function test_postgres_constraint_lists_reserve(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The CHECK constraint only exists on Postgres.');
        }

        $definition = (string) DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'apparatuses_status_check'")?->def;

        $this->assertStringContainsString("'Reserve'", $definition);
        $this->assertStringContainsString("'reserve'", $definition);
        $this->assertStringContainsString("'In Service'", $definition);
    }

    public function test_link_columns_migration_round_trips(): void
    {
        $migration = $this->linkMigration();
        $this->assertTrue(Schema::hasColumns('apparatuses', ['unit_master_vehicle_id', 'master_link_status', 'master_link_note']));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('apparatuses', 'unit_master_vehicle_id'));
        $this->assertFalse(Schema::hasColumn('apparatuses', 'master_link_status'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('apparatuses', ['unit_master_vehicle_id', 'master_link_status', 'master_link_note']));
    }

    private function createWithStatus(string $status, string $unitId): Apparatus
    {
        return Apparatus::withoutEvents(fn (): Apparatus => Apparatus::query()->create([
            'unit_id' => $unitId, 'designation' => $unitId, 'name' => $unitId, 'slug' => $unitId,
            'type' => 'Engine', 'status' => $status,
        ]));
    }
}
