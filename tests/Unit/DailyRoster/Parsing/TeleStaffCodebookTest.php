<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster\Parsing;

use App\Services\DailyRoster\Parsing\TeleStaffCodebook;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TeleStaffRosterWorkbookFactory;
use Tests\TestCase;

final class TeleStaffCodebookTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: ?string, 3: string, 4: string, 5: string}> */
    public static function tableCases(): array
    {
        $mark = "\u{2927}";

        return [
            'SP' => ['SP', 'SP', null, 'working', 'Scheduled', 'SP'],
            '+OT' => ['+OT', '+OT', null, 'working', 'Overtime', '+OT'],
            'OT' => ['OT', 'OT', null, 'working', 'Overtime', 'OT'],
            '2WXON' => ['2WXON', '2WXON', null, 'working', 'Exchange', '2WXON'],
            '2WXEON' => ['2WXEON', '2WXEON', null, 'working', 'Exchange', '2WXEON'],
            'marked 2WXON' => ["{$mark}2WXON", '2WXON', $mark, 'working', 'Exchange', '2WXON'],
            'marked 2WXEON' => ["{$mark}2WXEON", '2WXEON', $mark, 'working', 'Exchange', '2WXEON'],
            'ADONIS DAY' => ['ADONIS DAY', 'ADONIS DAY', null, 'not_working', 'A-Day', 'A-Day'],
            'dot ADONIS DAY' => ['.ADONIS DAY', 'ADONIS DAY', '.', 'not_working', 'A-Day', 'A-Day'],
            'VACA' => ['VACA', 'VACA', null, 'not_working', 'Vacation', 'VACA'],
            'dot VACA' => ['.VACA', 'VACA', '.', 'not_working', 'Vacation', 'VACA'],
            'VAC' => ['VAC', 'VAC', null, 'not_working', 'Vacation', 'VAC'],
            'dot VAC' => ['.VAC', 'VAC', '.', 'not_working', 'Vacation', 'VAC'],
            'EVAC' => ['EVAC', 'EVAC', null, 'not_working', 'Leave', 'EVAC'],
            '2WXOFF' => ['2WXOFF', '2WXOFF', null, 'not_working', 'Exchange off', '2WXOFF'],
            'dot 2WXOFF' => ['.2WXOFF', '2WXOFF', '.', 'not_working', 'Exchange off', '2WXOFF'],
            'marked 2WXOFF' => ["{$mark}2WXOFF", '2WXOFF', $mark, 'not_working', 'Exchange off', '2WXOFF'],
            'dot 2WXEOFF' => ['.2WXEOFF', '2WXEOFF', '.', 'not_working', 'Exchange off', '2WXEOFF'],
            'marked 2WXEOFF' => ["{$mark}2WXEOFF", '2WXEOFF', $mark, 'not_working', 'Exchange off', '2WXEOFF'],
            'ADMS' => ['ADMS', 'ADMS', null, 'not_working', 'Leave', 'ADMS'],
            'MILNP' => ['MILNP', 'MILNP', null, 'not_working', 'Leave', 'MILNP'],
            'SIC' => ['SIC', 'SIC', null, 'not_working', 'Sick', 'SIC'],
            'SIC RVT' => ['SIC RVT', 'SIC RVT', null, 'not_working', 'Sick', 'SIC RVT'],
            'dot PENFML' => ['.PENFML', 'PENFML', '.', 'not_working', 'Leave', '.PENFML'],
            'dot DAYOFF' => ['.DAYOFF', 'DAYOFF', '.', 'not_working', 'Day off', 'Day off'],
            '^OOC' => ['^OOC', '^OOC', null, 'annotation', 'Incentive', '^OOC'],
            '^ROC' => ['^ROC', '^ROC', null, 'annotation', 'Incentive', '^ROC'],
            '^MOC' => ['^MOC', '^MOC', null, 'annotation', 'Incentive', '^MOC'],
            '^TOC' => ['^TOC', '^TOC', null, 'annotation', 'Incentive', '^TOC'],
        ];
    }

    #[DataProvider('tableCases')]
    public function test_it_classifies_every_table_code(string $raw, string $base, ?string $prefix, string $availability, string $category, string $label): void
    {
        $result = TeleStaffCodebook::classify($raw);

        $this->assertSame($raw, $result->rawCode);
        $this->assertSame($base, $result->baseCode);
        $this->assertSame($prefix, $result->prefix);
        $this->assertSame($availability, $result->availability);
        $this->assertSame($category, $result->category);
        $this->assertSame($label, $result->displayLabel);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    public static function unknownCases(): array
    {
        return [
            'GWA' => ['GWA', 'GWA', null],
            'TRNADM' => ['TRNADM', 'TRNADM', null],
            'dot PMS keeps the prefix' => ['.PMS', 'PMS', '.'],
            '+OTD15FI' => ['+OTD15FI', '+OTD15FI', null],
            'a dot does not make SP working' => ['.SP', 'SP', '.'],
            'an exchange mark does not make SP working' => ["\u{2927}SP", 'SP', "\u{2927}"],
            'a dot is not listed on 2WXON' => ['.2WXON', '2WXON', '.'],
            'lower case is a different code' => ['sp', 'sp', null],
            'a caret is part of the code' => ['^XYZ', '^XYZ', null],
        ];
    }

    #[DataProvider('unknownCases')]
    public function test_anything_else_is_unknown_and_shown_verbatim(string $raw, string $base, ?string $prefix): void
    {
        $result = TeleStaffCodebook::classify($raw);

        $this->assertSame('unknown', $result->availability);
        $this->assertSame('Other', $result->category);
        $this->assertSame($raw, $result->displayLabel);
        $this->assertSame($base, $result->baseCode);
        $this->assertSame($prefix, $result->prefix);
    }

    /** The Crew Planner reads availability straight from these classes, so what each code means to it stays pinned. */
    public function test_the_codes_the_crew_planner_depends_on_keep_their_availability(): void
    {
        $expected = [
            'working' => ['SP', '+OT', 'OT', '2WXON', '2WXEON'],
            'not_working' => ['2WXOFF', 'ADONIS DAY', 'VAC', 'VACA', 'EVAC', 'SIC'],
            'annotation' => ['^OOC', '^ROC', '^MOC', '^TOC'],
        ];
        foreach ($expected as $availability => $codes) {
            foreach ($codes as $value) {
                $this->assertSame($availability, TeleStaffCodebook::classify($value)->availability, "Availability differs for {$value}");
            }
        }
    }

    public function test_the_factory_covers_every_observed_code(): void
    {
        foreach (TeleStaffRosterWorkbookFactory::OBSERVED_CODES as $code) {
            $this->assertSame($code, TeleStaffCodebook::classify($code)->rawCode);
        }
        $this->assertCount(21, TeleStaffRosterWorkbookFactory::SECTION_TITLES);
    }
}
