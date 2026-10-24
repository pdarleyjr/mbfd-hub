<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Models\InventoryLocation;
use App\Services\Exports\ExportCell;
use App\Services\Exports\ExportColumnReader;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/** Cell typing rules, checked on in-memory records so every case is explicit. */
final class ExportColumnReaderTypesTest extends TestCase
{
    /** @param  array<string, mixed>  $attributes */
    private function cell(Column $column, array $attributes): ExportCell
    {
        $record = new InventoryLocation;
        foreach ($attributes as $key => $value) {
            $record->setAttribute($key, $value);
        }

        return (new ExportColumnReader)->row([$column], $record)[0];
    }

    public function test_a_date_cast_value_stays_the_same_calendar_day(): void
    {
        // A "date" attribute arrives as midnight UTC; New York conversion would show the evening before.
        $cell = $this->cell(TextColumn::make('when')->dateTime(), ['when' => CarbonImmutable::parse('2026-03-05 00:00:00', 'UTC')]);

        self::assertSame(ExportCell::DATE, $cell->kind);
        self::assertSame('Mar 5, 2026', $cell->display());
    }

    public function test_timestamps_become_new_york_time_and_date_only_columns_use_the_new_york_day(): void
    {
        $instant = CarbonImmutable::parse('2026-01-15 03:30:00', 'UTC');

        $dateTime = $this->cell(TextColumn::make('when')->dateTime(), ['when' => $instant]);
        self::assertSame(ExportCell::DATETIME, $dateTime->kind);
        self::assertSame('Jan 14, 2026 10:30 PM', $dateTime->display());

        $dateOnly = $this->cell(TextColumn::make('when')->date(), ['when' => $instant]);
        self::assertSame(ExportCell::DATE, $dateOnly->kind);
        self::assertSame('Jan 14, 2026', $dateOnly->display());
    }

    public function test_numbers_stay_numbers_only_when_the_table_shows_that_number(): void
    {
        $days = $this->cell(TextColumn::make('days')->suffix(' days'), ['days' => 45]);
        self::assertSame(ExportCell::NUMBER, $days->kind);
        self::assertSame(45, $days->value);

        $money = $this->cell(TextColumn::make('cost')->money('USD'), ['cost' => 1250.5]);
        self::assertSame(ExportCell::NUMBER, $money->kind);

        $cents = $this->cell(TextColumn::make('cost')->money('USD', divideBy: 100), ['cost' => 125050]);
        self::assertSame(ExportCell::TEXT, $cents->kind, 'The shown amount differs from the stored cents, so the shown text is kept.');
        self::assertSame('$1,250.50', $cents->value);

        foreach ([['Medic ', 5], ['E-', 12], ['#', 1042]] as [$prefix, $number]) {
            $label = $this->cell(TextColumn::make('unit')->prefix($prefix), ['unit' => $number]);
            self::assertSame(ExportCell::TEXT, $label->kind, $prefix.$number.' is a label, not a quantity.');
            self::assertSame($prefix.$number, $label->value);
        }

        $long = $this->cell(TextColumn::make('id'), ['id' => 1234567890123456789]);
        self::assertSame(ExportCell::TEXT, $long->kind, 'More than 15 digits would lose precision as a spreadsheet number.');
        self::assertSame('1234567890123456789', $long->value);
    }

    public function test_numeric_strings_stay_text_so_leading_zeros_survive(): void
    {
        $cell = $this->cell(TextColumn::make('serial'), ['serial' => '00123']);

        self::assertSame(ExportCell::TEXT, $cell->kind);
        self::assertSame('00123', $cell->value);
    }

    public function test_booleans_lists_and_blanks(): void
    {
        self::assertSame('Yes', $this->cell(IconColumn::make('on')->boolean(), ['on' => true])->value);
        self::assertSame('No', $this->cell(IconColumn::make('on')->boolean(), ['on' => false])->value);
        self::assertSame('Yes', $this->cell(TextColumn::make('on'), ['on' => true])->value);
        self::assertSame('Alpha, Bravo', $this->cell(TextColumn::make('tags'), ['tags' => ['Alpha', 'Bravo']])->value);
        self::assertTrue($this->cell(TextColumn::make('missing'), [])->isBlank());
        self::assertTrue($this->cell(TextColumn::make('empty'), ['empty' => ''])->isBlank());
    }

    public function test_markup_is_removed_only_from_html_columns(): void
    {
        $html = $this->cell(TextColumn::make('note')->html(), ['note' => '<b>Bold</b> text<br>next line']);
        self::assertSame("Bold text\nnext line", $html->value);

        $htmlable = $this->cell(TextColumn::make('note')->formatStateUsing(fn (): Htmlable => new HtmlString('<i>Rich</i>')), ['note' => 'x']);
        self::assertSame('Rich', $htmlable->value);

        $plain = $this->cell(TextColumn::make('note'), ['note' => 'tire psi <50 and >20']);
        self::assertSame('tire psi <50 and >20', $plain->value, 'Plain text with angle brackets is not markup.');
    }
}
