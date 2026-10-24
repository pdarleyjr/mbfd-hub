<?php

declare(strict_types=1);

namespace Tests\Unit\Ems;

use App\Models\Ems\SupplyItem;
use App\Services\Ems\Import\SupplyRowMapper;
use App\Services\Ems\InventoryCountService;
use PHPUnit\Framework\TestCase;

/** Conversions use explicit factors only; anything else is null and flagged. */
final class SupplyConversionTest extends TestCase
{
    /** @param array<string, mixed> $threshold @param array<string, mixed> $package */
    private function map(array $threshold, array $package, ?string $text = '< 15U', array $order = []): array
    {
        return (new SupplyRowMapper)->map([
            'row_key' => 'x', 'item_text' => 'Item', 'order_level_text' => $text, 'section_key' => 'supplies',
            'parsed' => ['threshold' => $threshold, 'package' => $package, 'order' => $order, 'suppliers' => [], 'item_numbers' => []],
            'flags' => [], 'needs_review' => false, 'review_reasons' => [],
        ]);
    }

    public function test_each_thresholds_convert_directly(): void
    {
        $result = $this->map(['qty' => 15, 'unit' => 'each', 'comparison' => 'lt'], ['unit' => 'box', 'base_units_per_package' => 60]);

        $this->assertSame(15, $result['attributes']['threshold_qty_base']);
        $this->assertFalse($result['attributes']['needs_review']);
        $this->assertSame('< 15U', $result['attributes']['threshold_source_text']);
    }

    public function test_package_thresholds_need_an_explicit_package_size(): void
    {
        $known = $this->map(['qty' => 3, 'unit' => 'box', 'comparison' => 'lt'], ['unit' => 'box', 'base_units_per_package' => 20], '< 3BX');
        $this->assertSame(60, $known['attributes']['threshold_qty_base']);

        $unknown = $this->map(['qty' => 3, 'unit' => 'box', 'comparison' => 'lt'], ['unit' => 'box', 'base_units_per_package' => null], '< 3BX');
        $this->assertNull($unknown['attributes']['threshold_qty_base']);
        $this->assertTrue($unknown['attributes']['needs_review']);
        $this->assertStringContainsString('not stated', $unknown['review_reasons'][0]);

        $mismatch = $this->map(['qty' => 20, 'unit' => 'pack', 'comparison' => 'lt'], ['unit' => 'case', 'base_units_per_package' => 40], '< 20Pks');
        $this->assertNull($mismatch['attributes']['threshold_qty_base'], 'A pack threshold on a case row with a case size is still not a pack size.');
        $this->assertTrue($mismatch['attributes']['needs_review']);
    }

    public function test_an_unreadable_order_level_and_unusual_comparisons_are_flagged(): void
    {
        $unreadable = $this->map(['qty' => null, 'unit' => null, 'comparison' => null], ['unit' => null], 'when low');
        $this->assertNull($unreadable['attributes']['threshold_qty_base']);
        $this->assertTrue($unreadable['attributes']['needs_review']);

        $other = $this->map(['qty' => 4, 'unit' => 'each', 'comparison' => 'le'], ['unit' => null]);
        $this->assertNull($other['attributes']['threshold_qty_base']);
        $this->assertTrue($other['attributes']['needs_review']);

        $none = $this->map(['qty' => null, 'unit' => null, 'comparison' => null], ['unit' => null], null);
        $this->assertFalse($none['attributes']['needs_review'], 'No order level at all is not an error.');
    }

