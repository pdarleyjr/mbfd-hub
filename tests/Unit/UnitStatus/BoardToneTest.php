<?php

declare(strict_types=1);

namespace Tests\Unit\UnitStatus;

use App\Support\UnitStatus\BoardTone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardToneTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string}> */
    public static function cases(): array
    {
        return [
            'available' => [['key' => 'available', 'family' => 'available'], 'available'],
            'dispatched' => [['key' => 'on_call', 'family' => 'dispatched'], 'dispatched'],
            'en route' => [['key' => 'on_call', 'family' => 'enroute'], 'enroute'],
            'on scene and staged' => [['key' => 'on_call', 'family' => 'on_scene'], 'on_scene'],
            'transporting' => [['key' => 'on_call', 'family' => 'transport'], 'transport'],
            'at hospital' => [['key' => 'on_call', 'family' => 'arrived'], 'arrived'],
            'on a call with no known phase' => [['key' => 'on_call', 'family' => 'unknown'], 'assigned'],
            'on a call with a family the board does not know' => [['key' => 'on_call', 'family' => 'red;x'], 'assigned'],
            'not confirmed' => [['key' => 'unknown', 'family' => 'unknown'], 'not_confirmed'],
            'not staffed' => [['key' => 'not_staffed', 'family' => 'not_staffed'], 'idle'],
            'no active call' => [['key' => 'no_active_call', 'family' => 'no_active_call'], 'idle'],
            'nothing at all' => [[], 'idle'],
        ];
    }

    /** @param  array<string, mixed>  $availability */
    #[DataProvider('cases')]
    public function test_each_availability_maps_to_one_board_color(array $availability, string $tone): void
    {
        self::assertSame($tone, BoardTone::for($availability));
    }
}
