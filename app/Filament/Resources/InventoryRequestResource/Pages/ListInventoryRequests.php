<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryRequestResource\Pages;

use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Support\EmsLabels;
use App\Models\Ems\InventoryRequest;
use App\Services\Ems\Exports\RequestListExporter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListInventoryRequests extends ListRecords
{
    protected static string $resource = InventoryRequestResource::class;

    public function getTitle(): string
    {
        return 'Submitted Inventory Forms';
    }

    public function getSubheading(): ?string
    {
        return 'Medical Inventory Requests from members. These are the same records Captain 5 works from, and Captain 5 reconciles them on the Captain 5 page. Quantities are what was requested, not what crews used.';
    }

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
        abort_unless(InventoryRequestResource::canViewAny(), 403);
        $exporter = app(RequestListExporter::class);
        $generatedAt = CarbonImmutable::now(EmsLabels::TIMEZONE);
        $requests = $this->exportRequests();
        $filters = $this->describeFilters();

        $bytes = $format === 'xlsx'
            ? $exporter->xlsx($requests, $filters, $generatedAt)
            : $exporter->pdf($requests, $filters, $generatedAt);

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $exporter->filename($format, $generatedAt),
            [
                'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    /** The table as filtered and searched, in a stable order so repeated exports match. */
    /** @return Collection<int, InventoryRequest> */
    private function exportRequests(): Collection
    {
        return $this->getFilteredTableQuery()->reorder()->orderByDesc('submitted_at')->orderBy('id')->get()
            ->whereInstanceOf(InventoryRequest::class);
    }

    /** Words for the active search and filters, "None" when empty. */
    private function describeFilters(): string
    {
        $parts = [];
        $search = trim((string) $this->tableSearch);
        if ($search !== '') {
            $parts[] = 'Search: "'.$search.'"';
        }

        $state = $this->tableFilters ?? [];
        $statuses = array_values(array_filter((array) ($state['status']['values'] ?? [])));
        if ($statuses !== []) {
            $parts[] = 'Status: '.implode(', ', array_map(EmsLabels::status(...), $statuses));
        }
        if (filled($state['apparatus_id']['value'] ?? null)) {
            $parts[] = 'Unit: '.(InventoryRequestResource::unitOptions()[(int) $state['apparatus_id']['value']] ?? $state['apparatus_id']['value']);
        }
        if (filled($state['target_kind']['value'] ?? null)) {
            $parts[] = 'Kind: '.(EmsLabels::TARGET_KIND[$state['target_kind']['value']] ?? $state['target_kind']['value']);
        }
        if (filled($state['submitted']['from'] ?? null)) {
            $parts[] = 'Submitted from '.CarbonImmutable::parse((string) $state['submitted']['from'])->format('M j, Y');
        }
        if (filled($state['submitted']['until'] ?? null)) {
            $parts[] = 'Submitted until '.CarbonImmutable::parse((string) $state['submitted']['until'])->format('M j, Y');
        }
        if (! empty($state['waiting_for_reply']['isActive'])) {
            $parts[] = 'Member message waiting for a reply';
        }

        return $parts === [] ? 'None (all requests)' : implode('; ', $parts);
    }
}
