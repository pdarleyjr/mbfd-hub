<?php

declare(strict_types=1);

namespace Tests\Unit\OperationalForms;

use App\Services\OperationalForms\FormRegistry;
use Tests\TestCase;

class FormRegistryTest extends TestCase
{
    public function test_controlled_assets_match_their_manifests(): void
    {
        $registry = app(FormRegistry::class);

        foreach (['ics_214', 'froc_log_001_ff'] as $type) {
            $definition = $registry->get($type);
            $manifest = $definition->manifest();

            $this->assertSame($manifest['template_sha256'], hash_file('sha256', $definition->templatePath()));
            $this->assertSame($manifest['mapping_sha256'], hash_file('sha256', $definition->mappingPath()));
            $this->assertCount($manifest['expected_field_count'], $definition->mapping()['fields']);
        }
    }

    public function test_registry_exposes_the_controlled_capacities(): void
    {
        $registry = app(FormRegistry::class);

        $this->assertSame(['resources' => 8, 'activities' => 24], $registry->get('ics_214')->capacities());
        $this->assertSame([
            'team_members' => 14,
            'labor' => 13,
            'equipment_hours' => 6,
            'vehicle_mileage' => 2,
            'materials' => 7,
            'additional_notes' => 28,
        ], $registry->get('froc_log_001_ff')->capacities());
    }
}
