<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\InspectionPaperFields;
use Tests\TestCase;

final class CheckoutCompartmentLabelsTest extends TestCase
{
    public function test_numbered_side_compartments_follow_physical_rear_to_cab_order_without_moving_items(): void
    {
        $cases = [
            'engine' => [
                'comp_a1' => ['Driver L4', 'Side A · Compartment 1', 4, 'comp_1-item-1'],
                'comp_a2' => ['Driver L3', 'Side A · Compartment 2', 13, 'comp_2-item-1'],
                'comp_a3' => ['Driver L2', 'Side A · Compartment 3', 18, 'comp_a3-auto-pulse'],
                'comp_a4' => ['Driver L1', 'Side A · Compartment 4', 18, 'comp_4-item-9'],
                'comp_b1' => ['Officer R4', 'Side B · Compartment 1', 4, 'comp_1-item-5'],
                'comp_b2' => ['Officer R3', 'Side B · Compartment 2', 12, 'comp_2-item-12'],
                'comp_b3' => ['Officer R2', 'Side B · Compartment 3', 7, 'comp_3-item-15'],
                'comp_b4' => ['Officer R1', 'Side B · Compartment 4', 12, 'comp_4-item-1'],
            ],
            'engine2' => [
                'comp_l1' => ['Driver L4', 'Compartment L-1', 5, 'comp_l1-item-1'],
                'comp_l2' => ['Driver L3', 'Compartment L-2', 25, 'comp_l2-item-1'],
                'comp_l3' => ['Driver L2', 'Compartment L-3', 13, 'comp_l3-item-1'],
                'comp_l4' => ['Driver L1', 'Compartment L-4', 15, 'comp_l4-item-1'],
                'comp_r1' => ['Officer R4', 'Compartment R-1', 8, 'comp_r1-item-1'],
                'comp_r2' => ['Officer R3', 'Compartment R-2', 15, 'comp_r2-item-1'],
                'comp_r3' => ['Officer R2', 'Compartment R-3', 3, 'comp_r3-item-1'],
                'comp_r4' => ['Officer R1', 'Compartment R-4', 10, 'comp_r4-item-1'],
            ],
            'ladder1' => [
                'a_comp_1' => ['Driver L6', 'A - Compartment 1', 4, 'comp_1-item-1'],
                'a_comp_2' => ['Driver L5', 'A - Compartment 2', 2, 'comp_2-item-4'],
                'a_comp_3' => ['Driver L4', 'A - Compartment 3', 15, 'comp_3-item-13'],
                'a_comp_4' => ['Driver L3', 'A - Compartment 4', 16, 'comp_4-item-9'],
                'a_comp_5_6' => ['Driver L1 + L2 (combined)', 'A - Compartments 5 and 6', 13, 'comp_5_6-item-1'],
                'b_comp_1' => ['Officer R6', 'B - Compartment 1', 6, 'l1-b-comp-1-struts'],
                'b_comp_2' => ['Officer R5', 'B - Compartment 2', 3, 'comp_2-item-1'],
                'b_comp_3' => ['Officer R4', 'B - Compartment 3', 11, 'comp_3-item-1'],
                'b_comp_4' => ['Officer R3', 'B - Compartment 4', 8, 'comp_4-item-1'],
                'b_comp_5' => ['Officer R2', 'B - Compartment 5', 10, 'comp_5-item-1'],
                'b_comp_6' => ['Officer R1', 'B - Compartment 6', 6, 'comp_6-item-1'],
            ],
            'ladder3' => [
                'a_comp_1' => ['Driver L6', 'A - Compartment 1', 2, 'comp_1-item-1'],
                'a_comp_2' => ['Driver L5', 'A - Compartment 2', 2, 'comp_2-item-1'],
                'a_comp_3' => ['Driver L4', 'A - Compartment 3', 37, 'comp_3-item-1'],
                'a_comp_4' => ['Driver L3', 'A - Compartment 4', 9, 'comp_4-item-1'],
                'a_comp_5' => ['Driver L2', 'A - Compartment 5', 2, 'comp_5-item-1'],
                'a_comp_6' => ['Driver L1', 'A - Compartment 6', 11, 'comp_6-item-1'],
                'b_comp_1' => ['Officer R5', 'B - Compartment 1', 3, 'comp_1-item-3'],
                'b_comp_2' => ['Officer R4', 'B - Compartment 2', 9, 'officer_side_panel-item-11'],
                'b_comp_3' => ['Officer R3', 'B - Compartment 3', 9, 'officer_side_panel-item-4'],
                'b_comp_4' => ['Officer R2', 'B - Compartment 4', 4, 'comp_4-item-10'],
                'b_comp_5' => ['Officer R1', 'B - Compartment 5', 3, 'officer_side_panel-item-1'],
            ],
        ];

        foreach ($cases as $template => $expectedCompartments) {
            $checklist = $this->checklist($template);
            $compartments = array_column($checklist['compartments'], null, 'id');

            foreach ($expectedCompartments as $id => [$newTitle, $oldTitle, $itemCount, $firstItemId]) {
                $compartment = $compartments[$id];
                $this->assertSame($newTitle, $compartment['title'], "$template:$id title");
                $this->assertContains($oldTitle, $compartment['legacyCompartmentNames'] ?? [], "$template:$id historical alias");
                $this->assertCount($itemCount, $compartment['items'], "$template:$id item count");
                $this->assertSame($firstItemId, $compartment['items'][0]['id'], "$template:$id first item");
            }
        }
    }

    public function test_old_engine_two_compartment_names_remain_defect_aliases_after_relabeling(): void
    {
        $definitions = (new InspectionPaperFields)->equipmentDefinitions($this->checklist('engine2'));

        $this->assertSame('Driver L1', $definitions['comp_l4']['comp_l4-item-1']['compartment']);
        $this->assertContains('Compartment L-4', $definitions['comp_l4']['comp_l4-item-1']['compartment_names']);
        $this->assertSame('Officer R4', $definitions['comp_r1']['comp_r1-item-1']['compartment']);
        $this->assertContains('Compartment R-1', $definitions['comp_r1']['comp_r1-item-1']['compartment_names']);
    }

    public function test_engine_two_drill_bit_set_is_in_the_driver_door_nearest_the_cab_without_an_assumed_quantity(): void
    {
        $compartments = array_column($this->checklist('engine2')['compartments'], null, 'id');
        $items = array_column($compartments['comp_l1']['items'], null, 'id');

        $this->assertSame('Driver L4', $compartments['comp_l1']['title']);
        $this->assertSame('Drill bit set', $items['comp_l1-drill-bit-set']['name']);
        $this->assertArrayNotHasKey('expectedQuantity', $items['comp_l1-drill-bit-set']);
    }

    private function checklist(string $template): array
    {
        return json_decode(file_get_contents(base_path("storage/checklists/{$template}_checklist.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
