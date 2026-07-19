<?php

declare(strict_types=1);

namespace Tests\Unit\OperationalForms;

use App\Services\OperationalForms\FormDataValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FormDataValidatorTest extends TestCase
{
    public function test_partial_labor_and_mileage_rows_are_valid_drafts(): void
    {
        $validated = app(FormDataValidator::class)->validate('froc_log_001_ff', [
            'labor' => [[
                'category' => 'B',
                'work_performed' => 'EPM - Pre-Positioning Equipment and Resources',
                'start' => '15:06',
                'end' => '',
                'event_related' => true,
            ]],
            'vehicle_mileage' => [[
                'category' => 'B',
                'equipment_id' => 'R6',
                'start_odometer' => '113969',
                'end_odometer' => '',
                'event_related' => true,
            ]],
        ]);

        $this->assertSame('0.00', data_get($validated, 'calculated_totals.p2_total_event_hours'));
        $this->assertSame('0.00', data_get($validated, 'calculated_totals.p3_mileage_total_event'));
    }

    public function test_partial_labor_and_mileage_rows_block_completion_at_the_affected_fields(): void
    {
        try {
            app(FormDataValidator::class)->validate('froc_log_001_ff', [
                'general_information' => [
                    'event_id' => 'Bronze Game',
                    'applicant_name' => 'Miami Beach',
                    'department' => 'Miami Beach Fire Department',
                    'date' => '2026-07-18',
                ],
                'labor' => [[
                    'category' => 'B',
                    'work_performed' => 'EPM - Pre-Positioning Equipment and Resources',
                    'start' => '15:06',
                    'end' => '',
                    'event_related' => true,
                ]],
                'vehicle_mileage' => [[
                    'category' => 'B',
                    'equipment_id' => 'R6',
                    'start_odometer' => '113969',
                    'end_odometer' => '',
                    'event_related' => true,
                ]],
                'certification' => [
                    'final_employee_signature_text' => 'Test Firefighter',
                    'final_employee_signature_date' => '2026-07-18',
                    'confirmed' => true,
                ],
            ], true);
            $this->fail('Completion validation should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('labor.0.end', $exception->errors());
            $this->assertArrayHasKey('vehicle_mileage.0.end_odometer', $exception->errors());
        }
    }

    public function test_decreasing_odometer_pair_requires_corrected_mileage_and_reason_for_completion(): void
    {
        $base = [
            'general_information' => [
                'event_id' => 'Bronze Game',
                'applicant_name' => 'Miami Beach',
                'department' => 'Miami Beach Fire Department',
                'date' => '2026-07-18',
            ],
            'vehicle_mileage' => [[
                'category' => 'B',
                'equipment_id' => 'R6',
                'start_odometer' => '120',
                'end_odometer' => '100',
                'event_related' => true,
            ]],
            'certification' => [
                'final_employee_signature_text' => 'Test Firefighter',
                'final_employee_signature_date' => '2026-07-18',
                'confirmed' => true,
            ],
        ];

        try {
            app(FormDataValidator::class)->validate('froc_log_001_ff', $base, true);
            $this->fail('Completion validation should require a correction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicle_mileage.0.manual_miles', $exception->errors());
        }

        $base['vehicle_mileage'][0]['manual_miles'] = '20';
        $base['vehicle_mileage'][0]['correction_reason'] = 'Odometer was replaced';
        $validated = app(FormDataValidator::class)->validate('froc_log_001_ff', $base, true);
        $this->assertSame('20.00', data_get($validated, 'calculated_totals.p3_mileage_total_event'));
    }

    public function test_froc_accepts_optional_signature_times_for_exact_certification_records(): void
    {
        $validated = app(FormDataValidator::class)->validate('froc_log_001_ff', [
            'general_information' => [
                'event_id' => 'Gold Game',
                'applicant_name' => 'City of Miami Beach',
                'department' => 'Miami Beach Fire Department',
                'date' => '2026-07-19',
            ],
            'certification' => [
                'final_employee_signature_text' => 'Victor White',
                'final_employee_signature_date' => '2026-07-19',
                'final_employee_signature_time' => '23:00',
                'confirmed' => true,
            ],
        ], true);

        $this->assertSame('23:00', $validated['certification']['final_employee_signature_time']);
    }
}
