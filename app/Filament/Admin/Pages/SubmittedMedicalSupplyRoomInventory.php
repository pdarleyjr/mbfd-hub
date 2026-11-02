<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\EmsLabels;
use App\Filament\Support\EmsPanelAccess;
use App\Services\Ems\Exports\SupplyCountReport;
use App\Services\Ems\Exports\SupplyCountReportExporter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin -> EMS -> Submitted Medical Supply Room Inventory: the Monday counts Captain 5 submitted, newest
 * first, and each one as a report by shelf with what to order. Read only. There is no write action here,
 * and the page never creates or claims a supply order; recording an order placed happens on the Medical
 * Supply Room page. Everything shown comes from the stamped lines of the final count.
 */
final class SubmittedMedicalSupplyRoomInventory extends Page
{
    private const PAGE_SIZE = 25;

    private const SEARCH_MAX = 100;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'EMS';

    protected static ?string $navigationLabel = 'Submitted Medical Supply Room Inventory';

    protected static ?string $title = 'Submitted Medical Supply Room Inventory';

    protected static ?string $slug = 'submitted-medical-supply-room-inventory';

    /** Right after Submitted Inventory Forms (10). */
    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.admin.pages.submitted-medical-supply-room-inventory';

    /** The open report. Never trusted: it only counts when it is one of the listed counts. */
    #[Url(as: 'report')]
    public ?string $reportUuid = null;

    public bool $onlyToOrder = false;

    public string $search = '';

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    public static function canAccess(): bool
    {
        return EmsPanelAccess::canView(auth()->user());
    }

    public function mount(): void
    {
        $this->guard();
        if ($this->reportUuid !== null && SupplyCountReport::find($this->reportUuid) === null) {
            $this->reportUuid = null;
        }
    }

    /** Runs on every Livewire request, so an open page is refused the moment access is withdrawn. */
    public function hydrate(): void
    {
        $this->guard();
    }

    /**
     * The same decision as the other EMS screens (EMS view), asked through EmsPanelAccess so a super
     * administrator costs no queries. Called by every Livewire method, because hiding a button is cosmetic.
     */
    private function guard(): void
    {
        abort_unless(EmsPanelAccess::canView(auth()->user()), 403);
    }

    public function getSubheading(): string
    {
        return 'The Monday counts Captain 5 submitted. Quantities to order are recommendations worked out from the count, not purchase orders.';
    }

    // ------------------------------------------------------------------ view data

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->guard();
        $reports = app(SupplyCountReport::class);
        $snapshot = $this->reportUuid !== null ? SupplyCountReport::find($this->reportUuid) : null;

        if ($snapshot !== null) {
            return [
                'report' => $reports->report($snapshot, $this->onlyToOrder, $this->cleanSearch()),
                'listing' => null,
                'notAPurchaseOrder' => SupplyCountReport::NOT_A_PURCHASE_ORDER, 'dash' => SupplyCountReport::DASH,
                'reorderUrl' => MedicalSupplyRoom::getUrl(['tab' => 'reorder']),
            ];
        }

        return ['report' => null, 'listing' => $reports->listing($this->limit), 'notAPurchaseOrder' => SupplyCountReport::NOT_A_PURCHASE_ORDER, 'dash' => SupplyCountReport::DASH, 'reorderUrl' => null];
    }

    private function cleanSearch(): string
    {
        return mb_substr(trim($this->search), 0, self::SEARCH_MAX);
    }

    // ------------------------------------------------------------------ navigation

    public function openReport(string $uuid): void
    {
        $this->guard();
        abort_if(SupplyCountReport::find($uuid) === null, 404);
        $this->reportUuid = $uuid;
        $this->onlyToOrder = false;
        $this->search = '';
    }

    public function closeReport(): void
    {
        $this->guard();
        $this->reportUuid = null;
        $this->onlyToOrder = false;
        $this->search = '';
    }

    public function showMore(): void
    {
        $this->guard();
        $this->limit = min($this->limit + self::PAGE_SIZE, 500);
    }

    // --------------------------------------------------------------------- exports

    /** Excel or PDF of the open report, private and never cached. */
    public function exportReportAction(): Action
    {
        return Action::make('exportReport')->color('gray')->size('sm')->outlined()
            ->label(fn (array $arguments): string => ($arguments['format'] ?? 'xlsx') === 'pdf' ? 'Export PDF' : 'Export Excel')
            ->icon(fn (array $arguments): string => ($arguments['format'] ?? 'xlsx') === 'pdf' ? 'heroicon-o-document-arrow-down' : 'heroicon-o-table-cells')
            ->action(function (array $arguments): StreamedResponse {
                $this->guard();
                $snapshot = SupplyCountReport::find((string) ($arguments['report'] ?? $this->reportUuid ?? ''));
                abort_if($snapshot === null, 404);
                // The file carries the "only items to order" choice; the search box only narrows the screen.
                $report = app(SupplyCountReport::class)->report($snapshot, $this->onlyToOrder);
                $exporter = app(SupplyCountReportExporter::class);
                $pdf = ($arguments['format'] ?? 'xlsx') === 'pdf';
                $at = CarbonImmutable::now(EmsLabels::TIMEZONE);
                $bytes = $pdf ? $exporter->pdf($report, $at) : $exporter->xlsx($report, $at);

                return response()->streamDownload(
                    static function () use ($bytes): void {
                        echo $bytes;
                    },
                    $exporter->filename($report, $pdf ? 'pdf' : 'xlsx'),
                    [
                        'Content-Type' => $pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'Cache-Control' => 'private, no-store',
                    ],
                );
            });
    }
}
