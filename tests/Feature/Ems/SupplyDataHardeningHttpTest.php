<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\Feature\Ems\Concerns\BuildsSupplyDataWorld;
use Tests\TestCase;

/** The reviewer's HTTP-level cases: tampering, overflow, runtime throttle, privacy, read-only proof, and the full export. */
final class SupplyDataHardeningHttpTest extends TestCase
{
    use BuildsEmsWorld;
    use BuildsSupplyDataWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSupplyDataClock();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return array{0: \App\Models\Ems\SupplyItem, 1: \App\Models\Apparatus, 2: InventoryRequest} */
    private function world(): array
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $gloves = $this->sdItem('Gloves', ['threshold_qty_base' => 4]);
        $this->sdCount('2026-10-01 08:00:00', [[$gloves, 50]], 0);
        $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-10 08:00:00', [['Gloves', 3, 3]], ['submitter_name' => 'Jane Q Member', 'reviewed_by_name' => 'Reviewer Rita']);
        $this->sdMovement($gloves, 'issued', -3, $request, '2026-10-10 15:00:00', ['actor_name' => 'Actor Alice', 'reason' => 'Issued for the form']);
        $this->sdMovement($gloves, 'received', 20, null, '2026-10-12 09:00:00', ['actor_name' => 'Receiver Bob', 'reason' => 'Order received']);
        $this->sdMovement($gloves, 'adjusted', -1, null, '2026-10-13 09:00:00', ['actor_name' => 'Adjuster Carl', 'reason' => 'Dropped one']);

