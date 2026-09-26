<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\InspectionPaperFields;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InspectionPaperFieldsTest extends TestCase
{
    #[DataProvider('templates')]
    public function test_actual_templates_accept_frontend_identifiers_and_preserve_issued_field_metadata(string $template): void
    {
        $checklist = $this->checklist($template);
        $submission = $this->submission($checklist);
        $service = new InspectionPaperFields;
        $service->validate($submission, $checklist);

        $expected = [];
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] ?? [] as $field) {
            $expected[] = [
                'id' => $field['id'], 'name' => $field['name'], 'input_type' => $field['inputType'],
                'value' => $this->value($field['inputType']),
            ];
        }
        $this->assertSame($expected, $service->snapshot($submission, $checklist));
    }

    public static function templates(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'default', 'engine', 'engine2', 'ladder1', 'ladder3', 'fireboat6', 'rescue',
        ]);
    }

    public function test_paper_additions_are_optional_and_accept_blank_values_without_losing_their_ids(): void
    {
        $expectedIds = [
            'engine' => ['scba_5', 'radio_fi3', 'voice_amp_num'],
            'engine2' => ['scba_5'],
            'ladder1' => ['inspection_date', 'radio_oic', 'radio_fi3'],
            'ladder3' => ['inspection_date', 'radio_oic', 'personnel_oic', 'personnel_de', 'personnel_ff1', 'personnel_ff2'],
            'fireboat6' => ['fb6-driver-crew-signoff', 'fb6-engineer-crew-signoff', 'fb6-officer-crew-signoff', 'fb6-deck-hand-crew-signoff'],
        ];
        foreach ($expectedIds as $template => $ids) {
            if ($template !== 'fireboat6') {
                $ids = [...$ids, 'checkout_type', 'changeover_equipment_removed', 'new_damage_description', 'new_damage_location'];
            }
            $checklist = $this->checklist($template);
            $definitions = array_column($checklist['fields'] ?? $checklist['officerChecklist'], null, 'id');
            foreach ($ids as $id) {
                $this->assertArrayHasKey($id, $definitions, $template);
                $this->assertFalse($definitions[$id]['required'] ?? false, $id);
            }
            foreach ([null, ''] as $blank) {
                $submission = $this->submission($checklist);
                foreach ($submission['field_values'] as &$answer) {
                    if (! ($definitions[$answer['id']]['required'] ?? false)) {
                        $answer['value'] = $blank;
                    }
                }
                unset($answer);
                $service = new InspectionPaperFields;
                $service->validate($submission, $checklist);
                $this->assertSame(array_column($submission['field_values'], 'value'), array_column($service->snapshot($submission, $checklist), 'value'));
            }
        }
    }

    #[DataProvider('invalidFieldValues')]
    public function test_invalid_field_values_are_rejected(string $template, string $id, mixed $value): void
    {
        $checklist = $this->checklist($template);
        $submission = $this->submission($checklist);
        foreach ($submission['field_values'] as &$answer) {
            if ($answer['id'] === $id) {
                $answer['value'] = $value;
            }
        }
        unset($answer);
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public static function invalidFieldValues(): array
    {
        return [
            'text exceeds limit' => ['engine2', 'scba_5', str_repeat('x', 2001)],
            'text is array' => ['engine2', 'scba_5', ['0012']],
            'text is number' => ['engine2', 'scba_5', 12],
            'whitespace is not a reading' => ['engine2', 'engine_temp', '   '],
            'required value is blank' => ['engine2', 'air_pressure_front', null],
            'numeric string is not a number' => ['engine2', 'mileage', '1200'],
            'non-finite number' => ['engine2', 'mileage', INF],
            'number exceeds limit' => ['engine2', 'mileage', 1000000000],
            'percentage above range' => ['ladder3', 'fuel', 101],
            'percentage below range' => ['ladder3', 'fuel', -1],
            'checkbox is string' => ['fireboat6', 'fb6-mbfd-radio-test', 'true'],
            'date has wrong format' => ['fireboat6', 'inspection_date', '09/21/2026'],
            'date does not exist' => ['fireboat6', 'inspection_date', '2026-02-30'],
        ];
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_wrong_duplicate_and_omitted_field_identifiers_are_rejected(string $change): void
    {
        $checklist = $this->checklist('engine2');
        $submission = $this->submission($checklist);
        if ($change === 'wrong') {
            $submission['field_values'][0]['id'] = 'unissued-field';
        } elseif ($change === 'duplicate') {
            $submission['field_values'][] = $submission['field_values'][0];
        } else {
            array_pop($submission['field_values']);
        }
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public static function invalidIdentifiers(): array
    {
        return [['wrong'], ['duplicate'], ['omitted']];
    }

    #[DataProvider('invalidItemObservations')]
    public function test_present_defaults_and_invalid_item_identifiers_cannot_replace_member_observation(string $change): void
    {
        $checklist = $this->checklist('engine2');
        $submission = $this->submission($checklist);
        if ($change === 'unobserved') {
            $submission['compartments'][0]['items'][0]['observed'] = false;
        } elseif ($change === 'omitted observation') {
            unset($submission['compartments'][0]['items'][0]['observed']);
        } elseif ($change === 'wrong id') {
            $submission['compartments'][0]['items'][0]['id'] = 'unissued-item';
        } else {
            $submission['compartments'][0]['items'][] = $submission['compartments'][0]['items'][0];
        }
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public static function invalidItemObservations(): array
    {
        return [['unobserved'], ['omitted observation'], ['wrong id'], ['duplicate id']];
    }

    public function test_present_typed_compartment_item_requires_its_identifier(): void
    {
        $checklist = $this->checklist('ladder1');
        $submission = $this->submission($checklist);
        $index = array_search('scba_radio', array_column($submission['compartments'], 'id'), true);
        $submission['compartments'][$index]['items'][0]['value'] = null;
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public function test_missing_typed_compartment_item_does_not_require_a_fabricated_identifier(): void
    {
        $checklist = $this->checklist('ladder1');
        $submission = $this->submission($checklist);
        $index = array_search('scba_radio', array_column($submission['compartments'], 'id'), true);
        $submission['compartments'][$index]['items'][0]['status'] = 'Missing';
        $submission['compartments'][$index]['items'][0]['value'] = null;
        (new InspectionPaperFields)->validate($submission, $checklist);
        $this->assertNull($submission['compartments'][$index]['items'][0]['value']);
    }

    public function test_snapshot_uses_issued_labels_and_preserves_leading_zeroes_and_narrative(): void
    {
        $checklist = $this->checklist('engine2');
        $submission = $this->submission($checklist);
        foreach ($submission['field_values'] as &$answer) {
            $answer['name'] = 'Forged label';
            $answer['input_type'] = 'number';
            if ($answer['id'] === 'new_damage_description') {
                $answer['value'] = "Scratch below rear step.\nPhoto attached to observation.";
            }
        }
        unset($answer);
        $service = new InspectionPaperFields;
        $service->validate($submission, $checklist);
        $snapshot = array_column($service->snapshot($submission, $checklist), null, 'id');
        $this->assertSame(['id' => 'scba_5', 'name' => 'SCBA #5', 'input_type' => 'text', 'value' => '0012-A'], $snapshot['scba_5']);
        $this->assertSame("Scratch below rear step.\nPhoto attached to observation.", $snapshot['new_damage_description']['value']);
    }

    public function test_ladder1_paper_preserves_opposite_side_struts_and_combined_sawzall_row(): void
    {
        $checklist = $this->checklist('ladder1');
        $groups = array_column($checklist['compartments'], null, 'id');
        $this->assertArrayNotHasKey('comp_1', $groups);
        $this->assertArrayNotHasKey('dailySchedule', $checklist);
        $this->assertSame('L1 CHECKOUT SHEET.pdf', $checklist['sourceDocument']['file']);
        $a1 = array_column($groups['a_comp_1']['items'], null, 'id');
        $b1 = array_column($groups['b_comp_1']['items'], null, 'id');
        $this->assertSame('Set of Struts', $a1['comp_1-item-1']['name']);
        $this->assertSame(4, $a1['comp_1-item-1']['expectedQuantity']);
        $this->assertSame('Set of Struts', $b1['l1-b-comp-1-struts']['name']);
        $this->assertSame(4, $b1['l1-b-comp-1-struts']['expectedQuantity']);
        $this->assertSame([], $b1['l1-b-comp-1-struts']['legacyCompartmentNames']);
        $this->assertCount(13, $groups['a_comp_5_6']['items']);
        $saws = array_values(array_filter($groups['b_comp_3']['items'], static fn (array $item): bool => str_contains($item['name'], 'SawZall')));
        $this->assertCount(1, $saws);
        $this->assertSame('comp_3-item-2', $saws[0]['id']);
        $this->assertSame('SawZall 20Volt / 28Volt', $saws[0]['name']);
        $this->assertSame(2, $saws[0]['expectedQuantity']);
        $this->assertSame('20Volt (1), 28Volt (1).', $saws[0]['note']);
        $this->assertSame(['SawZall 20Volt', 'SawZall 28Volt'], $saws[0]['legacyItemNames']);
        $this->assertSame([
            'SCBA #1', 'SCBA #2', 'SCBA #3', 'SCBA #4', 'SCBA #5',
            'Crew Radio # - DE', 'Crew Radio # - FI #1', 'Crew Radio # - FI #2',
        ], array_column($groups['scba_radio']['items'], 'name'));
    }

    public function test_ladder_write_ins_follow_the_paper_medical_and_cab_groups(): void
    {
        $ladder1 = $this->checklist('ladder1');
        $l1groups = array_column($ladder1['compartments'], null, 'id');
        $medical = array_column($l1groups['b_comp_4']['items'], null, 'id');
        $this->assertSame('CO ID#', $medical['comp_4-item-1']['name']);
        $this->assertSame('H2S ID#', $medical['comp_4-item-2']['name']);
        $this->assertSame('text', $medical['comp_4-item-1']['inputType']);
        $cab = array_column($l1groups['front_cab']['items'], null, 'id');
        $this->assertSame('Plymovent Transmitter Working? (Y / N)', $cab['front_cab-item-26']['name']);
        $this->assertSame('text', $cab['front_cab-item-26']['inputType']);
        $this->assertNotContains('plymovent_trans', array_column($ladder1['officerChecklist'], 'id'));

        $ladder3 = $this->checklist('ladder3');
        $l3groups = array_column($ladder3['compartments'], null, 'id');
        $this->assertArrayNotHasKey('comp_3', $l3groups);
        $this->assertArrayNotHasKey('dailySchedule', $ladder3);
        $this->assertSame('L3 CHECKOUT SHEET.pdf', $ladder3['sourceDocument']['file']);
        $tools = array_column($l3groups['a_comp_3']['items'], null, 'id');
        $this->assertSame('Band Saw', $tools['l3-a-comp-3-band-saw']['name']);
        $this->assertArrayNotHasKey('expectedQuantity', $tools['l3-a-comp-3-band-saw']);
        $medical = array_column($l3groups['b_comp_3']['items'], null, 'id');
        foreach (['co_3500pak_num' => 'CO 3500PAK #', 'h2s_3500pak_num' => 'H2S 3500 PAK#'] as $id => $name) {
            $this->assertSame($name, $medical[$id]['name']);
            $this->assertSame('text', $medical[$id]['inputType']);
            $this->assertFalse($medical[$id]['valueRequired']);
            $this->assertNotContains($id, array_column($ladder3['officerChecklist'], 'id'));
        }
        $this->assertCount(11, $l3groups['officer_side_panel']['items']);
        $this->assertSame('officer_side_panel-item-25', $l3groups['officer_side_panel']['items'][0]['id']);
        $this->assertCount(5, $l3groups['stokes_comp']['items']);
    }

    #[DataProvider('invalidMovedWriteIns')]
    public function test_moved_ladder_write_ins_retain_required_and_type_validation(string $template, string $groupId, string $itemId, mixed $value): void
    {
        $checklist = $this->checklist($template);
        $submission = $this->submission($checklist);
        $group = array_search($groupId, array_column($submission['compartments'], 'id'), true);
        $this->assertNotFalse($group);
        $item = array_search($itemId, array_column($submission['compartments'][$group]['items'], 'id'), true);
        $this->assertNotFalse($item);
        $submission['compartments'][$group]['items'][$item]['value'] = $value;
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public static function invalidMovedWriteIns(): array
    {
        return [
            'present L1 CO requires identifier' => ['ladder1', 'b_comp_4', 'comp_4-item-1', null],
            'present L1 transmitter requires answer' => ['ladder1', 'front_cab', 'front_cab-item-26', null],
            'optional L3 CO still requires text when supplied' => ['ladder3', 'b_comp_3', 'co_3500pak_num', 12],
            'L3 H2S identifier length is bounded' => ['ladder3', 'b_comp_3', 'h2s_3500pak_num', str_repeat('x', 2001)],
        ];
    }

    public function test_optional_ladder3_detector_write_ins_allow_blank_values_in_their_paper_compartment(): void
    {
        $checklist = $this->checklist('ladder3');
        foreach ([null, ''] as $blank) {
            $submission = $this->submission($checklist);
            $group = array_search('b_comp_3', array_column($submission['compartments'], 'id'), true);
            $this->assertNotFalse($group);
            foreach ($submission['compartments'][$group]['items'] as &$item) {
                if (in_array($item['id'], ['co_3500pak_num', 'h2s_3500pak_num'], true)) {
                    $item['value'] = $blank;
                }
            }
            unset($item);
            (new InspectionPaperFields)->validate($submission, $checklist);
            $values = array_column($submission['compartments'][$group]['items'], 'value', 'id');
            $this->assertSame($blank, $values['co_3500pak_num']);
            $this->assertSame($blank, $values['h2s_3500pak_num']);
        }
    }

    public function test_fire_boat_paper_has_61_items_with_powered_red_lights_as_bilge_pump_instruction(): void
    {
        $checklist = $this->checklist('fireboat6');
        $items = array_merge(...array_column($checklist['compartments'], 'items'));
        $this->assertCount(61, $items);
        $this->assertCount(12, $checklist['compartments']);
        $byId = array_column($items, null, 'id');
        $this->assertSame('BILGE Pumps', $byId['fb6-interior-cab-bilge-pumps']['name']);
        $this->assertSame('Powered with red lights X4', $byId['fb6-interior-cab-bilge-pumps']['note']);
        $this->assertSame([], array_values(array_filter($items, static fn (array $item): bool => str_contains($item['name'], 'Powered with red lights'))));
    }

    public function test_rescue_radios_can_be_observed_present_without_inventing_optional_identifiers(): void
    {
        $checklist = $this->checklist('rescue');
        $index = array_search('radio_numbers', array_column($checklist['compartments'], 'id'), true);
        foreach ($checklist['compartments'][$index]['items'] as $definition) {
            $this->assertSame('text', $definition['inputType']);
            $this->assertFalse($definition['valueRequired']);
        }
        foreach ([null, ''] as $blank) {
            $submission = $this->submission($checklist);
            foreach ($submission['compartments'][$index]['items'] as &$item) {
                $item['value'] = $blank;
            }
            unset($item);
            (new InspectionPaperFields)->validate($submission, $checklist);
            $this->assertSame($blank, $submission['compartments'][$index]['items'][0]['value']);
        }
    }

    public function test_optional_rescue_radio_value_still_rejects_wrong_type(): void
    {
        $checklist = $this->checklist('rescue');
        $submission = $this->submission($checklist);
        $index = array_search('radio_numbers', array_column($submission['compartments'], 'id'), true);
        $submission['compartments'][$index]['items'][0]['value'] = 12;
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public function test_scheduled_duties_require_member_observation(): void
    {
        $checklist = $this->checklist('engine2');
        $submission = $this->submission($checklist);
        $submission['scheduled_tasks'] = [['id' => 'daily-duty', 'status' => 'Present', 'observed' => false]];
        $this->expectException(ValidationException::class);
        (new InspectionPaperFields)->validate($submission, $checklist);
    }

    public function test_observed_scheduled_duties_are_accepted(): void
    {
        $checklist = $this->checklist('engine2');
        $submission = $this->submission($checklist);
        $submission['scheduled_tasks'] = [['id' => 'daily-duty', 'status' => 'Present', 'observed' => true]];
        (new InspectionPaperFields)->validate($submission, $checklist);
        $this->assertTrue($submission['scheduled_tasks'][0]['observed']);
    }

    private function checklist(string $template): array
    {
        return json_decode(file_get_contents(base_path('storage/checklists/'.$template.'_checklist.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function submission(array $checklist): array
    {
        $submission = ['field_values' => [], 'compartments' => []];
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] ?? [] as $field) {
            $submission['field_values'][] = ['id' => $field['id'], 'value' => $this->value($field['inputType'])];
        }
        foreach ($checklist['compartments'] as $compartmentIndex => $compartment) {
            $id = $compartment['id'] ?? 'compartment-'.($compartmentIndex + 1);
            $items = [];
            foreach ($compartment['items'] as $itemIndex => $definition) {
                // Match api.ts: preserve issued IDs or generate the legacy positional ID.
                $item = ['id' => $definition['id'] ?? $id.'-item-'.($itemIndex + 1), 'name' => $definition['name'], 'status' => 'Present', 'observed' => true];
                if (in_array($definition['inputType'] ?? null, ['text', 'number', 'percentage', 'date'], true)) {
                    $item['value'] = $this->value($definition['inputType']);
                }
                $items[] = $item;
            }
            $submission['compartments'][] = ['id' => $id, 'items' => $items];
        }

        return $submission;
    }

    private function value(string $type): string|int|bool
    {
        return match ($type) {
            'number' => 1200,
            'percentage' => 75,
            'checkbox' => false,
            'date' => '2026-09-21',
            default => '0012-A',
        };
    }
}
