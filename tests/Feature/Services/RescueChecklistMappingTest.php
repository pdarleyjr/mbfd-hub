<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\InspectionPaperFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RescueChecklistMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rescue_source_side_split_preserves_all_legacy_item_ids_once(): void
    {
        $checklist = $this->checklist();
        $groups = array_column($checklist['compartments'], null, 'id');
        $expected = [
            'front_cab' => ['front_cab-item-1', 'front_cab-item-2', 'front_cab-item-3', 'front_cab-item-4', 'front_cab-item-5'],
            'compartment_d' => ['compartment_d-item-1', 'compartment_d-item-4', 'compartment_d-item-6', 'compartment_d-item-8', 'compartment_d-item-17'],
            'compartment_c' => ['compartment_c-item-1', 'compartment_c-item-2', 'compartment_c-item-3', 'compartment_c-item-4', 'compartment_c-item-5', 'compartment_c-item-6', 'compartment_c-item-7'],
            'compartment_b' => ['patient_compartment-item-9', 'patient_compartment-item-12', 'patient_compartment-item-15', 'patient_compartment-item-18', 'patient_compartment-item-20', 'compartment_b-item-6', 'compartment_b-item-7', 'compartment_b-item-8', 'compartment_b-item-9', 'compartment_b-item-10', 'compartment_b-item-11'],
            'compartment_a' => ['compartment_a-item-1', 'compartment_a-item-2', 'compartment_a-item-3', 'compartment_a-item-4'],
            'officer_compartment_d' => ['compartment_d-item-2', 'compartment_d-item-3', 'compartment_d-item-5', 'compartment_d-item-7', 'compartment_d-item-9', 'compartment_d-item-10', 'compartment_d-item-12', 'compartment_d-item-14'],
            'officer_compartment_c' => ['compartment_c-item-12', 'compartment_c-item-13', 'compartment_c-item-14', 'compartment_c-item-15', 'compartment_c-item-16'],
            'officer_compartment_b' => ['compartment_b-item-1', 'compartment_b-item-2', 'compartment_b-item-3', 'compartment_b-item-4', 'compartment_b-item-5', 'compartment_b-item-13'],
            'officer_compartment_a' => ['compartment_a-item-5', 'compartment_a-item-6', 'patient_compartment-item-6', 'patient_compartment-item-8', 'patient_compartment-item-11', 'patient_compartment-item-13', 'patient_compartment-item-14', 'patient_compartment-item-17', 'patient_compartment-item-19', 'patient_compartment-item-22', 'compartment_d-item-15'],
            'patient_compartment' => ['patient_compartment-item-1', 'patient_compartment-item-2', 'patient_compartment-item-3', 'patient_compartment-item-4', 'patient_compartment-item-5', 'patient_compartment-item-7', 'patient_compartment-item-10', 'patient_compartment-item-16', 'patient_compartment-item-21', 'compartment_d-item-16'],
            'stretcher' => ['stretcher-item-1', 'stretcher-item-2', 'compartment_b-item-12', 'compartment_c-item-8', 'compartment_c-item-9', 'compartment_c-item-10', 'compartment_c-item-11'],
            'radio_numbers' => ['radio_numbers-item-1', 'radio_numbers-item-2', 'radio_numbers-item-3', 'compartment_d-item-11', 'compartment_d-item-13'],
        ];

        $actualIds = [];
        foreach ($expected as $groupId => $expectedIds) {
            $this->assertArrayHasKey($groupId, $groups);
            $this->assertSame($expectedIds, array_map(static fn (array $item, int $index): string => $item['id'] ?? $groupId.'-item-'.($index + 1), $groups[$groupId]['items'], array_keys($groups[$groupId]['items'])), $groupId);
            $actualIds = [...$actualIds, ...$expectedIds];
        }

        $legacyIds = [];
        foreach (['front_cab' => 5, 'compartment_a' => 6, 'patient_compartment' => 22, 'compartment_b' => 13, 'stretcher' => 2, 'compartment_c' => 16, 'compartment_d' => 17, 'radio_numbers' => 3] as $groupId => $count) {
            for ($index = 1; $index <= $count; $index++) {
                $legacyIds[] = $groupId.'-item-'.$index;
            }
        }
        sort($legacyIds);
        sort($actualIds);
        $this->assertCount(84, $actualIds);
        $this->assertSame($legacyIds, $actualIds);
        $this->assertCount(count($actualIds), array_unique($actualIds));
    }

    public function test_old_compartment_names_match_exactly_one_new_physical_side_by_item(): void
    {
        $checklist = $this->checklist();
        $definitions = app(InspectionPaperFields::class)->equipmentDefinitions($checklist);
        $matcher = app(InspectionPaperFields::class);
        $oldTitles = [
            'front_cab' => 'Front Cab',
            'compartment_a' => 'Compartment A',
            'compartment_b' => 'Compartment B',
            'compartment_c' => 'Compartment C',
            'compartment_d' => 'Compartment D',
            'patient_compartment' => 'Patient Compartment',
            'stretcher' => 'Stretcher',
            'radio_numbers' => 'Radio Numbers',
        ];
        foreach ($checklist['compartments'] as $group) {
            foreach ($group['items'] as $index => $item) {
                $itemId = $item['id'] ?? $group['id'].'-item-'.($index + 1);
                $oldGroup = substr($itemId, 0, strrpos($itemId, '-item-'));
                $matches = $matcher->matchingEquipment($definitions, $oldTitles[$oldGroup], $item['name']);
                $this->assertCount(1, $matches, $oldTitles[$oldGroup].' / '.$item['name']);
                $this->assertSame($group['title'], $matches[0]['compartment']);
            }
        }

        foreach ([
            ['Compartment A', 'M Cylinders', 'L4 · Driver compartment'],
            ['Compartment A', 'HANDTEVY', 'R4 · Officer compartment'],
            ['Compartment B', 'Dewalt K-Saw with Spare battery', 'L3 · Driver compartment'],
            ['Compartment B', 'Dive Mask & Snorkel', 'R3 · Officer compartment'],
            ['Compartment C', 'Spanner Wrench', 'L2 · Driver compartment'],
            ['Compartment C', 'Vinegar', 'R2 · Officer compartment'],
            ['Compartment D', 'PAR Tags', 'L1 · Driver compartment'],
            ['Compartment D', 'KED', 'R1 · Officer compartment'],
            ['Patient Compartment', 'MCI Bag', 'L3 · Driver compartment'],
            ['Compartment D', '(3) Ballistic vests and (3) helmets', 'R4 · Officer compartment'],
        ] as [$oldGroup, $item, $currentGroup]) {
            $matches = $matcher->matchingEquipment($definitions, $oldGroup, $item);
            $this->assertCount(1, $matches, $oldGroup.' / '.$item);
            $this->assertSame($currentGroup, $matches[0]['compartment']);
        }
    }

    public function test_old_rescue_finding_reuses_one_defect_after_side_correction(): void
    {
        Queue::fake();
        $checklist = $this->checklist();
        $apparatus = Apparatus::create([
            'name' => 'Rescue 1', 'unit_id' => 'R1', 'designation' => 'R1', 'type' => 'Rescue',
            'vehicle_number' => 'TEST-R1', 'status' => 'In Service',
        ]);
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Compartment A', 'HANDTEVY', 'Missing', null, null, null);
        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id, 'operator_name' => 'Test Member', 'rank' => 'Captain',
            'review_status' => 'pending_review',
            'results' => [['id' => 'officer_compartment_a', 'name' => 'R4 · Officer compartment', 'items' => [[
                'id' => 'compartment_a-item-5', 'name' => 'HANDTEVY', 'status' => 'Damaged',
            ]]]],
            'pending_effects' => ['defects' => [['compartment' => 'R4 · Officer compartment', 'item' => 'HANDTEVY', 'status' => 'Damaged']]],
        ]);

        DB::transaction(fn () => app(ApparatusInspectionProcessingService::class)->process($inspection, $apparatus, null, $checklist));

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertSame('damaged', $defect->fresh()->issue_type);
        $this->assertSame('Compartment A', $defect->fresh()->compartment);
        $this->assertDatabaseHas('apparatus_defect_observations', [
            'apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id,
            'observation' => 'confirmed_again', 'reported_status' => 'Damaged',
        ]);
        $this->assertSame('In Service', $apparatus->fresh()->status);
        $this->assertSame(1, ApparatusDefectObservation::query()->count());
    }

    private function checklist(): array
    {
        return json_decode(file_get_contents(base_path('storage/checklists/rescue_checklist.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
