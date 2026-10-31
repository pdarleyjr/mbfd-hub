<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\Apparatus;
use App\Services\Dashboards\Support\ApparatusClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VehicleApparatusClassTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{array<string, mixed>, string, string|null}> */
    public static function units(): iterable
    {
        yield 'engine template' => [['designation' => 'E1', 'type' => 'Engine', 'daily_checkout_template' => 'engine'], 'engine_puc', 'engine'];
        yield 'engine 2 template' => [['designation' => 'E2', 'type' => 'Engine', 'daily_checkout_template' => 'engine2'], 'engine_puc', 'engine'];
        yield 'ladder 1 template' => [['designation' => 'L1', 'type' => 'Ladder', 'daily_checkout_template' => 'ladder1'], 'ladder', 'ladder-1'];
        yield 'ladder 3 template' => [['designation' => 'L3', 'type' => 'Ladder', 'daily_checkout_template' => 'ladder3'], 'ladder', 'ladder-3'];
        yield 'rescue template' => [['designation' => 'R4', 'type' => 'Rescue', 'daily_checkout_template' => 'rescue'], 'rescue', 'rescue'];
        yield 'fire boat template' => [['unit_id' => 'FB6', 'designation' => 'Fire Boat 6', 'type' => 'Other', 'daily_checkout_template' => 'fireboat6'], 'marine', 'fireboat'];
        yield 'rescue by family, no template' => [['designation' => 'R9', 'type' => 'Rescue'], 'rescue', 'rescue'];
        yield 'marine by type has no approved photo' => [['designation' => 'Marine 1', 'type' => 'Marine'], 'marine', null];
        yield 'fire boat by class has no approved photo' => [['designation' => 'Marine 2', 'type' => 'Other', 'class_description' => 'Fire Boats'], 'marine', null];
        yield 'ladder on the default checklist' => [['designation' => 'Ladder 4', 'type' => 'Ladder', 'daily_checkout_template' => 'default'], 'ladder', 'ladder-1'];
        yield 'command vehicle' => [['designation' => 'Battalion Chief 1', 'type' => 'Command'], 'special', null];
        yield 'name alone never classifies' => [['designation' => 'Utility 1', 'name' => 'Rescue support pickup', 'type' => 'Pickup'], 'special', null];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('units')]
    public function test_class_and_image_come_from_structured_fields(array $attributes, string $tag, ?string $image): void
    {
        $apparatus = Apparatus::query()->create(['unit_id' => 'UNIT'.random_int(1000, 9999), 'name' => $attributes['designation'], 'status' => 'In Service', ...$attributes]);
        $classes = app(ApparatusClass::class);

        $this->assertSame(['tag' => $tag, 'imageKey' => $image], $classes->of($apparatus));
        $this->assertSame(['tag' => $tag, 'imageKey' => $image], $classes->all()[$apparatus->id], 'the bulk path agrees');
        $this->assertSame(['tag' => 'special', 'imageKey' => null], $classes->of(null));
    }
}
