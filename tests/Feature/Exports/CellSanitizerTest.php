<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Services\Exports\CellSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CellSanitizerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function dangerous(): array
    {
        return [
            'equals' => ['=SUM(A1:A9)'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at sign' => ['@SUM(1)'],
            'fullwidth equals' => ["\u{FF1D}1+1"],
            'fullwidth plus' => ["\u{FF0B}1"],
            'fullwidth minus' => ["\u{FF0D}1"],
            'fullwidth at' => ["\u{FF20}SUM(1)"],
            'unicode minus sign' => ["\u{2212}1"],
            'small equals variants' => ["\u{FE62}1"],
            'leading space then equals' => ['  =cmd|\' /C calc\'!A0'],
            'leading tab' => ["\t=1+1"],
            'leading carriage return' => ["\r=1+1"],
            'leading line feed' => ["\n@x"],
            'leading null byte' => ["\x00=1"],
            'leading control char before text' => ["\x07hello"],
            'zero width space then equals' => ["\u{200B}=1"],
            'hyperlink formula' => ['=HYPERLINK("http://example.test","click")'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_formula_starts_are_prefixed_with_an_apostrophe(string $value): void
    {
        self::assertSame("'".$value, CellSanitizer::neutralize($value));
    }

    /** @return array<string, array{string}> */
    public static function safe(): array
    {
        return [
            'plain text' => ['Engine 1'],
            'equals in the middle' => ['a=b'],
            'minus in the middle' => ['Station 4 - North'],
            'apostrophe inside' => ["O'Brien"],
            'empty' => [''],
            'leading letter plus digits' => ['E4-2020'],
            'accented text' => ['Rescue Caf'."\u{E9}"],
        ];
    }

    #[DataProvider('safe')]
    public function test_ordinary_text_is_unchanged(string $value): void
    {
        self::assertSame($value, CellSanitizer::neutralize($value));
    }

    public function test_neutralizing_twice_does_not_stack_prefixes(): void
    {
        $once = CellSanitizer::neutralize('=1+1');

        self::assertSame($once, CellSanitizer::neutralize($once));
    }

    public function test_value_only_changes_strings(): void
    {
        self::assertSame(-5, CellSanitizer::value(-5));
        self::assertSame(1.5, CellSanitizer::value(1.5));
        self::assertNull(CellSanitizer::value(null));
        self::assertTrue(CellSanitizer::value(true));
        self::assertSame("'=1", CellSanitizer::value('=1'));
        self::assertSame('-5', CellSanitizer::value('-5'), 'A plain signed number cannot carry a formula.');
        self::assertSame('+2.50', CellSanitizer::value('+2.50'));
        self::assertSame("'-5+cmd", CellSanitizer::value('-5+cmd'));
        self::assertSame("'-", CellSanitizer::value('-'));
    }

    public function test_csv_lines_neutralize_text_and_leave_numbers_alone(): void
    {
        $handle = fopen('php://memory', 'w+');
        CellSanitizer::putCsv($handle, ['=SUM(A1)', 'plain, with comma', -5, '12.50', null, '-', 'say "hi"']);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        self::assertSame('\'=SUM(A1),"plain, with comma",-5,12.50,,\'-,"say ""hi"""'."\n", $line);
    }

    public function test_cell_scrubs_invalid_utf8_and_bounds_length(): void
    {
        self::assertTrue(mb_check_encoding(CellSanitizer::cell("bad \xFF byte"), 'UTF-8'));

        $long = CellSanitizer::cell(str_repeat('x', CellSanitizer::MAX_CELL_LENGTH + 500));

        self::assertLessThan(32767, mb_strlen($long));
        self::assertStringEndsWith('[truncated]', $long);
    }
}
