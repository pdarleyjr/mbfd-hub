<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\RunsEmsActions;
use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Support\EmsLabels;
use App\Filament\Support\EmsPanelAccess;
use App\Services\Ems\EmsStatistics;
use App\Services\Ems\Exports\StatisticsExporter;
use App\Services\Ems\Exports\StatisticsView;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin -> EMS -> Medical Data / Statistics. Every number comes from EmsStatistics, read from the
 * request tables, and is labelled as requests and issuances, not supplies used on calls. The Excel
 * and PDF files are built from the same data, so they hold exactly what is on screen.
 */
final class MedicalDataStatistics extends Page
{
    use RunsEmsActions;

    public const DEFAULT_DAYS = 90;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'EMS';

    protected static ?string $navigationLabel = 'Medical Data / Statistics';

    protected static ?string $title = 'Medical Data / Statistics';

    protected static ?string $slug = 'medical-statistics';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.admin.pages.medical-data-statistics';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $apparatusId = '';

    public string $targetKind = '';

    public static function canAccess(): bool
    {
        return EmsPanelAccess::canView(auth()->user());
    }

    public function mount(): void
    {
        $this->emsActor('view');
        $this->resetRange();
    }

    /** Runs on every Livewire request, so an open page is refused the moment access is withdrawn. */
    public function hydrate(): void
    {
        $this->emsActor('view');
    }

    public function resetRange(): void
    {
        $this->emsActor('view');
        $today = CarbonImmutable::now(EmsLabels::TIMEZONE)->startOfDay();
        $this->dateFrom = $today->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d');
        $this->dateTo = $today->format('Y-m-d');
    }

    // ------------------------------------------------------------------- filters

    /**
     * The filters as EmsStatistics wants them. A date that is not a real date is dropped (and said so on screen).
     *
     * @return array{date_from: ?string, date_to: ?string, apparatus_id: ?int, target_kind: ?string}
     */
    private function filters(): array
    {
        $kind = array_key_exists($this->targetKind, EmsLabels::TARGET_KIND) ? $this->targetKind : null;

        return [
            'date_from' => $this->realDate($this->dateFrom),
            'date_to' => $this->realDate($this->dateTo),
            'apparatus_id' => ctype_digit($this->apparatusId) && (int) $this->apparatusId > 0 ? (int) $this->apparatusId : null,
            'target_kind' => $kind,
        ];
    }

    private function realDate(string $value): ?string
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, EmsLabels::TIMEZONE) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /** @return list<string> */
    private function problems(): array
    {
        $problems = [];
        foreach (['dateFrom' => 'start', 'dateTo' => 'end'] as $property => $word) {
            if (trim($this->{$property}) !== '' && $this->realDate($this->{$property}) === null) {
                $problems[] = "The {$word} date was not understood, so that end of the range is left open.";
            }
        }
        $filters = $this->filters();
        if ($filters['date_from'] !== null && $filters['date_to'] !== null && $filters['date_from'] > $filters['date_to']) {
            $problems[] = 'The start date is after the end date, so no requests can match.';
        }

        return $problems;
    }

    /** Words for the filters on screen, printed on both files. */
    private function filterText(): string
    {
        $filters = $this->filters();
        $format = fn (?string $date): string => CarbonImmutable::parse((string) $date)->format('M j, Y');
        $range = match (true) {
            $filters['date_from'] !== null && $filters['date_to'] !== null => $format($filters['date_from']).' to '.$format($filters['date_to']),
            $filters['date_from'] !== null => 'From '.$format($filters['date_from']),
            $filters['date_to'] !== null => 'Up to '.$format($filters['date_to']),
            default => 'All dates',
        };
        $unit = $filters['apparatus_id'] !== null ? (InventoryRequestResource::unitOptions()[$filters['apparatus_id']] ?? 'Unit '.$filters['apparatus_id']) : 'All units';
        $kind = $filters['target_kind'] !== null ? EmsLabels::TARGET_KIND[$filters['target_kind']] : 'All kinds of request';

        return "Dates (Eastern): {$range}. Unit: {$unit}. Kind: {$kind}.";
    }

    // ------------------------------------------------------------------ view data

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->emsActor('view');
        $report = app(EmsStatistics::class)->report($this->filters());
        $view = StatisticsView::build($report);
        $monthly = collect($view['sections'])->firstWhere('heading', 'Monthly trend');

        return [
            'headerText' => StatisticsView::HEADER_TEXT,
            'view' => $view,
            'units' => InventoryRequestResource::unitOptions(),
            'kinds' => EmsLabels::TARGET_KIND,
            'problems' => $this->problems(),
            'filterText' => $this->filterText(),
            'monthlyMax' => max(1, (int) collect($monthly->rows ?? [])->max(fn (array $row): mixed => $row[1] ?? null)),
            'definitionsLabel' => EmsStatistics::LABEL,
        ];
    }

    // -------------------------------------------------------------------- exports

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction('exportExcel', 'Export Excel', 'heroicon-o-table-cells', 'xlsx'),
            $this->exportAction('exportPdf', 'Export PDF', 'heroicon-o-document-arrow-down', 'pdf'),
        ];
    }

    private function exportAction(string $name, string $label, string $icon, string $format): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color('gray')
            ->action(fn (): StreamedResponse => $this->streamExport($format));
    }

    public function streamExport(string $format): StreamedResponse
    {
        $this->emsActor('view');
        $exporter = app(StatisticsExporter::class);
        $at = CarbonImmutable::now(EmsLabels::TIMEZONE);
        $report = app(EmsStatistics::class)->report($this->filters());
        $bytes = $format === 'pdf' ? $exporter->pdf($report, $this->filterText(), $at) : $exporter->xlsx($report, $this->filterText(), $at);

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $exporter->filename($format === 'pdf' ? 'pdf' : 'xlsx', $at),
            ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'],
        );
    }
}