    public function test_source_review_flags_and_reasons_are_kept(): void
    {
        $result = (new SupplyRowMapper)->map([
            'row_key' => 'x', 'item_text' => 'Item', 'order_level_text' => '< 5U',
            'parsed' => ['threshold' => ['qty' => 5, 'unit' => 'each', 'comparison' => 'lt'], 'package' => [], 'order' => [], 'suppliers' => [], 'item_numbers' => []],
            'flags' => ['refrigerated' => true, 'controlled_substance' => false, 'has_expiration' => null],
            'needs_review' => true, 'review_reasons' => ['Item text wraps two lines.'],
        ]);

        $this->assertTrue($result['attributes']['needs_review']);
        $this->assertSame(['Item text wraps two lines.'], $result['attributes']['review_reasons']);
        $this->assertTrue($result['attributes']['refrigerated']);
        $this->assertNull($result['attributes']['has_expiration']);
    }

    public function test_order_packages_come_from_the_text_only_in_the_package_unit(): void
    {
        $ok = $this->map(['qty' => 5, 'unit' => 'each', 'comparison' => 'lt'], ['unit' => 'box', 'base_units_per_package' => 10], '< 5U', ['text_qty' => 2, 'text_unit' => 'box']);
        $this->assertSame(2, $ok['attributes']['order_qty_packages']);

        $other = $this->map(['qty' => 5, 'unit' => 'each', 'comparison' => 'lt'], ['unit' => 'box', 'base_units_per_package' => 10], '< 5U', ['text_qty' => 2, 'text_unit' => 'case']);
        $this->assertNull($other['attributes']['order_qty_packages']);
    }

    public function test_typed_counts_convert_with_explicit_sizes_and_flag_the_rest(): void
    {
        $boxed = new SupplyItem(['package_unit' => 'box', 'units_per_package' => 24, 'packages_per_case' => 10]);
        $this->assertSame(['quantity' => 5, 'warnings' => []], InventoryCountService::convertEntry($boxed, ['each' => 5]));
        $this->assertSame(['quantity' => 53, 'warnings' => []], InventoryCountService::convertEntry($boxed, ['boxes' => 2, 'each' => 5]));
        $this->assertSame(['quantity' => 480, 'warnings' => []], InventoryCountService::convertEntry($boxed, ['cases' => 2]));
        $this->assertSame(['quantity' => 0, 'warnings' => []], InventoryCountService::convertEntry($boxed, ['each' => 0]));

        $caseUnit = new SupplyItem(['package_unit' => 'case', 'units_per_package' => 100]);
        $this->assertSame(300, InventoryCountService::convertEntry($caseUnit, ['cases' => 3])['quantity']);

        $unknown = new SupplyItem(['package_unit' => 'case', 'units_per_package' => null]);
        $this->assertSame(['quantity' => null, 'warnings' => ['unknown_conversion']], InventoryCountService::convertEntry($unknown, ['cases' => 1, 'each' => 4]));
        $this->assertSame(['quantity' => null, 'warnings' => ['unknown_conversion']], InventoryCountService::convertEntry($unknown, ['boxes' => 1]));
        $this->assertSame(['quantity' => 4, 'warnings' => []], InventoryCountService::convertEntry($unknown, ['each' => 4]));

        // A case-packaged item has no box factor: a "boxes" count is flagged, never multiplied by the case size.
        $caseOnly = new SupplyItem(['package_unit' => 'case', 'units_per_package' => 100]);
        $this->assertSame(['quantity' => null, 'warnings' => ['unknown_conversion']], InventoryCountService::convertEntry($caseOnly, ['boxes' => 2]));
        $this->assertSame(['quantity' => null, 'warnings' => ['unknown_conversion']], InventoryCountService::convertEntry(new SupplyItem(['units_per_package' => 12]), ['boxes' => 1]));
        $this->assertSame(['quantity' => 36, 'warnings' => []], InventoryCountService::convertEntry(new SupplyItem(['package_unit' => 'pack', 'units_per_package' => 12]), ['boxes' => 3]));

        $noCaseSize = new SupplyItem(['package_unit' => 'box', 'units_per_package' => 24, 'packages_per_case' => null]);
        $this->assertSame(['quantity' => null, 'warnings' => ['unknown_conversion']], InventoryCountService::convertEntry($noCaseSize, ['cases' => 1]));
    }
}