        return [$gloves, $r2, $request];
    }

    public function test_tampered_queries_and_paths_are_only_ever_200_404_or_422(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$gloves, $r2] = $this->world();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $queries = [
            'item=-1', 'item=0', 'item=abc', 'item[]=1', 'item=1e3', 'item=99999999999999999999', 'item=1;DROP%20TABLE%20ems_stock_movements', 'item=1%20OR%201=1', 'item=%00',
            'limit=0', 'limit=-1', 'limit=51', 'limit=99999999', 'limit=abc', 'limit[]=1', 'limit=1.5', 'limit=%20', 'limit[a]=1',
            'period=custom&from=2026-10-01&to=2026-11-02', 'period=custom&from[]=2026-10-01&to=2026-11-02', 'period=custom&from=2026-10-01&to[]=2026-11-02',
            'period=custom&from=2026-02-30&to=2026-03-02', 'period=custom&from=2026-13-01&to=2026-13-02', 'period=custom&from=0000-00-00&to=0000-00-01',
            'period=custom&from=2026-10-01&to=2099-01-01', 'period=custom&from=1900-01-01&to=2026-11-02', 'period=custom&from=2025-09-29&to=2026-11-02',
            'period=custom&from=2025-09-28&to=2026-11-02', 'period=custom&from=2026-11-02&to=2026-11-02', 'period=custom&from=2026-1-1&to=2026-11-02',
            'period=custom&from=%00&to=2026-11-02', 'period[]=30d', 'period=30d%27', 'period=CUSTOM', 'period=30d&from=garbage&to=garbage',
            'unit=unit:abc', 'unit=unit:999999', 'unit=../', 'unit=unit:0', 'unit=unit:-1', 'unit=unit:1%0a', 'unit[]=detail', 'unit=detail%0a',
            'unit=unit:99999999999999999999', 'unit=unit:999999999999999999', "unit=detail';--",
            'category='.str_repeat('a', 81), 'category='.str_repeat('a', 80), 'category[]=x', 'category=%27%20OR%201=1--', 'category=%E2%80%AE%00', 'category[a][b]=c',
            '0=1', 'x[]=1&x[]=2',
        ];
        $paths = ['/ems/api/supply-data', "/ems/api/supply-data/items/{$gloves->id}", "/ems/api/supply-data/units/unit:{$r2->id}", '/ems/api/supply-data/units/detail', '/ems/api/supply-data/export.xlsx'];

        $unexpected = [];
        foreach ($paths as $path) {
            foreach ($queries as $query) {
                $response = $this->actingAs($viewer)->getJson("{$path}?{$query}");
                $code = $response->baseResponse->getStatusCode();
                if (! in_array($code, [200, 404, 422], true) || ($code === 200 && ! str_contains($path, 'export') && $response->json('ok') !== true)) {
                    $unexpected[] = "{$code} {$path}?{$query}";
                }
            }
        }

        $this->assertSame([], $unexpected);
    }

    public function test_f6_ids_too_big_for_an_integer_are_a_plain_404_not_a_server_error(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [, $r2] = $this->world();
        $viewer = $this->emsAdmin(['admin.ems.view']);

        foreach (['/items/99999999999999999999', '/items/9223372036854775807', '/items/9223372036854775808', '/items/-1', '/items/1.5', '/items/abc', '/items/%00', '/items/0',
            '/units/unit:99999999999999999999', '/units/unit:999999999999999999', '/units/unit:0', '/units/unit:-1', '/units/UNASSIGNED', '/units/unit:abc',
            '/units/..%2F..%2Fetc%2Fpasswd', '/units/%00', '/units/a/b'] as $path) {
            $this->assertSame(404, $this->actingAs($viewer)->getJson('/ems/api/supply-data'.$path)->baseResponse->getStatusCode(), $path);
        }
        $this->assertSame(200, $this->actingAs($viewer)->getJson("/ems/api/supply-data/units/unit:{$r2->id}")->baseResponse->getStatusCode());
    }

    public function test_f10_a_unit_key_with_a_trailing_newline_is_not_a_unit(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [, $r2] = $this->world();
        $viewer = $this->emsAdmin(['admin.ems.view']);

        $this->actingAs($viewer)->getJson('/ems/api/supply-data/units/detail%0A')->assertNotFound();
        $this->actingAs($viewer)->getJson("/ems/api/supply-data/units/unit:{$r2->id}%0A")->assertNotFound();
        $this->actingAs($viewer)->getJson('/ems/api/supply-data/units/detail')->assertOk();
    }

    public function test_the_read_throttle_allows_60_a_minute_then_answers_429_json_across_the_routes(): void
    {
        $this->world();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $codes = [];
        for ($i = 0; $i < 63; $i++) {
            $codes[] = $this->actingAs($viewer)->getJson('/ems/api/supply-data/units/detail')->baseResponse->getStatusCode();
        }

        $this->assertSame([200 => 60, 429 => 3], array_count_values($codes));
        $other = $this->actingAs($viewer)->getJson('/ems/api/supply-data');
        $this->assertSame(429, $other->baseResponse->getStatusCode(), 'one bucket for every Supply Data route');
        $this->assertIsArray($other->json());
    }

    public function test_no_person_names_appear_in_any_response_or_the_export(): void
    {
        [$gloves, $r2] = $this->world();
        $viewer = $this->emsAdmin(['admin.ems.view'], 'Viewer Vera');
        $bodies = [
            'overview' => $this->actingAs($viewer)->getJson('/ems/api/supply-data?period=90d')->getContent(),
            'item' => $this->actingAs($viewer)->getJson("/ems/api/supply-data/items/{$gloves->id}?period=90d")->getContent(),
            'unit' => $this->actingAs($viewer)->getJson("/ems/api/supply-data/units/unit:{$r2->id}?period=90d")->getContent(),
        ];
        $path = tempnam(sys_get_temp_dir(), 'sd-pii-');
        file_put_contents($path, $this->actingAs($viewer)->get('/ems/api/supply-data/export.xlsx?period=90d')->streamedContent());
        $text = '';
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                $text .= implode(' | ', array_map('strval', $row))."\n";
            }
        }
        unlink($path);
        $bodies['export'] = $text;

        foreach ($bodies as $name => $body) {
            foreach (['Jane Q Member', 'Reviewer Rita', 'Actor Alice', 'Receiver Bob', 'Adjuster Carl', 'Test Counter', 'Test Member', 'Viewer Vera'] as $person) {
                $this->assertStringNotContainsString($person, $body, "{$name} must not carry {$person}");
            }
        }
    }

    public function test_every_endpoint_including_units_unassigned_and_filtered_exports_changes_nothing(): void
    {
        [$gloves, $r2] = $this->world();
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $this->actingAs($viewer)->getJson('/ems/api/supply-data')->assertOk(); // warm the session registry
        Queue::fake();
        Bus::fake();
        Notification::fake();
        Mail::fake();
        $snapshot = fn (): array => collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
            ->mapWithKeys(fn (string $table): array => [$table => md5((string) json_encode(DB::table($table)->get()->all()))])->all();
        $before = $snapshot();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        foreach (['/ems/api/supply-data', "/ems/api/supply-data/items/{$gloves->id}", "/ems/api/supply-data/units/unit:{$r2->id}", '/ems/api/supply-data/units/detail',
            '/ems/api/supply-data/units/unassigned', '/ems/api/supply-data/export.xlsx', '/ems/api/supply-data?period=12m&unit=detail&category=x&limit=50',
            '/ems/api/supply-data/export.xlsx?period=90d&item='.$gloves->id, '/ems/api/supply-data/export.xlsx?unit=unassigned'] as $path) {
            $this->assertSame(200, $this->actingAs($viewer)->get($path, ['Accept' => 'application/json'])->baseResponse->getStatusCode(), $path);
        }

        // The sign-in layer stamps the person's own session row (last activity) on every request; nothing else may change.
        $changed = array_keys(array_filter($snapshot(), fn (string $hash, string $table): bool => $table !== 'authentication_sessions' && $before[$table] !== $hash, ARRAY_FILTER_USE_BOTH));
        $this->assertSame([], $changed, 'no row of any table changed');
        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|create|alter|drop|truncate|savepoint|begin|commit)\b/', $sql) === 1 && ! str_contains($sql, 'authentication_sessions'));
        $this->assertSame([], array_values($writes));
        $this->assertFalse(collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'for update')));
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_f4_the_export_lists_every_supply_and_says_when_the_accuracy_sheet_is_a_selection(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $truck = $this->sdApparatus('Engine 5', 'E5');
        $counted = [];
        for ($i = 0; $i < 60; $i++) {
            $item = $this->sdItem('Bulk '.$i, ['threshold_qty_base' => 2]);
            $request = $this->sdRequest($truck, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['b', 1, 1]]);
            $this->sdIssue($item, 1, $request, '2026-10-20 09:00:00');
            if ($i < 30) {
                $counted[] = $item;
            }
        }
        $this->sdCount('2026-10-01 08:00:00', array_map(fn ($item): array => [$item, 100], $counted), 0);
        $this->sdCount('2026-10-25 08:00:00', array_map(fn ($item, int $i): array => [$item, 90 + $i % 7], $counted, array_keys($counted)), 0);
        $viewer = $this->emsAdmin(['admin.ems.view']);

        $json = $this->actingAs($viewer)->getJson('/ems/api/supply-data?limit=50')->json('data');
        $path = tempnam(sys_get_temp_dir(), 'sd-all-');
        file_put_contents($path, $this->actingAs($viewer)->get('/ems/api/supply-data/export.xlsx?limit=5')->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        $supplies = collect($book->getSheetByName('Supplies')->toArray())->skip(1);
        $this->assertSame(60, $json['headline']['items_distributed']);
        $this->assertCount(50, $json['top_items'], 'a page may show 50');
        $this->assertSame(60, $supplies->count(), 'the file lists every supply, whatever limit the page used');
        $this->assertSame($json['headline']['distribution_events'], (int) $supplies->sum(3));
        $about = collect($book->getSheetByName('About this export')->toArray())->keyBy(0);
        $this->assertStringContainsString('All 60 supplies', $about['Supplies'][1]);
        $this->assertSame(30, $json['count_accuracy']['comparable_counts']);
        $accuracy = collect($book->getSheetByName('Count accuracy')->toArray())->skip(1);
        $this->assertSame(25, $accuracy->count());
        $this->assertStringContainsString('25 largest differences of 30', $about['Count accuracy'][1]);
    }
}
