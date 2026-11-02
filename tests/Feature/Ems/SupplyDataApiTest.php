<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\Feature\Ems\Concerns\BuildsSupplyDataWorld;
use Tests\TestCase;

/** The Supply Data HTTP API: access, validation, the response envelope, read-only behaviour and the export. */
final class SupplyDataApiTest extends TestCase
{
    use BuildsEmsWorld;
    use BuildsSupplyDataWorld;
    use RefreshDatabase;

    /** @var array{0: int, 1: int}|null */
    private ?array $pathFixtures = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSupplyDataClock();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return array<string, string> every Supply Data read, by name */
    private function paths(): array
    {
        $this->pathFixtures ??= [$this->sdItem('Gauze')->id, $this->sdApparatus('Rescue 2', 'R2')->id];
        [$item, $truck] = $this->pathFixtures;

        return [
            'overview' => '/ems/api/supply-data',
            'item' => "/ems/api/supply-data/items/{$item}",
            'unit' => "/ems/api/supply-data/units/unit:{$truck}",
            'detail' => '/ems/api/supply-data/units/detail',
            'export' => '/ems/api/supply-data/export.xlsx',
        ];
    }

    /** A little history so every endpoint has something to say. */
    private function seedHistory(): void
    {
        $truck = $this->sdApparatus('Engine 1', 'E1');
        $item = $this->sdItem('Gloves', ['threshold_qty_base' => 4]);
        $guess = $this->sdItem('Guess', ['threshold_qty_base' => 4]);
        $this->sdCount('2026-10-01 08:00:00', [[$item, 20]], 0);
        $this->sdEstimate('2026-11-01 08:00:00', [[$guess, 9]]);
        foreach ([10, 12, 14] as $day) {
            $request = $this->sdRequest($truck, InventoryRequest::RECONCILED, "2026-10-{$day} 08:00:00", [['Gloves', 3, 3]], ['reviewed_at' => $this->sdUtc("2026-10-{$day} 09:00:00"), 'reconciled_at' => $this->sdUtc("2026-10-{$day} 15:00:00")]);
            $this->sdIssue($item, 3, $request, "2026-10-{$day} 15:00:00");
            $this->sdIssue($guess, 1, $request, "2026-10-{$day} 15:00:00");
        }
    }

    private function asUser(User $user, string $path): TestResponse
    {
        return $this->actingAs($user)->getJson($path);
    }

    public function test_signed_out_callers_get_401_json_on_every_endpoint(): void
    {
        foreach ($this->paths() as $name => $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_a_member_without_ems_view_gets_403_on_every_endpoint(): void
    {
        $member = $this->emsMember('Firefighter');
        foreach ($this->paths() as $name => $path) {
            $this->assertSame(403, $this->asUser($member, $path)->status(), $name);
        }
        $this->assertSame('forbidden', $this->asUser($member, '/ems/api/supply-data')->json('error.code'));
    }

    public function test_an_ems_viewer_and_a_captain_get_200_on_every_endpoint(): void
    {
        $this->seedHistory();
        foreach ([$this->emsAdmin(['admin.ems.view']), $this->emsCaptain()] as $user) {
            foreach ($this->paths() as $name => $path) {
                $this->assertSame(200, $this->asUser($user, $path)->baseResponse->getStatusCode(), $name);
            }
        }
    }

    public function test_an_explicit_captain5_deny_override_blocks_the_endpoints(): void
    {
        $captain = $this->emsCaptain();
        $this->assertSame(200, $this->asUser($captain, '/ems/api/supply-data')->status());
        Captain5AccessOverride::query()->create(['employee_profile_id' => $captain->employee_profile_id, 'effect' => Captain5AccessOverride::EFFECT_DENY, 'level' => null, 'reason' => 'Removed', 'set_by_user_id' => $this->emsSuperAdmin()->id]);
        $this->forgetEmsMemo();

        foreach ($this->paths() as $name => $path) {
            $this->assertSame(403, $this->asUser($captain->fresh(), $path)->status(), $name);
        }
    }

    public function test_a_viewer_cannot_call_any_write_endpoint_and_supply_data_accepts_no_writes(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $uuid = (string) Str::uuid();
        $key = ['Idempotency-Key' => 'k-'.Str::random(12)];
        $this->actingAs($viewer);

        foreach ([
            ['post', "/ems/api/requests/{$uuid}/acknowledge"], ['post', "/ems/api/requests/{$uuid}/reject"], ['post', '/ems/api/forms/reconcile'],
            ['post', "/ems/api/requests/{$uuid}/issue"], ['post', "/ems/api/requests/{$uuid}/correct"], ['post', '/ems/api/supply/counts'],
            ['post', '/ems/api/supply/orders'], ['post', '/ems/api/supply/adjustments'], ['post', '/ems/api/supply/count-form/start'],
            ['patch', '/ems/api/supply/items/1'], ['post', '/ems/api/supply/master/items/1/count'],
        ] as [$method, $path]) {
            $this->assertSame(403, $this->{$method.'Json'}($path, [], $key)->status(), "{$method} {$path}");
        }
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->assertSame(405, $this->{$method.'Json'}('/ems/api/supply-data', [], $key)->status(), "Supply Data accepts no {$method}");
        }
    }

    public function test_the_routes_sit_in_the_staff_group_with_view_access_and_a_read_throttle(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'ems/api/supply-data'));

