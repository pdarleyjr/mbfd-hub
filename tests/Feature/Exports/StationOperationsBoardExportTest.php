<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\SecurityActionEvent;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StationOperationsBoardExportTest extends ExportTestCase
{
    private function boardFixture(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-08T12:00:00-04:00'));
        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => 'Test address', 'is_active' => true]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'E1', 'designation' => 'E1', 'vehicle_number' => 'E1',
            'make' => 'Fixture', 'model' => 'Engine', 'status' => 'In Service', 'daily_checkout_requirement' => 'required',
            'current_engine_hours' => 100, 'last_pm_engine_hours' => 0, 'pm_interval_hours' => 300,
        ]);
        ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id, 'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Fixture Operator', 'rank' => 'Firefighter', 'shift' => 'A-Day', 'unit_number' => 'E1',
            'review_status' => 'approved', 'processing_status' => 'accepted',
            'completed_at' => CarbonImmutable::parse('2026-03-08T13:30:00Z'),
        ]);
    }

    public function test_board_excel_contains_the_underlying_fleet_activity_queues_and_eastern_timestamps(): void
    {
        $this->boardFixture();
        $component = Livewire::test(StationOperationsHubWidget::class)->call('exportBoard', 'xlsx')
            ->assertFileDownloaded('MBFD - Station Operations Board - 2026-03-08.xlsx');
        $book = $this->workbook($component);
        $sheet = $book->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);
        $text = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach (['Fleet summary', 'Fleet', 'Activity', 'Checkouts', 'Inspection queue summary', 'Station 1', 'E1'] as $label) {
            self::assertStringContainsString($label, $text);
        }
        self::assertStringContainsString('9:30', $text);
        self::assertSame(1, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'allowed')->count());
    }

    public function test_board_pdf_contains_data_and_its_operational_date_range(): void
    {
        $this->boardFixture();
        $component = Livewire::test(StationOperationsHubWidget::class)->call('exportBoard', 'pdf')
            ->assertFileDownloaded('MBFD - Station Operations Board - 2026-03-08.pdf');
        $text = (new Parser)->parseContent($this->downloadedBytes($component))->getText();
        foreach (['Station Operations Board', 'Operational window:', 'America/New_York', 'Station 1', 'E1', '9:30 AM'] as $label) {
            self::assertStringContainsString($label, $text);
        }
    }

    #[DataProvider('formats')]
    public function test_board_download_rechecks_source_permissions_after_the_dashboard_was_opened(string $format): void
    {
        $this->boardFixture();
        $board = app(StationOperationsHubWidget::class);
        self::assertTrue($board->canExportBoard());
        $limited = $this->userWith('admin.access', 'admin.stations.view');
        $this->actingAs($limited);
        self::assertFalse($board->canExportBoard());
        try {
            $board->exportBoard($format);
            self::fail('An actor without access to every source received a board snapshot.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(0, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'allowed')->count());
        self::assertSame(1, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'denied')->count());
    }

    public static function formats(): array
    {
        return [['xlsx'], ['pdf']];
    }
}
