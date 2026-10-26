<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Exports\EvaluationSubmissionExporter;
use App\Filament\Exports\SafeExporter;
use App\Filament\Exports\SingleGasMetersExporter;
use App\Filament\Workgroup\Exports\WorkgroupAIReportExporter;
use App\Models\Apparatus;
use App\Models\CandidateProduct;
use App\Models\Employee;
use App\Models\SingleGasMeter;
use Carbon\CarbonImmutable;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Tests\TestCase;

final class NativeExportersSanitizedTest extends TestCase
{
    public function test_every_native_filament_exporter_uses_the_sanitizing_base_class(): void
    {
        $classes = [];
        foreach ([app_path('Filament/Exports'), app_path('Filament/Workgroup/Exports')] as $directory) {
            foreach (glob($directory.'/*Exporter.php') ?: [] as $file) {
                $classes[] = str_replace(['/', '.php'], ['\\', ''], 'App\\'.ltrim(str_replace(app_path(), '', $file), '/\\'));
            }
        }
        $classes = array_values(array_filter(array_map(static fn (string $class): string => str_replace('\\\\', '\\', $class), $classes), static fn (string $class): bool => $class !== SafeExporter::class));

        self::assertContains(SingleGasMetersExporter::class, $classes);
        self::assertContains(EvaluationSubmissionExporter::class, $classes);
        self::assertGreaterThanOrEqual(7, count($classes), 'SingleGasMeters, EvaluationSubmission and the five Workgroup exporters.');
        foreach ($classes as $class) {
            self::assertTrue(is_subclass_of($class, SafeExporter::class), $class.' must extend '.SafeExporter::class);
        }
    }

    public function test_exported_rows_neutralize_formula_text_and_keep_numbers(): void
    {
        $exporter = new class(new Export, ['name' => 'Name', 'plus' => 'Plus', 'fullwidth' => 'Fullwidth', 'count' => 'Count', 'plain' => 'Plain', 'none' => 'None'], []) extends SafeExporter
        {
            protected static ?string $model = Employee::class;

            public static function getColumns(): array
            {
                return [
                    ExportColumn::make('name')->state(fn (): string => '=HYPERLINK("http://example.test","x")'),
                    ExportColumn::make('plus')->state(fn (): string => '+1+1'),
                    ExportColumn::make('fullwidth')->state(fn (): string => "\u{FF1D}2+2"),
                    ExportColumn::make('count')->state(fn (): int => -5),
                    ExportColumn::make('plain')->state(fn (): string => 'Engine 1'),
                    ExportColumn::make('none')->state(fn (): ?string => null),
                ];
            }

            public static function getCompletedNotificationBody(Export $export): string
            {
                return '';
            }
        };

        $row = $exporter(new Employee);

        self::assertSame(['\'=HYPERLINK("http://example.test","x")', "'+1+1", "'\u{FF1D}2+2", '-5', 'Engine 1', null], $row);
    }

    public function test_the_shipped_single_gas_meter_exporter_neutralizes_text_from_the_database(): void
    {
        $meter = new SingleGasMeter(['serial_number' => '@SUM(1)']);
        $meter->setRelation('apparatus', new Apparatus(['designation' => '=cmd|calc']));
        $map = ['apparatus.designation' => 'Apparatus Unit', 'serial_number' => 'Serial Number'];

        $row = (new SingleGasMetersExporter(new Export, $map, []))($meter);

        self::assertSame(["'=cmd|calc", "'@SUM(1)"], $row);
    }

    public function test_workgroup_report_generation_stamp_uses_the_eastern_day_at_midnight(): void
    {
        foreach ([
            '2026-10-26T03:59:59Z' => '2026-10-25 23:59:59 EDT',
            '2026-10-26T04:00:00Z' => '2026-10-26 00:00:00 EDT',
        ] as $instant => $label) {
            $this->travelTo(CarbonImmutable::parse($instant));
            $row = (new WorkgroupAIReportExporter(new Export, ['exported_at' => 'Report Generated'], []))(new CandidateProduct);

            self::assertSame([$label], $row);
        }
    }
}