        $this->assertCount(4, $routes);
        foreach ($routes as $route) {
            $middleware = collect($route->gatherMiddleware())->map(fn ($m): string => is_string($m) ? $m : 'closure');
            $this->assertContains('auth:web', $middleware->all());
            $this->assertContains('App\\Http\\Middleware\\Ems\\EnsureEmsAccess:view', $middleware->all());
            $this->assertContains('throttle:60,1,ems-supply-data', $middleware->all());
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_the_overview_uses_the_standard_envelope_and_is_never_cached(): void
    {
        $this->seedHistory();
        $response = $this->asUser($this->emsCaptain(), '/ems/api/supply-data?period=90d&limit=5');

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('data.label', SupplyDataReport::LABEL)->assertJsonPath('data.period.key', '90d');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(6, $response->json('data.headline.distribution_events'), 'three requests, two items each');
        $this->assertSame(['ok', 'data', 'meta'], array_keys($response->json()));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badQueries(): array
    {
        return [
            'span over 400 days' => ['period=custom&from=2025-09-01&to=2026-10-10', 'to'],
            'from after to' => ['period=custom&from=2026-10-10&to=2026-10-01', 'to'],
            'future date' => ['period=custom&from=2026-10-01&to=2026-11-03', 'to'],
            'unknown period' => ['period=decade', 'period'],
            'custom without dates' => ['period=custom', 'from'],
            'bad unit' => ['unit=garage', 'unit'],
            'unknown item' => ['item=999999', 'item'],
            'bad limit' => ['limit=500', 'limit'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badQueries')]
    public function test_bad_parameters_are_422_with_the_field_named(string $query, string $field): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);

        // The unit page takes its unit from the path, so a unit in the query string is ignored there.
        foreach (['/ems/api/supply-data', '/ems/api/supply-data/units/detail'] as $path) {
            if ($field === 'unit' && str_contains($path, '/units/')) {
                continue;
            }
            $response = $this->asUser($viewer, "{$path}?{$query}");
            $response->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('error.code', 'validation_failed');
            $this->assertArrayHasKey($field, (array) $response->json('error.fields'), $path);
        }
        $this->asUser($viewer, "/ems/api/supply-data/export.xlsx?{$query}")->assertStatus(422);
    }

    public function test_unknown_things_are_404_and_controlled_items_do_not_exist_here(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $controlled = $this->sdItem('Controlled thing', ['controlled_substance' => true]);

        $this->asUser($viewer, '/ems/api/supply-data/items/999999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->asUser($viewer, '/ems/api/supply-data/items/'.$controlled->id)->assertNotFound();
        $this->asUser($viewer, '/ems/api/supply-data/items/abc')->assertNotFound();
        $this->asUser($viewer, '/ems/api/supply-data/units/unit:999999')->assertNotFound();
        $this->asUser($viewer, '/ems/api/supply-data/units/garage')->assertNotFound();
        $this->asUser($viewer, '/ems/api/supply-data/units/detail')->assertOk()->assertJsonPath('data.unit.kind', 'detail');
        $this->asUser($viewer, '/ems/api/supply-data/units/unassigned')->assertOk();
        $this->asUser($viewer, '/ems/api/supply-data?item='.$controlled->id)->assertStatus(422);
    }

    public function test_no_endpoint_writes_to_any_ems_table_or_the_stock_lock(): void
    {
        $this->seedHistory();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $paths = $this->paths();
        $tables = collect(Schema::getTableListing())->map(fn ($t) => preg_replace('/^.*\./', '', (string) $t))->filter(fn (string $t): bool => str_starts_with($t, 'ems_'))->values();
        $this->assertGreaterThan(10, $tables->count());
        $counts = fn (): array => $tables->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();
        $stock = fn (): mixed => DB::table('ems_counters')->where('name', 'stock')->value('value');
        $before = [$counts(), $stock(), DB::table('ems_counters')->where('name', 'mir')->value('value')];

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });
        foreach ($paths as $path) {
            $this->asUser($viewer, $path)->assertOk();
        }
        $this->asUser($viewer, '/ems/api/supply-data?unit=detail&category=PPE&period=12m')->assertOk();

        $this->assertSame($before, [$counts(), $stock(), DB::table('ems_counters')->where('name', 'mir')->value('value')], 'row counts and the stock lock value are unchanged');
        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|create|alter|drop|truncate)\b/', $sql) === 1 && str_contains($sql, 'ems_'));
        $this->assertSame([], array_values($writes), 'no statement writes to an ems_ table');
        $locks = array_filter($statements, fn (string $sql): bool => str_contains($sql, 'for update'));
        $this->assertSame([], array_values($locks), 'no row is locked');
    }

    /** The SQL statements a callback runs. */
    private function queriesOf(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $work();

        return $count;
    }

    private function overviewQueries(): int
    {
        return $this->queriesOf(fn () => app(SupplyDataReport::class)->overview(SupplyDataFilters::fromArray([])));
    }

    public function test_the_overview_runs_a_small_fixed_number_of_queries_whatever_the_data_size(): void
    {
        $this->seedHistory();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $this->overviewQueries(); // warm: schema and permission lookups
        $small = $this->overviewQueries();
        $this->asUser($viewer, '/ems/api/supply-data')->assertOk(); // warm: first sign-in registers the session
        $smallHttp = $this->queriesOf(fn () => $this->asUser($viewer, '/ems/api/supply-data')->assertOk());

        // Many more items, requests, lines, movements and counts, all counted against the same stock cut-off.
        $trucks = [$this->sdApparatus('Rescue 2', 'R2'), $this->sdApparatus('Rescue 3', 'R3')];
        $lines = [];
        for ($i = 0; $i < 40; $i++) {
            $item = $this->sdItem('Bulk '.$i, ['threshold_qty_base' => 5]);
            $lines[] = [$item, 30 + $i];
            $day = str_pad((string) (5 + $i % 20), 2, '0', STR_PAD_LEFT);
            $request = $this->sdRequest($trucks[$i % 2], $i % 3 === 0 ? InventoryRequest::SUBMITTED : InventoryRequest::RECONCILED, "2026-10-{$day} 08:00:00", [['Bulk', 5, 5], ['Bulk 2', 2, 2]]);
            $this->sdIssue($item, 2 + $i % 4, $request, "2026-10-{$day} 09:00:00");
        }
        $this->sdCount('2026-10-03 08:00:00', $lines, 0);
        $this->sdCount('2026-10-28 08:00:00', array_map(fn (array $l): array => [$l[0], $l[1] - 2], $lines), 0);

        $this->assertSame($small, $this->overviewQueries(), 'the number of queries does not grow with the number of rows');
        $this->assertSame($smallHttp, $this->queriesOf(fn () => $this->asUser($viewer, '/ems/api/supply-data')->assertOk()));
    }

    public function test_the_query_budget(): void
    {
        $this->seedHistory();
        $item = $this->sdItem('Another');
        $this->overviewQueries();

        $overview = $this->overviewQueries();
        $itemPage = $this->queriesOf(fn () => app(SupplyDataReport::class)->item($item, SupplyDataFilters::fromArray([])));
        $unitPage = $this->queriesOf(fn () => app(SupplyDataReport::class)->unit('detail', SupplyDataFilters::fromArray([])));

        fwrite(STDERR, "\nSUPPLY-DATA-QUERIES overview={$overview} item={$itemPage} unit={$unitPage}\n");
        $this->assertLessThanOrEqual(25, $overview, 'overview, with two stock cut-offs among the current baselines');
        $this->assertLessThanOrEqual(25, $itemPage);
        $this->assertLessThanOrEqual(10, $unitPage);
    }

    public function test_the_export_totals_equal_the_json_and_say_what_they_are(): void
    {
        $this->seedHistory();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $json = $this->asUser($viewer, '/ems/api/supply-data?limit=50')->assertOk()->json('data');
        $response = $this->asUser($viewer, '/ems/api/supply-data/export.xlsx');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('MBFD - Supply Data - 2026-11-02.xlsx', (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'sd-test-');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        $this->assertSame(['Summary', 'Supplies', 'Units', 'Stock', 'Fulfillment', 'Count accuracy', 'About this export'], $book->getSheetNames());
        $summary = collect($book->getSheetByName('Summary')->toArray())->keyBy(0);
        $this->assertEquals($json['headline']['distribution_events'], $summary['Distribution events'][1]);
        $this->assertEquals($json['headline']['requests_reconciled'], $summary['Requests reconciled'][1]);
        $this->assertEquals($json['attention']['estimated'], $summary['Estimated balances'][1]);

        $supplies = collect($book->getSheetByName('Supplies')->toArray())->skip(1);
        $this->assertSame($json['headline']['distribution_events'], (int) $supplies->sum(3));
        $this->assertSame(count($json['top_items']), $supplies->count());
        $units = collect($book->getSheetByName('Units')->toArray())->skip(1);
        $this->assertSame($json['headline']['distribution_events'], (int) $units->sum(2));
        $this->assertSame($json['headline']['requests_submitted'], (int) $units->sum(1));

        $stock = collect($book->getSheetByName('Stock')->toArray())->skip(1);
        $this->assertSame(count($json['stock']), $stock->count());
        $this->assertSame($json['attention']['estimated'], $stock->where(5, 'Estimated')->count(), 'every estimated balance is marked');

        $about = collect($book->getSheetByName('About this export')->toArray())->keyBy(0);
        $this->assertSame(SupplyDataReport::LABEL, $about['Read this first'][1]);
        $this->assertStringContainsString('Oct 4, 2026 to Nov 2, 2026 (30 days', $about['Interval'][1]);
        $this->assertStringContainsString('Nov 2, 2026', $about['Generated (ET)'][1]);
        $this->assertStringContainsString('ems_stock_movements', $about['Provenance'][1]);
        $this->assertSame($json['definitions']['distributed'], $about['Definition: distributed'][1]);
    }

    public function test_the_export_follows_the_same_filters_as_the_json(): void
    {
        $this->seedHistory();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $response = $this->asUser($viewer, '/ems/api/supply-data/export.xlsx?unit=detail&period=90d');
        $path = tempnam(sys_get_temp_dir(), 'sd-test-');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);
        $about = collect($book->getSheetByName('About this export')->toArray())->keyBy(0);

        $this->assertSame('unit detail', $about['Filters'][1]);
        $this->assertStringContainsString('90 days', $about['Interval'][1]);
        $this->assertSame(0, (int) collect($book->getSheetByName('Supplies')->toArray())->skip(1)->sum(3), 'the detail unit received nothing');
    }

    public function test_the_item_and_unit_pages_carry_the_contract_keys(): void
    {
        $this->seedHistory();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $item = DB::table('ems_supply_items')->where('name', 'Gloves')->value('id');
        $truck = DB::table('apparatuses')->where('name', 'Engine 1')->value('id');

        $itemData = $this->asUser($viewer, "/ems/api/supply-data/items/{$item}")->assertOk()->json('data');
        $unitData = $this->asUser($viewer, "/ems/api/supply-data/units/unit:{$truck}")->assertOk()->json('data');

        $this->assertSame(['label', 'generated_at', 'timezone', 'item', 'balance', 'period', 'filters', 'distribution', 'trend', 'units', 'replenishment', 'counts', 'movements', 'movements_truncated'], array_keys($itemData));
        $this->assertSame(9, $itemData['distribution']['distributed']);
        $this->assertSame(3, $itemData['distribution']['distribution_events']);
        $this->assertSame(100.0, (float) array_sum(array_column($itemData['units'], 'share_pct')));
        $this->assertSame(['label', 'generated_at', 'timezone', 'unit', 'period', 'filters', 'summary', 'top_requested', 'top_distributed', 'trend', 'requests'], array_keys($unitData));
        $this->assertSame(3, $unitData['summary']['requests']);
        $this->assertSame(3, $unitData['summary']['requests_reconciled']);
        $this->assertSame(9, $unitData['summary']['qty_requested']);
        $this->assertSame(6, $unitData['summary']['distributed_events']);
        $this->assertCount(3, $unitData['requests']);
        $this->assertSame('Engine 1', $unitData['unit']['label']);
    }

    public function test_the_default_filters_match_the_service(): void
    {
        $this->seedHistory();
        $viewer = $this->emsAdmin(['admin.ems.view']);

        $http = $this->asUser($viewer, '/ems/api/supply-data')->json('data');
        $service = json_decode((string) json_encode(app(SupplyDataReport::class)->overview(SupplyDataFilters::fromArray([]))), true);

        $this->assertSame($service['headline'], $http['headline']);
        $this->assertSame($service['stock'], $http['stock']);
    }
}
