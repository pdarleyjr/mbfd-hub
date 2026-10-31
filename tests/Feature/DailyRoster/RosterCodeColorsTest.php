<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Models\DailyRoster\RosterAssignment;
use App\Services\DailyRoster\RosterStatusPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Code colors, read from the TeleStaff online roster on 2026-10-30, so a member sees what TeleStaff shows. */
final class RosterCodeColorsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> code, base code, fill, text */
    public static function codesSeenInTeleStaff(): iterable
    {
        yield 'SP is plain' => ['SP', 'SP', '#FFFFFF', '#000000'];
        yield '+OT' => ['+OT', '+OT', '#00FF00', '#000000'];
        yield 'HOOT' => ['HOOT', 'HOOT', '#00FF00', '#000000'];
        yield 'A-Day' => ['ADONIS DAY', 'ADONIS DAY', '#008EE5', '#000000'];
        yield 'dotted A-Day' => ['.ADONIS DAY', 'ADONIS DAY', '#008EE5', '#000000'];
        yield 'VACA' => ['VACA', 'VACA', '#E6006F', '#FFFFFF'];
        yield 'dotted VACA' => ['.VACA', 'VACA', '#E6006F', '#FFFFFF'];
        yield 'ADMS' => ['ADMS', 'ADMS', '#E60000', '#FFFFFF'];
        yield 'EVAC' => ['EVAC', 'EVAC', '#E60000', '#FFFFFF'];
        yield 'MILNP' => ['MILNP', 'MILNP', '#E60000', '#FFFFFF'];
        yield 'PENFML' => ['.PENFML', 'PENFML', '#E60000', '#FFFFFF'];
        yield 'SIC RVT' => ['SIC RVT', 'SIC RVT', '#AA0000', '#FFFFFF'];
        yield '2WXON' => ['2WXON', '2WXON', '#FFAA56', '#000000'];
        yield '2WXEON' => ['2WXEON', '2WXEON', '#FFAA56', '#000000'];
        yield '2WXOFF' => ['.2WXOFF', '2WXOFF', '#FFAA56', '#000000'];
        yield '2WXEOFF' => ['.2WXEOFF', '2WXEOFF', '#FFAA56', '#000000'];
        yield 'OOC' => ['^OOC', '^OOC', '#00BFBF', '#000000'];
        yield 'PMS' => ['.PMS', 'PMS', '#FF00FF', '#000000'];
    }

    #[DataProvider('codesSeenInTeleStaff')]
    public function test_each_code_has_the_color_telestaff_shows(string $code, string $baseCode, string $fill, string $text): void
    {
        $pill = app(RosterStatusPresenter::class)->present($this->assignment($code, $baseCode));

        self::assertSame(['bg' => $fill, 'ink' => $text], RosterStatusPresenter::TONE_COLORS[$pill->tone]);
    }

    public function test_a_code_it_does_not_know_stays_dashed_and_says_so(): void
    {
        $pill = app(RosterStatusPresenter::class)->present($this->assignment('GWA', 'GWA', RosterAssignment::AVAILABILITY_UNKNOWN));

        self::assertSame('unknown', $pill->tone);
        self::assertSame('Status not recognized', $pill->screenReaderText);
    }

    public function test_a_colored_code_it_cannot_classify_keeps_its_color_and_still_says_it_is_not_recognized(): void
    {
        $pill = app(RosterStatusPresenter::class)->present($this->assignment('.PMS', 'PMS', RosterAssignment::AVAILABILITY_UNKNOWN));

        self::assertSame('pms', $pill->tone);
        self::assertSame('.PMS', $pill->label);
        self::assertSame('Status not recognized', $pill->screenReaderText);
    }

    public function test_color_never_changes_who_counts_as_working(): void
    {
        $presenter = app(RosterStatusPresenter::class);

        self::assertSame('working', $presenter->present($this->assignment('+OT', '+OT'))->screenReaderText);
        self::assertSame('not working', $presenter->present($this->assignment('ADMS', 'ADMS', RosterAssignment::AVAILABILITY_NOT_WORKING))->screenReaderText);
    }

    public function test_the_page_tokens_and_the_pdf_use_the_same_colors(): void
    {
        $theme = (string) file_get_contents(resource_path('css/mbfd-theme.css'));

        foreach (RosterStatusPresenter::TONE_COLORS as $tone => $colors) {
            foreach (['bg', 'ink'] as $part) {
                [$red, $green, $blue] = sscanf($colors[$part], '#%02x%02x%02x');
                self::assertStringContainsString("--hub-roster-{$tone}-{$part}: {$red} {$green} {$blue};", $theme);
            }
        }
    }

    private function assignment(string $code, string $baseCode, string $availability = RosterAssignment::AVAILABILITY_WORKING): RosterAssignment
    {
        return (new RosterAssignment)->forceFill([
            'kind' => RosterAssignment::KIND_EMPLOYEE,
            'raw_code' => $code,
            'base_code' => $baseCode,
            'availability' => $availability,
            'category' => 'Other',
        ]);
    }
}
