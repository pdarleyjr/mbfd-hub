<?php

declare(strict_types=1);

namespace Tests\Unit\OperationalForms;

use App\Services\OperationalForms\FrocTotalsCalculator;
use PHPUnit\Framework\TestCase;

class FrocTotalsCalculatorTest extends TestCase
{
    public function test_calculates_overnight_labor_and_all_six_authoritative_totals(): void
    {
        $data = [
            'labor' => [
                ['start' => '22:30', 'end' => '01:00', 'event_related' => true],
                ['start' => '08:00', 'end' => '12:00', 'manual_override_hours' => '3.50', 'override_reason' => 'Approved meal deduction', 'event_related' => false],
            ],
            'equipment_hours' => [
                ['hours' => '1.25', 'event_related' => true],
                ['hours' => '2.00', 'event_related' => false],
            ],
            'vehicle_mileage' => [
                ['start_odometer' => '100.5', 'end_odometer' => '124.75', 'event_related' => true],
                ['start_odometer' => '200', 'end_odometer' => '205', 'event_related' => false],
            ],
        ];

        $result = FrocTotalsCalculator::calculate($data);

        $this->assertSame('3.50', $result['p2_total_non_event_hours']);
        $this->assertSame('2.50', $result['p2_total_event_hours']);
        $this->assertSame('2.00', $result['p3_equipment_hours_total_non_event']);
        $this->assertSame('1.25', $result['p3_equipment_hours_total_event']);
        $this->assertSame('5.00', $result['p3_mileage_total_non_event']);
        $this->assertSame('24.25', $result['p3_mileage_total_event']);
    }

    public function test_rejects_negative_mileage_without_a_correction_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FrocTotalsCalculator::calculate([
            'vehicle_mileage' => [[
                'start_odometer' => '120',
                'end_odometer' => '100',
                'event_related' => true,
            ]],
        ]);
    }
}
