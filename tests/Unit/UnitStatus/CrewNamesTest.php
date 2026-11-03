<?php

declare(strict_types=1);

namespace Tests\Unit\UnitStatus;

use App\Support\UnitStatus\RankAbbreviator;
use App\Support\UnitStatus\ShortName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CrewNamesTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function ranks(): array
    {
        return [
            'firefighter' => ['Firefighter', 'FF'],
            'firefighter de' => ['Firefighter DE', 'DE'],
            'lieutenant' => ['Lieutenant', 'Lt.'],
            'combat lieutenant' => ['Combat Lieutenant', 'Lt.'],
            'captain' => ['Captain', 'Capt.'],
            'combat captain' => ['Combat Captain', 'Capt.'],
            'captain pm' => ['Captain PM', 'Capt.'],
            'marine captain' => ['Marine Captain', 'Capt.'],
            'division chief' => ['Division Chief', 'DC'],
            'battalion chief' => ['Battalion Chief', 'BC'],
            'leading dot' => ['.Firefighter', 'FF'],
            'leading dot de' => ['.Firefighter DE', 'DE'],
            'case' => ['LIEUTENANT', 'Lt.'],
            'spacing' => ['  Combat   Captain ', 'Capt.'],
            'unknown as written' => ['Driver Engineer', 'Driver Engineer'],
            'unknown with dot' => ['.Probationary', 'Probationary'],
            'null' => [null, null],
            'blank' => ['  ', null],
            'only dots' => ['..', null],
        ];
    }

    #[DataProvider('ranks')]
    public function test_ranks_are_abbreviated(?string $rank, ?string $expected): void
    {
        self::assertSame($expected, RankAbbreviator::abbreviate($rank));
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string}> */
    public static function names(): array
    {
        return [
            'comma' => ['Lieutenant', 'Benson, Mark', 'Lt. Benson'],
            'suffix before the comma stays whole' => ['Firefighter DE', 'Yacum Jr, David', 'DE Yacum Jr'],
            'no rank is surname only' => [null, 'Yacum Jr, David', 'Yacum Jr'],
            'leading dot rank' => ['.Firefighter', 'Smith, Pat', 'FF Smith'],
            'casing as written' => ['Captain', 'BENSON, MARK', 'Capt. BENSON'],
            'no comma uses the last word' => ['Lieutenant', 'Mark Benson', 'Lt. Benson'],
            'no comma keeps Jr with the word before' => ['Firefighter DE', 'David Yacum Jr', 'DE Yacum Jr'],
            'no comma keeps Sr. with the word before' => [null, 'John Smith Sr.', 'Smith Sr.'],
            'no comma keeps III' => [null, 'Jo Smith III', 'Smith III'],
            'single word' => ['Captain', 'Benson', 'Capt. Benson'],
            'unknown rank as written' => ['Driver Engineer', 'Benson, Mark', 'Driver Engineer Benson'],
        ];
    }

    #[DataProvider('names')]
    public function test_short_names(?string $rank, string $display, string $expected): void
    {
        self::assertSame($expected, ShortName::from($rank, $display));
    }

    public function test_only_the_surname_comes_out(): void
    {
        $short = ShortName::from('Firefighter', 'Yacum Jr, David Michael');

        foreach (['David', 'Michael', '(', ','] as $leak) {
            self::assertStringNotContainsString($leak, $short);
        }
    }
}
