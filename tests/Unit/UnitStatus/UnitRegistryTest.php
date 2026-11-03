<?php

declare(strict_types=1);

namespace Tests\Unit\UnitStatus;

use App\Support\UnitStatus\StatusFamily;
use App\Support\UnitStatus\UnitRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UnitRegistryTest extends TestCase
{
    private function registry(): UnitRegistry
    {
        return new UnitRegistry;
    }

    public function test_exactly_sixteen_units_in_contract_order(): void
    {
        $codes = array_map(static fn ($unit): string => $unit->code, $this->registry()->units());

        self::assertSame(['L1', 'E1', 'R1', 'R11', 'E2', 'R2', 'R22', 'CPT5', '300', 'L3', 'E3', 'R3', 'E4', 'R4', 'R44', 'FB6'], $codes);
        self::assertSame([1, 2, 3, 4, 6], $this->registry()->stationNumbers());
    }

    /** @return array<string, array{0: string, 1: ?string, 2?: bool}> */
    public static function designations(): array
    {
        return [
            'R1 is not R11' => ['R1', 'R1'],
            'R11 is not R1' => ['R11', 'R11'],
            'R2' => ['R2', 'R2'],
            'R22' => ['R22', 'R22'],
            'R4' => ['R4', 'R4'],
            'R44' => ['R44', 'R44'],
            'E1 is not L1' => ['E1', 'E1'],
            'L1' => ['L1', 'L1'],
            'CPT5' => ['CPT5', 'CPT5'],
            '300 is not CPT5' => ['300', '300'],
            'lowercase' => ['e3', 'E3'],
            'spaces and punctuation' => [' r-1 ', 'R1'],
            'inner space' => ['CPT 5', 'CPT5'],
            'FB6' => ['FB6', 'FB6'],
            'R111 is nobody' => ['R111', null],
            'R3 is not R33' => ['R33', null],
            'prefix of a unit' => ['R', null],
            'E10 is not E1' => ['E10', null],
            'suffix noise' => ['E1A', null],
            'prefixed by letter' => ['XE1', null],
            'command car' => ['CMD1', null],
            'G1' => ['G1', null],
            'air truck' => ['810', null],
            'R6 is not FB6' => ['R6', null],
            'only a question mark' => ['?', null],
            'empty' => ['', null],
            '3000 is not 300' => ['3000', null],
        ];
    }

    #[DataProvider('designations')]
    public function test_designation_matching_is_exact(string $designation, ?string $expected): void
    {
        self::assertSame($expected, $this->registry()->matchDesignation($designation)?->unit->code);
    }

    public function test_a_leading_question_mark_is_matched_but_unconfirmed(): void
    {
        $match = $this->registry()->matchDesignation('?r 1');

        self::assertSame('R1', $match?->unit->code);
        self::assertFalse($match->confirmed);
        self::assertTrue($this->registry()->matchDesignation('R1')?->confirmed);
        self::assertSame('FB6', $this->registry()->matchDesignation('?FB6')?->unit->code);
        self::assertNull($this->registry()->matchDesignation('?R111'));
    }

    public function test_roster_labels_match_exactly_ignoring_case_and_spacing(): void
    {
        $registry = $this->registry();

        self::assertSame('R1', $registry->matchRosterLabel('Rescue 1')?->code);
        self::assertSame('R1', $registry->matchRosterLabel('  rescue   1 ')?->code);
        self::assertSame('R11', $registry->matchRosterLabel('RESCUE 11')?->code);
        self::assertSame('300', $registry->matchRosterLabel('Division Chief 300')?->code);
        self::assertSame('FB6', $registry->matchRosterLabel('Fire Boat 6')?->code);
        self::assertSame('CPT5', $registry->matchRosterLabel('Captain 5')?->code);
        foreach (['Air Truck 810', 'Rescue Float', 'Rescue Float Pool', 'Combat Float Pool', 'Marine Float', 'Rescue', 'Rescue 111', 'Fireboat 6'] as $ignored) {
            self::assertNull($registry->matchRosterLabel($ignored), $ignored);
        }
        self::assertNull($registry->matchRosterLabel(null));
    }

    public function test_find_by_code_is_exact(): void
    {
        self::assertSame('Rescue 44', $this->registry()->find('r44')?->name);
        self::assertNull($this->registry()->find('R5'));
        self::assertNull($this->registry()->find('R'));
        self::assertSame('unit-fb6', $this->registry()->find('FB6')?->domId());
    }

    public function test_status_words_map_to_families_and_indicator_colors(): void
    {
        $expected = [
            'Dispatched' => ['dispatched', 'rgb(245,124,0)'],
            'Acknowledged' => ['dispatched', 'rgb(245,124,0)'],
            'Enroute' => ['enroute', 'rgb(0,204,0)'],
            'Staged' => ['on_scene', 'rgb(204,0,0)'],
            'On Scene' => ['on_scene', 'rgb(204,0,0)'],
            'Available On Scene' => ['on_scene', 'rgb(204,0,0)'],
            'Transport' => ['transport', 'rgb(255,193,7)'],
            'Transport Arrived' => ['arrived', 'rgb(25,118,210)'],
            'Cleared From Incident' => ['cleared', 'rgb(73,73,73)'],
            '' => ['unknown', null],
            'Something New' => ['unknown', null],
        ];
        foreach ($expected as $word => [$family, $color]) {
            self::assertSame($family, StatusFamily::family($word), $word);
            self::assertSame($color, StatusFamily::color(StatusFamily::family($word)), $word);
        }
        self::assertSame('on_scene', StatusFamily::family('  on   scene '));
        self::assertTrue(StatusFamily::isCleared('cleared from incident'));
        self::assertFalse(StatusFamily::isCleared('Transport Arrived'));
    }
}
