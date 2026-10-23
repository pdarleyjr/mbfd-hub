<?php

declare(strict_types=1);

namespace Tests\Unit\Captain5;

use App\Services\Captain5\MedicalScope;
use App\Services\InspectionPaperFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The medical mapping is a reviewed artifact. If a template changes, its reviewed_version no
 * longer matches and this test fails: someone must re-review the template item by item and
 * update config/captain5_medical_scope.php (docs/captain5-ems-operations-center.md section 4).
 */
final class MedicalScopeMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_reviewed_template_still_matches_its_reviewed_version(): void
    {
        $scope = app(MedicalScope::class);
        foreach (MedicalScope::MEDICAL_ONLY_TYPES as $type) {
            $template = $scope->baselineTemplate($type);
            self::assertIsArray($template, "{$type} template must exist");
            self::assertSame(
                config("captain5_medical_scope.{$type}.reviewed_version"),
                MedicalScope::canonicalVersion($template),
                "The {$type} checklist changed: re-review config/captain5_medical_scope.php and update reviewed_version.",
            );
        }
    }

    public function test_every_mapped_item_exists_and_no_item_is_both_medical_and_ambiguous(): void
    {
        $scope = app(MedicalScope::class);
        foreach (MedicalScope::MEDICAL_ONLY_TYPES as $type) {
            $ids = [];
            foreach (app(InspectionPaperFields::class)->equipmentDefinitions($scope->baselineTemplate($type)) as $items) {
                foreach (array_keys($items) as $id) {
                    $ids[] = (string) $id;
                }
            }
            $medical = (array) config("captain5_medical_scope.{$type}.medical");
            $ambiguous = (array) config("captain5_medical_scope.{$type}.ambiguous");
            self::assertNotEmpty($medical, "{$type} must have medical items");
            foreach ([...$medical, ...$ambiguous] as $id) {
                self::assertContains($id, $ids, "{$type}: mapped item {$id} does not exist in the template");
            }
            self::assertSame([], array_values(array_intersect($medical, $ambiguous)), "{$type}: an item cannot be both");
            self::assertSame(count($medical), count(array_unique($medical)));
        }
    }

    public function test_classification_is_a_lookup_never_a_keyword_match(): void
    {
        $scope = app(MedicalScope::class);
        self::assertSame(MedicalScope::MEDICAL, $scope->classify('engine', 'comp_4-item-1'));        // LifePak
        self::assertSame(MedicalScope::MEDICAL, $scope->classify('ladder3', 'officer_side_panel-item-4'));
        self::assertSame(MedicalScope::NOT_MEDICAL, $scope->classify('engine', 'front_cab-item-1'));   // Toughbook
        self::assertSame(MedicalScope::NOT_MEDICAL, $scope->classify('ladder1', 'comp_4-item-1'));      // CO ID# (gas monitor)
        self::assertSame(MedicalScope::UNCLASSIFIED, $scope->classify('engine', 'comp_b4-water-cooler-cups'));
        self::assertSame(MedicalScope::UNCLASSIFIED, $scope->classify('engine2', 'inv-c42'), 'Inventory additions wait for a decision');
        self::assertSame(MedicalScope::UNCLASSIFIED, $scope->classify('engine', null));
        self::assertSame(MedicalScope::UNCLASSIFIED, $scope->classify('rescue', 'front_cab-item-1'), 'Rescue is not a medical-only template');
        // Same item id means different equipment in another template: lookups are per template.
        self::assertSame(MedicalScope::NOT_MEDICAL, $scope->classify('ladder3', 'comp_4-item-1'));      // Res-Q Jacks
    }
}
