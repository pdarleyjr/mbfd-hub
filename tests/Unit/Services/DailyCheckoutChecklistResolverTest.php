<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
use Tests\TestCase;

class DailyCheckoutChecklistResolverTest extends TestCase
{
    public function test_each_unambiguous_supported_apparatus_class_resolves_a_non_empty_canonical_checklist(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        $cases = [
            ['type' => 'Engine', 'designation' => 'E 1', 'expected' => 'engine'],
            ['type' => 'Engine', 'designation' => 'E 2', 'expected' => 'engine2'],
            ['type' => 'Rescue', 'designation' => 'R 1', 'expected' => 'rescue'],
            ['type' => 'Administrative', 'designation' => 'C 1', 'expected' => 'default'],
        ];

        foreach ($cases as $case) {
            $apparatus = new Apparatus([
                'type' => $case['type'],
                'designation' => $case['designation'],
                'name' => $case['designation'],
            ]);

            $resolution = $resolver->resolve($apparatus);

            $this->assertSame($case['expected'], $resolution['checklist_type']);
            $this->assertTrue($resolution['usable']);
            $this->assertFalse($resolution['fallback_used']);
            $this->assertGreaterThan(0, $resolution['item_count']);
        }
    }

    public function test_it_rejects_a_structurally_ambiguous_checklist_source(): void
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
                'designation' => 'C 1',
            ]));
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertFalse($resolution['usable']);
        $this->assertSame('invalid_schema', $resolution['error']);
    }

    public function test_it_rejects_duplicate_item_names_within_a_compartment(): void
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
                'designation' => 'C 1',
            ]));
        } finally {
            unlink($path);
            rmdir($directory);
        }

        $this->assertFalse($resolution['usable']);
        $this->assertSame('invalid_schema', $resolution['error']);
    }

    public function test_current_ladder_checklist_sources_are_rejected_until_their_duplicate_labels_are_owner_corrected(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        foreach ([
            ['type' => 'Ladder', 'designation' => 'L 1', 'expected' => 'ladder1'],
            ['type' => 'Ladder', 'designation' => 'L 3', 'expected' => 'ladder3'],
        ] as $case) {
            $resolution = $resolver->resolve(new Apparatus($case));

            $this->assertSame($case['expected'], $resolution['checklist_type']);
            $this->assertFalse($resolution['usable']);
            $this->assertSame('invalid_schema', $resolution['error']);
        }
    }

    public function test_it_fails_closed_for_unmapped_specialized_apparatuses(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);

        foreach ([
            ['unit_id' => 'E9', 'type' => 'Engine', 'designation' => 'Engine 9', 'name' => 'Engine 9'],
            ['unit_id' => 'L11', 'type' => 'Ladder', 'designation' => 'Ladder 11', 'name' => 'Ladder 11'],
            ['unit_id' => 'R2', 'type' => 'Rescue', 'designation' => 'Rescue 2', 'name' => 'Rescue 2'],
        ] as $attributes) {
            $resolution = $resolver->resolve(new Apparatus($attributes));

            $this->assertSame('unmapped', $resolution['checklist_type']);
            $this->assertFalse($resolution['usable']);
            $this->assertSame('unmapped_specialized_apparatus', $resolution['error']);
        }
    }

    public function test_it_fails_closed_when_explicit_specialized_identifiers_conflict(): void
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve(new Apparatus([
            'unit_id' => 'E1',
            'type' => 'Engine',
            'designation' => 'E2',
            'name' => 'Engine 1',
        ]));

        $this->assertSame('unmapped', $resolution['checklist_type']);
        $this->assertFalse($resolution['usable']);
        $this->assertSame('ambiguous_specialized_apparatus', $resolution['error']);
    }
}
