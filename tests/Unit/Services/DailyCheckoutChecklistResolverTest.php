<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
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
            ['type' => 'Ladder', 'class_description' => 'LADDER', 'designation' => 'L11', 'expected' => 'ladder1', 'error' => 'invalid_schema'],
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
            ['type' => 'Ladder', 'class_description' => 'LADDER', 'designation' => 'L3', 'expected' => 'ladder3', 'error' => 'invalid_schema'],
        ] as $case) {
            $resolution = $resolver->resolve(new Apparatus($case));

            $this->assertSame($case['expected'], $resolution['checklist_type']);
            $this->assertSame('family_override', $resolution['resolution_source']);
            $this->assertSame($case['error'], $resolution['error']);
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
