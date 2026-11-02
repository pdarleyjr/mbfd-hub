<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Models\User;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Ems\CountMath;
use App\Services\Ems\EmsActor;
use App\Services\Ems\Import\SupplyEstimateLoader;
use App\Services\Ems\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Best-guess numbers and who may keep the master inventory (docs/ems/captain5-medical-inventory-workflow.md sections 6 and 9).
 * Fixture rows are the BuildsEmsWorld supply items: sr.t.01 Gauze 4x4 (box of 200, A2), sr.t.02 Tape 1 inch (box of 24, A2),
 * sr.t.03 Stethoscope (each, B1), sr.t.04 Gloves Medium (unknown pack, C1).
 */
final class SupplyEstimatesTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ems-estimates-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        File::delete($this->path);
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @param array<string, mixed> $over */
    private function writeFile(array $over = []): void
    {
        File::put($this->path, json_encode($over + [
            'note' => 'Test guesses.',
            'locations' => [['code' => 'OFFICE', 'label' => 'Capt 5 Office', 'sort' => 455]],
            'estimates' => [
                ['name' => 'Gloves Medium', 'typed' => 12, 'set' => ['count_unit' => 'box', 'package_unit' => 'box', 'units_per_package' => 50, 'threshold_qty_base' => 100, 'threshold_source_text' => '< 2 boxes', 'review_reasons' => []]],
                ['name' => 'Stethoscope', 'typed' => 3, 'location_code' => 'OFFICE', 'set' => ['section_key' => 'stored-with-captain-5']],
            ],
            'moves' => [['name' => 'Tape 1 inch', 'location_code' => 'OFFICE', 'set' => ['name' => 'Tape One Inch']]],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function masterRow(User $user, int $itemId): array
    {
        return collect($this->actingAs($user)->getJson('/ems/api/supply/master')->assertOk()->json('data.items'))->firstWhere('id', $itemId);
    }

    public function test_a_dry_run_changes_nothing_and_says_what_it_would_load(): void
    {
        $this->writeFile();
        $before = InventorySnapshot::query()->count();

        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path])->expectsOutputToContain('Dry run only')->assertSuccessful();

        $this->assertSame($before, InventorySnapshot::query()->count());
        $this->assertSame(0, SupplyItemEvent::query()->where('reason', SupplyEstimateLoader::REASON)->count());
        $this->assertNull(StorageLocation::query()->where('code', 'OFFICE')->first());
        $this->assertSame('each', $this->supplyItem('sr.t.03')->count_unit);
        $this->assertNotSame(100, $this->supplyItem('sr.t.04')->threshold_qty_base);
    }

    public function test_guesses_load_marked_as_estimates_with_their_settings_shelf_and_history(): void
    {
        $this->writeFile();
        $captain = $this->emsCaptain();

        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->assertSuccessful();

        $gloves = $this->supplyItem('sr.t.04');
        $row = $this->masterRow($captain, $gloves->id);
        $this->assertSame(12, $row['on_hand']);
        $this->assertSame(600, $row['on_hand_base']);
        $this->assertSame('box', $row['count_unit']);
        $this->assertTrue($row['estimated']);
        $this->assertSame('Order when fewer than 2 boxes', $row['order_rule_text']);
        $this->assertSame([], $row['review_reasons']);
        $this->assertFalse($row['needs_review']);

        $stethoscope = $this->masterRow($captain, $this->supplyItem('sr.t.03')->id);
        $this->assertSame('OFFICE', $stethoscope['location_code']);
        $this->assertSame('Stored with Captain 5', $stethoscope['section_label']);

        $tape = SupplyItem::query()->where('source_row_key', 'sr.t.02')->first();
        $this->assertSame('Tape One Inch', $tape->name);
        $this->assertNull($this->masterRow($captain, $tape->id)['on_hand'], 'A move never invents a number.');

        $snapshot = InventorySnapshot::query()->where('origin', InventorySnapshot::ORIGIN_ESTIMATE)->sole();
        $this->assertSame(InventorySnapshot::KIND_BASELINE, $snapshot->kind);
        $this->assertTrue($snapshot->isFinal());
        $this->assertSame(SupplyEstimateLoader::SNAPSHOT_NAME, $snapshot->finalized_name);
        $this->assertTrue(SupplyItemEvent::query()->where('supply_item_id', $gloves->id)->where('field', 'units_per_package')->where('reason', SupplyEstimateLoader::REASON)->exists());
    }

    public function test_applying_the_same_file_twice_changes_nothing_the_second_time(): void
    {
        $this->writeFile();
        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->assertSuccessful();
        $events = SupplyItemEvent::query()->count();

        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->expectsOutputToContain('already applied')->assertSuccessful();

        $this->assertSame(1, InventorySnapshot::query()->where('origin', InventorySnapshot::ORIGIN_ESTIMATE)->count());
        $this->assertSame($events, SupplyItemEvent::query()->count());
    }

    public function test_an_item_somebody_already_counted_keeps_its_number_and_settings(): void
    {
        $captain = $this->emsCaptain();
        $gloves = $this->supplyItem('sr.t.04');
        $this->emsPost($captain, "/ems/api/supply/master/items/{$gloves->id}/count", ['on_hand' => 7])->assertOk();
        $this->writeFile();

        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->expectsOutputToContain('Gloves Medium')->assertSuccessful();

        $row = $this->masterRow($captain, $gloves->id);
        $this->assertSame(7, $row['on_hand']);
        $this->assertFalse($row['estimated']);
        $this->assertSame('each', $gloves->fresh()->count_unit);
    }

    public function test_a_number_typed_into_the_open_count_is_not_overwritten(): void
    {
        $captain = $this->emsCaptain();
        $gloves = $this->supplyItem('sr.t.04');
        $count = $this->emsPost($captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $gloves->id, 'on_hand' => 4]]], ['Idempotency-Key' => 'save-'.Str::random(12)])->assertOk();
        $this->writeFile();

        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->assertSuccessful();

        $this->assertSame('each', $gloves->fresh()->count_unit);
        $this->assertNull($this->masterRow($captain, $gloves->id)['on_hand']);
    }

    public function test_a_name_that_is_not_on_the_list_stops_the_whole_load(): void
    {
        $this->writeFile(['estimates' => [
            ['name' => 'Stethoscope', 'typed' => 3],
            ['name' => 'Not An Item', 'typed' => 1],
        ]]);

        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->expectsOutputToContain('Not An Item')->assertFailed();

        $this->assertSame(0, InventorySnapshot::query()->where('origin', InventorySnapshot::ORIGIN_ESTIMATE)->count());
        $this->assertNull(StorageLocation::query()->where('code', 'OFFICE')->first());
    }

    public function test_the_monday_count_replaces_a_guess_and_a_blank_leaves_it(): void
    {
        $this->writeFile();
        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->assertSuccessful();
        $captain = $this->emsCaptain();
        $gloves = $this->supplyItem('sr.t.04');
        $stethoscope = $this->supplyItem('sr.t.03');

        $count = $this->emsPost($captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $gloves->id, 'on_hand' => 9]]], ['Idempotency-Key' => 'save-'.Str::random(12)])->assertOk();
        $missing = count($this->actingAs($captain)->getJson("/ems/api/supply/count-form/{$count}/review")->assertOk()->json('data.uncounted'));
        $this->emsPost($captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => $missing])->assertOk();

        $counted = $this->masterRow($captain, $gloves->id);
        $this->assertSame(9, $counted['on_hand']);
        $this->assertSame(450, $counted['on_hand_base']);
        $this->assertFalse($counted['estimated']);

        $blank = $this->masterRow($captain, $stethoscope->id);
        $this->assertSame(3, $blank['on_hand']);
        $this->assertTrue($blank['estimated']);
    }

    public function test_typing_the_real_number_under_edit_replaces_a_guess(): void
    {
        $this->writeFile();
        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->assertSuccessful();
        $captain = $this->emsCaptain();
        $stethoscope = $this->supplyItem('sr.t.03');

        $this->emsPost($captain, "/ems/api/supply/master/items/{$stethoscope->id}/count", ['on_hand' => 8])->assertOk()->assertJsonPath('data.estimated', false)->assertJsonPath('data.on_hand', 8);
    }

    public function test_stock_taken_after_a_guess_shows_in_red_and_the_next_count_clears_it(): void
    {
        $this->writeFile();
        $this->artisan('ems:apply-supply-estimates', ['--file' => $this->path, '--apply' => true])->assertSuccessful();
        $captain = $this->emsCaptain();
        $stethoscope = $this->supplyItem('sr.t.03');

        app(StockLedger::class)->record($stethoscope, 'issued', -2, EmsActor::forUser($captain, 'captain5'), 'test:estimate-issue', 'Taken for a rescue');

        $row = $this->masterRow($captain, $stethoscope->id);
        $this->assertSame(1, $row['on_hand']);
        $this->assertSame(2, $row['deducted']);
        $this->assertTrue($row['estimated']);

        $this->emsPost($captain, "/ems/api/supply/master/items/{$stethoscope->id}/count", ['on_hand' => 5])->assertOk();
        $row = $this->masterRow($captain, $stethoscope->id);
        $this->assertSame(5, $row['on_hand']);
        $this->assertSame(0, $row['deducted']);
    }

    public function test_the_real_file_is_well_formed(): void
    {
        $doc = json_decode((string) file_get_contents(database_path('data/ems/supply_room_estimates_20261101.json')), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($doc['estimates'], 'name');
        $this->assertCount(44, $names);
        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($doc['estimates'] as $entry) {
            $this->assertIsInt($entry['typed'], $entry['name']);
            $this->assertNotSame('', $entry['basis'], $entry['name']);
            if (isset($entry['set']['count_unit'])) {
                $this->assertSame($entry['set']['count_unit'], $entry['set']['package_unit'], $entry['name']);
                $this->assertGreaterThan(0, $entry['set']['units_per_package'], $entry['name']);
                $this->assertSame(0, $entry['set']['threshold_qty_base'] % $entry['set']['units_per_package'], $entry['name'].' order level must be whole packages, the edit form only takes whole numbers.');
                $this->assertLessThanOrEqual(StockLedger::MAX_BASE, $entry['typed'] * $entry['set']['units_per_package'], $entry['name']);
                $probe = (new SupplyItem)->forceFill(['name' => $entry['name']] + $entry['set']);
                CountMath::assertConvertible($probe);
            }
        }
        foreach ($doc['estimates'] as $entry) {
            if (isset($entry['location_code'])) {
                $this->assertContains($entry['location_code'], array_column($doc['locations'], 'code'));
            }
        }
    }

    // ------------------------------------------------------------------ who may keep the master inventory

    public function test_any_administrator_can_keep_the_master_inventory_and_run_the_count_but_not_the_forms(): void
    {
        $admin = $this->emsAdmin([]);
        $item = $this->supplyItem('sr.t.03');

        $meta = $this->actingAs($admin)->getJson('/ems/api/meta')->assertOk();
        $meta->assertJsonPath('data.permissions.view', false)->assertJsonPath('data.permissions.act', false);
        $meta->assertJsonPath('data.inventory.read', true)->assertJsonPath('data.inventory.edit', true);
        $this->actingAs($admin)->getJson('/ems/api/supply/master')->assertOk()->assertJsonPath('data.can_edit', true);

        $this->emsPost($admin, "/ems/api/supply/master/items/{$item->id}/count", ['on_hand' => 4])->assertOk();
        $this->actingAs($admin)->patchJson("/ems/api/supply/master/items/{$item->id}", ['name' => 'Stethoscope Adult'], ['Idempotency-Key' => 'rename-'.Str::random(12)])->assertOk()->assertJsonPath('data.name', 'Stethoscope Adult');
        $count = $this->emsPost($admin, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($admin)->getJson("/ems/api/supply/count-form/{$count}/review")->assertOk();

        $this->actingAs($admin)->getJson('/ems/api/requests')->assertForbidden();
        $this->emsPost($admin, '/ems/api/forms/reconcile', ['request_ids' => [], 'expected_last_movement_id' => 0])->assertForbidden();
    }

    public function test_an_administrator_can_save_submit_and_discard_a_count(): void
    {
        $admin = $this->emsAdmin([]);
        $item = $this->supplyItem('sr.t.03');
        $headers = fn (): array => ['Idempotency-Key' => 'admin-'.Str::random(12)];

        $discarded = $this->emsPost($admin, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($admin)->deleteJson("/ems/api/supply/counts/{$discarded}", [], $headers())->assertOk();
        $this->assertNull(InventorySnapshot::query()->where('uuid', $discarded)->first());

        $count = $this->emsPost($admin, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($admin)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $item->id, 'on_hand' => 6]]], $headers())->assertOk();
        $missing = count($this->actingAs($admin)->getJson("/ems/api/supply/count-form/{$count}/review")->assertOk()->json('data.uncounted'));
        $this->emsPost($admin, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => $missing])->assertOk();

        $this->assertSame(6, $this->masterRow($admin, $item->id)['on_hand']);
    }

    public function test_a_captain_5_deny_override_also_ends_an_administrators_inventory_access(): void
    {
        $admin = $this->emsAdmin([]);
        app(Captain5AdministrationService::class)->setOverride($this->emsSuperAdmin(), (int) $admin->employee_profile_id, 'deny', 'Under review');
        $this->forgetEmsMemo();
        $item = $this->supplyItem('sr.t.03');

        $this->actingAs($admin->fresh())->getJson('/ems/api/supply/master')->assertForbidden();
        $this->emsPost($admin->fresh(), "/ems/api/supply/master/items/{$item->id}/count", ['on_hand' => 4])->assertForbidden();
    }

    public function test_a_read_only_grant_and_a_plain_member_stay_out_of_editing(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $member = $this->emsMember();
        $item = $this->supplyItem('sr.t.03');

        $this->actingAs($viewer)->getJson('/ems/api/meta')->assertOk()->assertJsonPath('data.inventory.edit', false);
        $this->emsPost($viewer, "/ems/api/supply/master/items/{$item->id}/count", ['on_hand' => 4])->assertForbidden();
        $this->actingAs($member)->getJson('/ems/api/meta')->assertForbidden();
        $this->actingAs($member)->getJson('/ems/api/supply/master')->assertForbidden();
        $this->emsPost($member, "/ems/api/supply/master/items/{$item->id}/count", ['on_hand' => 4])->assertForbidden();
    }
}
