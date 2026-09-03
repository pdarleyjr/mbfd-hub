<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DailyCheckoutChecklistResolverTest extends TestCase
{
    public function test_it_uses_the_approved_family_mapping_for_standard_operational_units(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        $cases = [
            ['type' => 'Engine', 'class_description' => 'ENGINE', 'designation' => 'E3', 'expected' => 'engine', 'error' => null],
            ['type' => 'Engine', 'class_description' => 'Engine Company', 'designation' => 'E4', 'expected' => 'engine', 'error' => null],
            ['type' => 'Rescue', 'class_description' => 'RESCUE', 'designation' => 'R2', 'expected' => 'rescue', 'error' => null],
            ['type' => 'Rescue', 'class_description' => 'Rescue Company', 'designation' => 'R11', 'expected' => 'rescue', 'error' => null],
            ['type' => 'Rescue', 'class_description' => 'RESCUE', 'designation' => 'R22', 'expected' => 'rescue', 'error' => null],
            ['type' => 'Rescue', 'class_description' => 'RESCUE', 'designation' => 'R3', 'expected' => 'rescue', 'error' => null],
            ['type' => 'Rescue', 'class_description' => 'RESCUE', 'designation' => 'R4', 'expected' => 'rescue', 'error' => null],
            ['type' => 'Rescue', 'class_description' => 'RESCUE', 'designation' => 'R44', 'expected' => 'rescue', 'error' => null],
            ['type' => 'Ladder', 'class_description' => 'LADDER', 'designation' => 'L11', 'expected' => 'ladder1', 'error' => null],
        ];

        foreach ($cases as $case) {
            $resolution = $resolver->resolve(new Apparatus($case));

            $this->assertSame($case['expected'], $resolution['checklist_type']);
            $this->assertSame('family', $resolution['resolution_source']);
            $this->assertSame($case['error'], $resolution['error']);
        }
    }

    public function test_it_applies_the_approved_e2_and_l3_overrides_before_their_family_mapping(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        foreach ([
            ['type' => 'Engine', 'class_description' => 'ENGINE', 'designation' => 'E2', 'expected' => 'engine2', 'error' => null],
            ['type' => 'Ladder', 'class_description' => 'LADDER', 'designation' => 'L3', 'expected' => 'ladder3', 'error' => null],
        ] as $case) {
            $resolution = $resolver->resolve(new Apparatus($case));

            $this->assertSame($case['expected'], $resolution['checklist_type']);
            $this->assertSame('family_override', $resolution['resolution_source']);
            $this->assertSame($case['error'], $resolution['error']);
        }
    }

    public function test_every_approved_frontline_template_is_usable(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        foreach ([
            ['unit_id' => 'E1', 'type' => 'Engine', 'template' => DailyCheckoutChecklistTemplate::Engine],
            ['unit_id' => 'E2', 'type' => 'Engine', 'template' => DailyCheckoutChecklistTemplate::Engine2],
            ['unit_id' => 'R1', 'type' => 'Rescue', 'template' => DailyCheckoutChecklistTemplate::Rescue],
            ['unit_id' => 'L1', 'type' => 'Ladder', 'template' => DailyCheckoutChecklistTemplate::Ladder1],
            ['unit_id' => 'L3', 'type' => 'Ladder', 'template' => DailyCheckoutChecklistTemplate::Ladder3],
            ['unit_id' => 'FB6', 'type' => 'Fire Boat', 'template' => DailyCheckoutChecklistTemplate::FireBoat6],
        ] as $case) {
            $resolution = $resolver->resolve(new Apparatus([
                'unit_id' => $case['unit_id'],
                'designation' => $case['unit_id'],
                'type' => $case['type'],
                'daily_checkout_template' => $case['template']->value,
            ]));

            $this->assertTrue($resolution['usable'], $case['template']->value);
            $this->assertNull($resolution['error'], $case['template']->value);
            $this->assertSame($case['template']->value, $resolution['checklist_type']);
        }
    }

    public function test_an_explicit_approved_template_overrides_specialty_fail_closed_classification(): void
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve(new Apparatus([
            'type' => 'Air Truck',
            'class_description' => 'SPECIALTY AIR',
            'designation' => 'Air 1',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Rescue->value,
        ]));

        $this->assertSame('rescue', $resolution['checklist_type']);
        $this->assertSame('configured_template', $resolution['resolution_source']);
        $this->assertTrue($resolution['usable']);
    }

    public function test_specialty_and_administrative_apparatuses_fail_closed_while_their_template_is_pending(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        foreach ([
            ['type' => 'Air Truck', 'class_description' => 'Air Support', 'designation' => 'Air 1'],
            ['type' => 'Fire Boat', 'class_description' => 'Marine', 'designation' => 'FB6'],
            ['type' => 'Command Vehicle', 'class_description' => 'Command', 'designation' => 'C1'],
            ['type' => 'Administrative', 'class_description' => 'Administrative', 'designation' => 'Admin 1'],
        ] as $attributes) {
            $resolution = $resolver->resolve(new Apparatus($attributes));

            $this->assertSame('unmapped', $resolution['checklist_type']);
            $this->assertSame('specialty_template_pending', $resolution['error']);
            $this->assertSame('pending', $resolution['resolution_source']);
            $this->assertFalse($resolution['usable']);
        }
    }

    public function test_fire_boat_6_only_resolves_its_explicit_v2_template(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        $pending = $resolver->resolve(new Apparatus([
            'unit_id' => 'FB6',
            'type' => 'Fire Boat',
            'class_description' => 'Marine',
            'designation' => 'Fire Boat 6',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Pending->value,
        ]));
        $this->assertSame('specialty_template_pending', $pending['error']);
        $this->assertFalse($pending['usable']);

        foreach ([
            DailyCheckoutChecklistTemplate::Default,
            DailyCheckoutChecklistTemplate::Engine,
            DailyCheckoutChecklistTemplate::Engine2,
            DailyCheckoutChecklistTemplate::Rescue,
            DailyCheckoutChecklistTemplate::Ladder1,
            DailyCheckoutChecklistTemplate::Ladder3,
        ] as $template) {
            $resolution = $resolver->resolve(new Apparatus([
                'unit_id' => 'FB6',
                'type' => 'Fire Boat',
                'class_description' => 'Marine',
                'designation' => 'Fire Boat 6',
                'daily_checkout_template' => $template->value,
            ]));

            $this->assertSame('unmapped', $resolution['checklist_type']);
            $this->assertSame('fire_boat_template_required', $resolution['error']);
            $this->assertFalse($resolution['usable']);
        }

        $resolution = $resolver->resolve(new Apparatus([
            'unit_id' => 'FB6',
            'type' => 'Fire Boat',
            'class_description' => 'Marine',
            'designation' => 'Fire Boat 6',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]));

        $this->assertTrue($resolution['usable']);
        $this->assertSame('fireboat6', $resolution['checklist_type']);
        $this->assertSame('configured_template', $resolution['resolution_source']);
        $this->assertSame(2, $resolution['checklist']['schema_version']);
        $this->assertSame('fire_boat_6_daily', $resolution['checklist']['template_id']);

        $wrongIdentity = $resolver->resolve(new Apparatus([
            'unit_id' => 'E1',
            'type' => 'Engine',
            'designation' => 'Engine 1',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]));
        $this->assertSame('unmapped', $wrongIdentity['checklist_type']);
        $this->assertSame('fire_boat_6_identity_required', $wrongIdentity['error']);

        $monday = $resolver->dueTasksFor($resolution['checklist'], CarbonImmutable::parse('2026-08-31'));
        $sunday = $resolver->dueTasksFor($resolution['checklist'], CarbonImmutable::parse('2026-08-30'));
        $monthly = $resolver->dueTasksFor($resolution['checklist'], CarbonImmutable::parse('2026-11-01'));
        $weekdayAndMonthly = $resolver->dueTasksFor($resolution['checklist'], CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(['fb6-monday-fuel-tank-hold'], array_column($monday, 'id'));
        $this->assertSame([], $sunday);
        $this->assertSame(['fb6-monthly-first-day'], array_column($monthly, 'id'));
        $this->assertSame(['fb6-tuesday-stern-lazarete', 'fb6-monthly-first-day'], array_column($weekdayAndMonthly, 'id'));
    }

    public function test_v2_contract_requires_unique_machine_ids_without_rejecting_repeated_display_labels(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-daily-checklist-'.uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory.DIRECTORY_SEPARATOR.'fireboat6_checklist.json';
        $apparatus = new Apparatus([
            'unit_id' => 'FB6',
            'type' => 'Fire Boat',
            'designation' => 'Fire Boat 6',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);

        try {
            file_put_contents($path, json_encode([
                'schema_version' => 2,
                'template_id' => 'fire_boat_6_daily',
                'template_version' => '2026-07',
                'inspectionDateFieldId' => 'inspection_date',
                'fields' => [[
                    'id' => 'inspection_date',
                    'name' => 'Date',
                    'inputType' => 'date',
                    'required' => true,
                ]],
                'recurringTasks' => [[
                    'id' => 'monday-task',
                    'name' => 'Monday task',
                    'recurrence' => ['type' => 'weekday', 'weekday' => 'monday'],
                ]],
                'compartments' => [[
                    'id' => 'cab',
                    'title' => 'Cab',
                    'items' => [
                        ['id' => 'radio-driver', 'name' => 'Radio', 'inputType' => 'checkbox'],
                        ['id' => 'radio-officer', 'name' => 'Radio', 'inputType' => 'checkbox'],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            $usable = (new DailyCheckoutChecklistResolver($directory))->resolve($apparatus);
            $this->assertTrue($usable['usable']);

            file_put_contents($path, json_encode([
                'schema_version' => 2,
                'template_id' => 'fire_boat_6_daily',
                'template_version' => '2026-07',
                'inspectionDateFieldId' => 'inspection_date',
                'fields' => [[
                    'id' => 'inspection_date',
                    'name' => 'Date',
                    'inputType' => 'date',
                    'required' => true,
                ]],
                'recurringTasks' => [[
                    'id' => 'monday-task',
                    'name' => 'Monday task',
                    'recurrence' => ['type' => 'weekday', 'weekday' => 'monday'],
                ]],
                'compartments' => [[
                    'id' => 'cab',
                    'title' => 'Cab',
                    'items' => [
                        ['id' => 'radio', 'name' => 'Driver radio', 'inputType' => 'checkbox'],
                        ['id' => 'radio', 'name' => 'Officer radio', 'inputType' => 'checkbox'],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            $duplicateId = (new DailyCheckoutChecklistResolver($directory))->resolve($apparatus);
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertFalse($duplicateId['usable']);
        $this->assertSame('invalid_schema', $duplicateId['error']);
    }

    public function test_an_unknown_specialty_fails_closed_while_its_template_is_pending(): void
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve(new Apparatus([
            'type' => 'HazMat Engine',
            'class_description' => 'Specialty',
            'designation' => 'HM1',
        ]));

        $this->assertSame('unmapped', $resolution['checklist_type']);
        $this->assertSame('unclassified_family_template_pending', $resolution['error']);
        $this->assertSame('pending', $resolution['resolution_source']);
        $this->assertFalse($resolution['usable']);
    }

    public function test_it_fails_closed_when_family_data_is_ambiguous_without_an_explicit_template(): void
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve(new Apparatus([
            'type' => 'Engine',
            'class_description' => 'Rescue',
            'designation' => 'E1',
        ]));

        $this->assertSame('unmapped', $resolution['checklist_type']);
        $this->assertSame('ambiguous_apparatus_family', $resolution['error']);
        $this->assertSame('pending', $resolution['resolution_source']);
    }

    public function test_it_rejects_a_structurally_ambiguous_explicit_template_source(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-daily-checklist-'.uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory.DIRECTORY_SEPARATOR.'default_checklist.json';
        file_put_contents($path, json_encode([
            'compartments' => [[
                'id' => 'cab',
                'title' => 'Cab',
                'items' => [
                    ['name' => 'Radio'],
                ],
            ], [
                'id' => 'cab',
                'title' => 'Second Cab',
                'items' => [
                    ['name' => 'Flashlight'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $resolution = (new DailyCheckoutChecklistResolver($directory))->resolve(new Apparatus([
                'type' => 'Administrative',
                'designation' => 'C1',
                'daily_checkout_template' => DailyCheckoutChecklistTemplate::Default->value,
            ]));
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertFalse($resolution['usable']);
        $this->assertSame('invalid_schema', $resolution['error']);
    }

    public function test_it_rejects_duplicate_item_names_within_a_compartment_for_an_explicit_template(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-daily-checklist-'.uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory.DIRECTORY_SEPARATOR.'default_checklist.json';
        file_put_contents($path, json_encode([
            'compartments' => [[
                'id' => 'cab',
                'title' => 'Cab',
                'items' => [
                    ['name' => 'Radio'],
                    ['name' => 'Radio'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $resolution = (new DailyCheckoutChecklistResolver($directory))->resolve(new Apparatus([
                'type' => 'Administrative',
                'designation' => 'C1',
                'daily_checkout_template' => DailyCheckoutChecklistTemplate::Default->value,
            ]));
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertFalse($resolution['usable']);
        $this->assertSame('invalid_schema', $resolution['error']);
    }

    public function test_it_rejects_duplicate_compartment_names_for_an_explicit_template(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-daily-checklist-'.uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory.DIRECTORY_SEPARATOR.'default_checklist.json';
        file_put_contents($path, json_encode([
            'compartments' => [[
                'id' => 'cab-a',
                'title' => 'Cab',
                'items' => [['name' => 'Radio']],
            ], [
                'id' => 'cab-b',
                'title' => 'Cab',
                'items' => [['name' => 'Radio']],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $resolution = (new DailyCheckoutChecklistResolver($directory))->resolve(new Apparatus([
                'type' => 'Administrative',
                'designation' => 'C1',
                'daily_checkout_template' => DailyCheckoutChecklistTemplate::Default->value,
            ]));
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertFalse($resolution['usable']);
        $this->assertSame('invalid_schema', $resolution['error']);
    }

    public function test_it_returns_a_stable_immutable_version_for_the_canonical_checklist_content(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-daily-checklist-'.uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory.DIRECTORY_SEPARATOR.'default_checklist.json';
        $apparatus = new Apparatus([
            'type' => 'Administrative',
            'designation' => 'C1',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Default->value,
        ]);
        $resolver = new DailyCheckoutChecklistResolver($directory);

        try {
            file_put_contents($path, json_encode([
                'metadata' => ['z' => 'last', 'a' => 'first'],
                'compartments' => [[
                    'id' => 'cab',
                    'title' => 'Cab',
                    'items' => [['name' => 'Radio']],
                ]],
            ], JSON_THROW_ON_ERROR));
            $first = $resolver->resolve($apparatus);

            file_put_contents($path, json_encode([
                'compartments' => [[
                    'items' => [['name' => 'Radio']],
                    'title' => 'Cab',
                    'id' => 'cab',
                ]],
                'metadata' => ['a' => 'first', 'z' => 'last'],
            ], JSON_THROW_ON_ERROR));
            $reordered = $resolver->resolve($apparatus);

            file_put_contents($path, json_encode([
                'compartments' => [[
                    'id' => 'cab',
                    'title' => 'Cab',
                    'items' => [['name' => 'Portable Radio']],
                ]],
                'metadata' => ['a' => 'first', 'z' => 'last'],
            ], JSON_THROW_ON_ERROR));
            $changed = $resolver->resolve($apparatus);
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertTrue($first['usable']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['checklist_version']);
        $this->assertSame($first['checklist_version'], $reordered['checklist_version']);
        $this->assertNotSame($first['checklist_version'], $changed['checklist_version']);
    }
}
