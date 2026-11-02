<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\StockMovement;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Ems\Concerns\BuildsSupplyDataWorld;
use Tests\TestCase;

/**
 * The Supply Data SQL on real PostgreSQL (production's database): timestamptz comparisons, GROUP BY on an alias, the
 * count-variance CTE and the Eastern week buckets across the November 1, 2026 daylight-saving change. Each test runs
 * inside a transaction that is rolled back. Guarded to the explicitly configured disposable test database.
 */
#[Group('postgres')]
final class SupplyDataPostgresTest extends TestCase
{
    use BuildsSupplyDataWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDisposablePostgres();
        DB::beginTransaction();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function overview(array $input): array
    {
        return app(SupplyDataReport::class)->overview(SupplyDataFilters::fromArray($input + ['limit' => 50]));
    }

    public function test_postgresql_buckets_events_by_eastern_week_across_the_daylight_saving_change(): void
    {
        $truck = $this->sdApparatus('Rescue PG', 'PG'.strtoupper(substr(uniqid(), -6)));
        $item = $this->sdItem('PG gauze');
        foreach (['2026-11-01 03:59:00', '2026-11-01 04:00:00', '2026-11-08 04:59:00', '2026-11-08 05:00:00'] as $utc) {
            $request = $this->sdRequest($truck, InventoryRequest::RECONCILED, '2026-10-30 08:00:00', [['x', 1, 1]]);
            $this->sdMovement($item, StockMovement::ISSUED, -1, $request, '2026-11-01 00:00:00', ['occurred_at' => CarbonImmutable::parse($utc, 'UTC')]);
        }

        $o = $this->overview(['period' => 'custom', 'from' => '2026-10-26', 'to' => '2026-11-09']);

        $this->assertSame(['2026-10-25', '2026-11-01', '2026-11-08'], array_column($o['trend'], 'week_start'));
        $this->assertSame([1, 2, 1], array_column($o['trend'], 'distribution_events'));
        $this->assertSame(4, $o['headline']['distribution_events']);
        $this->assertSame(4, $o['headline']['requests_submitted']);
    }

    public function test_postgresql_answers_do_not_depend_on_the_session_time_zone(): void
    {
        $truck = $this->sdApparatus('Rescue PG', 'PG'.strtoupper(substr(uniqid(), -6)));
        $item = $this->sdItem('PG zone');
        foreach (['2026-11-01 03:59:00', '2026-11-01 04:00:00', '2026-11-08 04:59:00', '2026-11-08 05:00:00'] as $utc) {
            $request = $this->sdRequest($truck, InventoryRequest::RECONCILED, '2026-10-30 08:00:00', [['x', 1, 1]]);
            $this->sdMovement($item, StockMovement::ISSUED, -1, $request, '2026-11-01 00:00:00', ['occurred_at' => CarbonImmutable::parse($utc, 'UTC')]);
        }
        $input = ['period' => 'custom', 'from' => '2026-10-26', 'to' => '2026-11-09'];

        DB::statement("SET TIME ZONE 'UTC'");
        $utc = $this->overview($input);
        DB::statement("SET TIME ZONE 'Asia/Tokyo'");
        $tokyo = $this->overview($input);
        DB::statement("SET TIME ZONE 'America/Los_Angeles'");
        $pacific = $this->overview($input);

        $this->assertSame([1, 2, 1], array_column($utc['trend'], 'distribution_events'));
        $this->assertSame($utc['trend'], $tokyo['trend']);
        $this->assertSame($utc['trend'], $pacific['trend']);
        $this->assertSame($utc['headline'], $tokyo['headline']);
        $this->assertSame($utc['top_items'][0]['last_distributed_at'], $tokyo['top_items'][0]['last_distributed_at'], 'the Eastern time shown does not change either');
    }

    public function test_postgresql_nets_a_reversed_issuance_to_zero_and_a_correction_to_its_issuance_period(): void
    {
        $truck = $this->sdApparatus('Rescue PG', 'PG'.strtoupper(substr(uniqid(), -6)));
        $reversed = $this->sdItem('PG reversed');
        $kept = $this->sdItem('PG kept');
        $request = $this->sdRequest($truck, InventoryRequest::REVIEWED, '2026-10-20 08:00:00', [['x', 5, 0]]);
        $this->sdReverse($this->sdIssue($reversed, 5, $request, '2026-10-20 09:00:00'), $request, '2026-11-02 09:00:00');
        $this->sdIssue($kept, 4, $request, '2026-10-20 09:00:00');

        $o = $this->overview([]);

        $this->assertSame(['PG kept'], array_column($o['top_items'], 'name'));
        $this->assertSame(4, $o['top_items'][0]['distributed']);
        $this->assertSame(1, $o['headline']['distribution_events']);
    }

    public function test_postgresql_computes_variance_with_the_movement_id_cutoff(): void
    {
        $truck = $this->sdApparatus('Rescue PG', 'PG'.strtoupper(substr(uniqid(), -6)));
        $item = $this->sdItem('PG counted');
        $request = $this->sdRequest($truck, InventoryRequest::RECONCILED, '2026-10-05 08:00:00', [['x', 1, 1]]);
        $this->sdCount('2026-10-01 08:00:00', [[$item, 100]], 0);
        $this->sdIssue($item, 10, $request, '2026-10-05 09:00:00');
        $through = $this->sdMaxMovementId();
        $this->sdIssue($item, 3, $request, '2026-10-20 08:30:00');
        $this->sdCount('2026-10-20 09:00:00', [[$item, 85, 'counted', $through]], $this->sdMaxMovementId());

        $o = $this->overview([]);
        $row = collect($o['count_accuracy']['items'])->firstWhere('name', 'PG counted');

        $this->assertSame(1, $o['count_accuracy']['comparable_counts']);
        $this->assertSame([90, -5], [$row['expected'], $row['variance']]);
    }

    public function test_postgresql_runs_every_overview_section_for_an_empty_period(): void
    {
        $o = $this->overview(['period' => '12m']);

        $this->assertSame(0, $o['headline']['distribution_events']);
        $this->assertCount(13, $o['trend']);
        $this->assertSame('month_start', array_key_first($o['trend'][0]));
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
