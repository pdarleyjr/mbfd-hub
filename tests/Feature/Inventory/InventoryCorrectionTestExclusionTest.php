<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\ApparatusInspection;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use App\Services\Inspections\ApparatusInspectionTestClassificationService;
use App\Services\Inventory\InventoryChangePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

final class InventoryCorrectionTestExclusionTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
    }

    public function test_an_excluded_inspection_rejects_a_late_report_without_retaining_a_photo_or_operational_work(): void
    {
        $draft = (string) Str::uuid();
        $inspection = $this->inspection($draft);
        app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, $this->logistics(), 'Approved test.');

        $this->report('E1', [...$this->reportData($draft), 'photo' => self::PNG])
            ->assertUnprocessable()->assertJsonValidationErrors('inspection');

        $this->assertDatabaseCount('apparatus_inventory_corrections', 0);
        $this->assertDatabaseCount('apparatus_inventory_correction_events', 0);
        $this->assertDatabaseCount('apparatus_inventory_changes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('inventory-corrections'));
    }

    public function test_an_excluded_inspection_cannot_be_linked_from_a_stale_instance(): void
    {
        $draft = (string) Str::uuid();
        $correction = $this->reported('E1', $this->reportData($draft));
        $inspection = $this->inspection($draft);
        // Represent retained legacy data that predates the exclusion integration guard.
        $inspection->fresh()->forceFill(['test_excluded_at' => now()])->save();
        $events = $correction->events()->count();

        $this->assertExcluded(fn () => $this->service()->linkInspection($inspection));

        $this->assertNull($correction->fresh()->apparatus_inspection_id);
        $this->assertSame($events, $correction->events()->count());
    }

    #[DataProvider('linkStates')]
    public function test_a_stale_approval_cannot_apply_inventory_from_a_linked_or_draft_correlated_excluded_inspection(bool $linked): void
    {
        $draft = (string) Str::uuid();
        $correction = $this->reported('E1', $this->reportData($draft));
        $inspection = $this->inspection($draft);
        if ($linked) {
            $this->service()->linkInspection($inspection);
        }
        $reviewer = $this->logistics();
        $plan = $this->service()->preview($correction->id, $reviewer, ['op' => 'remove'], InventoryChangePlanner::SCOPE_UNIT);
        $version = $this->resolved('E1')['checklist_version'];
        $events = $correction->events()->count();
        $inspection->forceFill(['test_excluded_at' => now()])->save();

        $this->assertExcluded(fn () => $this->service()->approve(
            $correction->id, $reviewer, ['op' => 'remove'], InventoryChangePlanner::SCOPE_UNIT,
            [$this->units['E1']->id], $plan['fingerprint'], $correction->fresh()->lock_version, (string) Str::uuid(),
        ));

        $this->assertSame(ApparatusInventoryCorrection::STATUS_SUBMITTED, $correction->fresh()->status);
        $this->assertSame($version, $this->resolved('E1')['checklist_version']);
        $this->assertSame($events, $correction->events()->count());
        $this->assertDatabaseCount('apparatus_inventory_change_sets', 0);
        $this->assertDatabaseCount('apparatus_inventory_changes', 0);
        $this->assertDatabaseCount('apparatus_checklist_revisions', 0);
    }

    #[DataProvider('linkStates')]
    public function test_classification_refuses_linked_or_unlinked_draft_corrections(bool $linked): void
    {
        $draft = (string) Str::uuid();
        $correction = $this->reported('E1', $this->reportData($draft));
        $inspection = $this->inspection($draft);
        if ($linked) {
            $this->service()->linkInspection($inspection);
        }

        try {
            app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, $this->logistics(), 'Cannot hide operational corrections.');
            self::fail('An inspection with an inventory correction must retain its operational classification.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('inspection', $exception->errors());
        }

        $this->assertNull($inspection->fresh()->test_excluded_at);
        $this->assertSame(ApparatusInventoryCorrection::STATUS_SUBMITTED, $correction->fresh()->status);
        $this->assertDatabaseCount('apparatus_inspection_test_events', 0);
    }

    public function test_normal_late_link_approval_and_reversal_work_but_an_excluded_parent_cannot_reverse_stock(): void
    {
        $draft = (string) Str::uuid();
        $inspection = $this->inspection($draft);
        $original = $inspection->getRawOriginal();
        $correction = $this->reported('E1', $this->reportData($draft));
        $this->assertSame($inspection->id, $correction->apparatus_inspection_id);
        $reviewer = $this->logistics();
        $this->approve($correction, $reviewer, ['op' => 'remove']);
        $this->assertFalse($this->hasItem('E1', $correction->item_key));
        $version = $this->resolved('E1')['checklist_version'];
        $events = $correction->events()->count();

        $inspection->forceFill(['test_excluded_at' => now()])->save();
        $this->assertExcluded(fn () => $this->service()->reverse($correction->id, $reviewer, 'Must restore inspection first.'));
        $this->assertSame(ApparatusInventoryCorrection::STATUS_APPLIED, $correction->fresh()->status);
        $this->assertSame($version, $this->resolved('E1')['checklist_version']);
        $this->assertSame($events, $correction->events()->count());
        $this->assertSame(ApparatusInventoryChange::STATUS_ACTIVE, $correction->changes()->sole()->status);

        app(ApparatusInspectionTestClassificationService::class)->restore($inspection->id, $reviewer, 'Restore retained operational evidence.');
        $this->service()->reverse($correction->id, $reviewer, 'Correct the real checklist.');
        $this->assertTrue($this->hasItem('E1', $correction->item_key));
        $this->assertSame(ApparatusInventoryCorrection::STATUS_REVERSED, $correction->fresh()->status);
        foreach (['results', 'officer_signature', 'completed_at', 'inventory_draft_id', 'actor_user_id'] as $field) {
            $this->assertSame($original[$field], $inspection->fresh()->getRawOriginal($field), $field);
        }
    }

    public static function linkStates(): array
    {
        return ['linked inspection' => [true], 'draft awaiting linkage' => [false]];
    }

    private function inspection(string $draft): ApparatusInspection
    {
        return ApparatusInspection::create([
            'apparatus_id' => $this->units['E1']->id, 'actor_user_id' => $this->member->id,
            'inventory_draft_id' => $draft, 'operator_name' => 'Inventory Reporter', 'rank' => 'Firefighter',
            'review_status' => 'approved', 'processing_status' => null, 'completed_at' => now(),
            'results' => [], 'officer_signature' => 'inspection-signatures/retained-test-source.png',
        ]);
    }

    private function reportData(string $draft): array
    {
        $item = $this->item('E1', 'Passports');

        return ['reason' => 'no_longer_carried', 'compartment_id' => $item['compartment_id'], 'item_id' => $item['item_id'], 'inspection_draft_id' => $draft];
    }

    private function assertExcluded(callable $operation): void
    {
        try {
            $operation();
            self::fail('An excluded inspection must not create inventory work.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('inspection', $exception->errors());
        }
    }
}
