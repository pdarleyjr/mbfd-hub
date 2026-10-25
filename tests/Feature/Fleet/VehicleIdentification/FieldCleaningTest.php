<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Support\Fleet\VehicleIdentificationFields as Fields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a crew may type for an identifier, and what is only a way of saying "nothing". */
final class FieldCleaningTest extends TestCase
{
    /** @return array<string, array{string, string, mixed}> */
    public static function fillerValues(): array
    {
        $sets = [];
        foreach (['N/A', 'n/a', 'NA', 'NONE', '-', '0', 'UNKNOWN', 'unknown', 'X', 'x', '  none  ', '0000', 'AAAA', 'ZZZZZZ', '1111111', '--'] as $filler) {
            foreach ([Fields::VEHICLE_NUMBER, Fields::PLATE] as $field) {
                $sets["{$field} {$filler}"] = [$field, Fields::KIND_VEHICLE, $filler];
            }
            $sets["hin {$filler}"] = [Fields::VIN, Fields::KIND_BOAT, $filler];
        }

        return $sets;
    }

    #[DataProvider('fillerValues')]
    public function test_filler_is_never_accepted_as_an_identifier(string $field, string $kind, mixed $value): void
    {
        $this->assertNull(Fields::clean($field, $kind, $value));
    }

    public function test_identifiers_must_be_long_enough_to_mean_something(): void
    {
        $this->assertNull(Fields::clean(Fields::PLATE, 'vehicle', 'A'));
        $this->assertSame('AB', Fields::clean(Fields::PLATE, 'vehicle', 'a b'));
        $this->assertNull(Fields::clean(Fields::VEHICLE_NUMBER, 'vehicle', 'R-1'));
        $this->assertSame('r-14', Fields::clean(Fields::VEHICLE_NUMBER, 'vehicle', ' r-14 '), 'spelling is kept; matching ignores case');
        $this->assertNull(Fields::clean(Fields::VIN, 'boat', 'AB123'));
        $this->assertSame('MTK12345A515', Fields::clean(Fields::VIN, 'boat', 'mtk 12345-a515'));
        $this->assertNull(Fields::clean(Fields::VIN, 'vehicle', '1FTFW1ET5DFC1031'), '16 characters');
        $this->assertSame('1FTFW1ET5DFC10312', Fields::clean(Fields::VIN, 'vehicle', '1ftfw1et5 dfc-10312'));
    }

    public function test_a_year_is_a_nineteen_or_twenty_hundreds_year(): void
    {
        $this->assertSame('1987', Fields::clean(Fields::YEAR, 'vehicle', '1987'));
        $this->assertSame('2026', Fields::clean(Fields::YEAR, 'vehicle', '2026'));
        $this->assertNull(Fields::clean(Fields::YEAR, 'vehicle', '1899'));
        $this->assertNull(Fields::clean(Fields::YEAR, 'vehicle', '2100'));
        $this->assertNull(Fields::clean(Fields::YEAR, 'vehicle', '22'));
    }

    public function test_descriptions_are_not_held_to_the_identifier_rules(): void
    {
        $this->assertSame('F-250', Fields::clean(Fields::MODEL, 'vehicle', 'F-250'));
        $this->assertSame('GL', Fields::clean(Fields::MODEL, 'vehicle', 'GL'));
    }

    public function test_placeholder_detection_covers_blank_markers_and_typed_fillers(): void
    {
        foreach ([null, '', '  ', 'N/A', 'na', 'None', '-', '0', 'UNKNOWN', 'X'] as $placeholder) {
            $this->assertTrue(Fields::isPlaceholderValue($placeholder), var_export($placeholder, true));
        }
        foreach (['V-202', '1FTFW1ET5DFC10312', 'XE1234'] as $real) {
            $this->assertFalse(Fields::isPlaceholderValue($real), $real);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function descriptionFiller(): array
    {
        $sets = [];
        foreach (['N/A', 'n/a', 'NA', 'NONE', 'none', '-', '--', '0', 'UNKNOWN', 'unknown'] as $filler) {
            foreach ([Fields::MAKE, Fields::MODEL] as $field) {
                $sets["{$field} {$filler}"] = [$field, $filler];
            }
        }

        return $sets;
    }

    #[DataProvider('descriptionFiller')]
    public function test_make_and_model_refuse_placeholders(string $field, string $value): void
    {
        $this->assertNull(Fields::clean($field, Fields::KIND_VEHICLE, $value));
    }

    public function test_make_and_model_keep_a_real_x_and_ordinary_names(): void
    {
        $this->assertSame('X', Fields::clean(Fields::MODEL, Fields::KIND_VEHICLE, ' X '), 'a Tesla Model X');
        $this->assertSame('Model X', Fields::clean(Fields::MODEL, Fields::KIND_VEHICLE, 'Model X'));
        $this->assertSame('Pierce', Fields::clean(Fields::MAKE, Fields::KIND_VEHICLE, 'Pierce'));
        $this->assertSame('F-250', Fields::clean(Fields::MODEL, Fields::KIND_VEHICLE, 'F-250'));
    }
}
