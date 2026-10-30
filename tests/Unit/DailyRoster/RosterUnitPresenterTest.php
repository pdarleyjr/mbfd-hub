<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster;

use App\Services\DailyRoster\RosterUnitImage;
use App\Services\DailyRoster\RosterUnitPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RosterUnitPresenterTest extends TestCase
{
    private const PUBLIC_PATH = __DIR__.'/../../../public';

    /** @return array<string, array{string, string, string}> */
    public static function mappedUnits(): array
    {
        return [
            'engine' => ['Engine 1', 'engine', RosterUnitImage::CUTOUT],
            'two digit engine' => ['Engine 11', 'engine', RosterUnitImage::CUTOUT],
            'ladder 1' => ['Ladder 1', 'ladder-1', RosterUnitImage::CUTOUT],
            'ladder 3' => ['Ladder 3', 'ladder-3', RosterUnitImage::CUTOUT],
            'rescue' => ['Rescue 22', 'rescue', RosterUnitImage::CUTOUT],
            'fire boat' => ['Fire Boat 5', 'fireboat', RosterUnitImage::CUTOUT],
            '300' => ['Division Chief 300', 'command-300', RosterUnitImage::PHOTO],
            'captain 5' => ['Captain 5', 'captain-5', RosterUnitImage::PHOTO],
            'surrounding space' => [' Engine 2 ', 'engine', RosterUnitImage::CUTOUT],
        ];
    }

    #[DataProvider('mappedUnits')]
    public function test_a_named_unit_gets_its_image(string $label, string $name, string $kind): void
    {
        $image = (new RosterUnitPresenter(self::PUBLIC_PATH))->present($label);

        self::assertSame($kind, $image->kind);
        self::assertStringContainsString("/images/apparatus/roster/{$name}-", (string) $image->src);
        self::assertStringEndsWith(' 2x', (string) $image->srcset);
    }

    /** @return array<string, array{string}> */
    public static function unmappedUnits(): array
    {
        return array_map(static fn (string $label): array => [$label], [
            'air truck' => 'Air Truck 810',
            'rescue float' => 'Rescue Float',
            'marine float' => 'Marine Float',
            'float pool' => 'Firefighter Float Pool',
            'future ladder' => 'Ladder 2',
            'rescue with suffix' => 'Rescue 1 Float',
            'engine prefix' => 'Reserve Engine 1',
            'lowercase' => 'engine 1',
            'other chief' => 'Division Chief 301',
            'other captain' => 'Captain 55',
            'empty' => '',
        ]);
    }

    #[DataProvider('unmappedUnits')]
    public function test_anything_else_gets_the_pictogram_never_a_vehicle(string $label): void
    {
        $image = (new RosterUnitPresenter(self::PUBLIC_PATH))->present($label);

        self::assertSame(RosterUnitImage::PICTOGRAM, $image->kind);
        self::assertNull($image->src);
    }

    public function test_a_missing_file_falls_back_to_the_pictogram(): void
    {
        $image = (new RosterUnitPresenter(sys_get_temp_dir().'/roster-images-that-do-not-exist'))->present('Engine 1');

        self::assertSame(RosterUnitImage::PICTOGRAM, $image->kind);
    }

    public function test_declared_dimensions_match_the_files(): void
    {
        $presenter = new RosterUnitPresenter(self::PUBLIC_PATH);
        foreach (self::mappedUnits() as [$label]) {
            $image = $presenter->present($label);
            $size = getimagesize(self::PUBLIC_PATH.$image->src);

            self::assertIsArray($size, $label);
            self::assertSame([$image->width, $image->height], [$size[0], $size[1]], $label);
        }
    }
}
