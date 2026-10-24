<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\SupplyRoomCounts;
use App\Filament\Admin\Pages\Concerns\SupplyRoomStockTable;
use App\Filament\Concerns\RunsEmsActions;
use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Support\EmsLabels;
use App\Filament\Support\EmsPanelAccess;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyOrder;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\Exports\SnapshotExporter;
use App\Services\Ems\ReconciliationService;
use App\Services\Ems\ReorderAdvisor;
use App\Services\Ems\SnapshotPresenter;
use App\Services\Ems\SupplyOrderService;
use App\Services\Ems\SupplyRoomQuery;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin -> EMS -> Medical Supply Room: stock on hand, counts, reconciliation, reorder advice and
 * orders, the stock ledger and saved snapshots. Reading needs the EMS view decision; counting,
 * reconciling and ordering need act; changing an item's threshold or package size needs configure.
 * Every write goes through the EMS services, which check access again.
 */
final class MedicalSupplyRoom extends Page implements HasTable
{
    use InteractsWithTable, SupplyRoomStockTable {
        SupplyRoomStockTable::table insteadof InteractsWithTable;
    }
    use RunsEmsActions;
    use SupplyRoomCounts;

    public const TABS = [
        'stock' => 'Stock',
        'counts' => 'Counts',
        'reconcile' => 'Reconcile',
        'reorder' => 'Reorder',
        'movements' => 'Movements',
        'snapshots' => 'Snapshots',
    ];

    private const MOVEMENTS_PER_PAGE = 25;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'EMS';

    protected static ?string $navigationLabel = 'Medical Supply Room';

    protected static ?string $title = 'Medical Supply Room';

    protected static ?string $slug = 'medical-supply-room';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.admin.pages.medical-supply-room';

    #[Url(as: 'tab')]
    public string $tab = 'stock';

    /** The newest stock movement the previewed reconciliation covers; sent back when it is confirmed. */
    #[Locked]
    public ?int $previewLastMovementId = null;

    public bool $changesOnly = true;

    public string $movementType = '';

    public int $movementsPage = 1;

    public string $orderStatus = 'placed';

    #[Locked]
    public ?string $snapshotUuid = null;

    public static function canAccess(): bool
    {
        return EmsPanelAccess::canView(auth()->user());
    }

    public function mount(): void
    {
        $this->emsActor('view');
        $this->tab = $this->normalTab($this->tab);
    }

    /** Runs on every Livewire request, so an open page is refused the moment access is withdrawn. */
    public function hydrate(): void
    {
        $this->emsActor('view');
    }

    public function getSubheading(): ?string
    {
        return 'Stock on hand, counts, reorders and orders for the Captain 5 supply room. A request never changes stock. Only issuing, receiving, adjusting, counting and reconciling do.';
    }

    public function setTab(string $tab): void
    {
        $this->emsActor('view');
        $this->tab = $this->normalTab($tab);
    }

    private function normalTab(string $tab): string
    {
        return array_key_exists($tab, self::TABS) ? $tab : 'stock';
    }

    // ------------------------------------------------------------------ view data

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->emsActor('view');
        $tab = $this->normalTab($this->tab);

        return [
            'tabs' => self::TABS,
            'canAct' => $this->emsCan('act'),
            'canConfigure' => $this->emsCan('configure'),
            'supplierNote' => SupplyRoomQuery::SUPPLIER_NOTE,
            'controlled' => $tab === 'stock' ? $this->controlledItems() : [],
        ] + match ($tab) {
            'counts' => $this->countsData(),
            'reconcile' => $this->reconcileData(),
            'reorder' => $this->reorderData(),
            'movements' => $this->movementsData(),
            'snapshots' => $this->snapshotsData(),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function controlledItems(): array
    {
        return SupplyItem::query()->with('location:id,code')->where('active', true)->where('controlled_substance', true)->orderBy('name')->get()
            ->map(fn (SupplyItem $item): array => ['name' => $item->name, 'location' => $item->location?->code])->all();
    }

    /** @return array<string, mixed> */
    private function countsData(): array
    {
        $drafts = InventorySnapshot::query()->where('kind', InventorySnapshot::KIND_COUNT)->where('status', InventorySnapshot::DRAFT)
            ->withCount('lines')->orderByDesc('id')->get()
            ->map(fn (InventorySnapshot $s): array => [
                'id' => (int) $s->getKey(), 'scope' => $s->scope_location_code ?? 'All locations',
                'started' => EmsLabels::et($s->started_at), 'entered' => (int) $s->lines_count,
            ])->all();

        return ['drafts' => $drafts, 'detail' => $this->openCountDetail()];
    }

    /** @return array<string, mixed> */
    private function reconcileData(): array
    {
        $preview = app(ReconciliationService::class)->preview();
        $this->previewLastMovementId = (int) $preview['last_movement_id'];
        $rows = collect($preview['rows']);
        // "No earlier count" and "size not stated" describe the item, not this reconciliation, so they do not
        // make a row worth showing (the first is summarized above the table). Movements and real problems do.
        $shown = $this->changesOnly
            ? $rows->filter(fn (array $r): bool => $r['movement_count'] > 0 || array_intersect($r['warnings'], ['negative_result', 'unmatched_issuances']) !== [])->values()
            : $rows;

        return [
            'preview' => $preview,
            'previewRows' => $shown->all(),
            'hiddenRows' => $rows->count() - $shown->count(),
            'previousCount' => $preview['previous_snapshot'] !== null ? EmsLabels::et($preview['previous_snapshot']['finalized_at'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function reorderData(): array
    {
        $advice = app(ReorderAdvisor::class)->advise();
        $status = array_key_exists($this->orderStatus, EmsLabels::ORDER_STATUS) ? $this->orderStatus : '';

        return [
            'advice' => $advice,
            'orders' => array_map(fn (array $o): array => $o + [
                'placed_text' => EmsLabels::et($o['placed_at']), 'received_text' => EmsLabels::et($o['received_at']),
                'status_text' => EmsLabels::ORDER_STATUS[$o['status']] ?? $o['status'],
            ], app(SupplyRoomQuery::class)->orders(['status' => $status])),
            'orderStatuses' => EmsLabels::ORDER_STATUS,
        ];
    }

    /** @return array<string, mixed> */
    private function movementsData(): array
    {
        $result = app(SupplyRoomQuery::class)->movements(['type' => $this->movementType, 'page' => $this->movementsPage, 'per_page' => self::MOVEMENTS_PER_PAGE]);
        $requestIds = collect($result['movements'])->pluck('request_id')->filter()->unique()->all();
        $requests = InventoryRequest::query()->whereIn('id', $requestIds)->get(['id', 'uuid', 'number'])->keyBy('id');

        return [
            'movements' => array_map(fn (array $m): array => $m + [
                'at' => EmsLabels::et($m['occurred_at']),
                'type_text' => EmsLabels::MOVEMENT[$m['movement_type']] ?? $m['movement_type'],
                'request_number' => $requests->get($m['request_id'])?->number,
                'request_url' => $requests->has($m['request_id']) ? InventoryRequestResource::getUrl('view', ['record' => $requests->get($m['request_id'])->uuid]) : null,
            ], $result['movements']),
            'movementMeta' => $result['meta'],
            'movementTypes' => EmsLabels::MOVEMENT,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotsData(): array
    {
        $presenter = app(SnapshotPresenter::class);
        $list = app(SupplyRoomQuery::class)->snapshots([], $presenter);
        $detail = null;
        if ($this->snapshotUuid !== null) {
            $snapshot = InventorySnapshot::query()->where('uuid', $this->snapshotUuid)->first();
            $detail = $snapshot === null ? null : $presenter->detail($snapshot);
        }

        return [
            'snapshots' => array_map(fn (array $s): array => $s + [
                'when' => EmsLabels::et($s['finalized_at'] ?? $s['started_at']),
                'kind_text' => EmsLabels::SNAPSHOT_KIND[$s['kind']] ?? $s['kind'],
            ], $list),
            'snapshotDetail' => $detail,
            'valueSources' => EmsLabels::VALUE_SOURCE,
        ];
    }

    // -------------------------------------------------------------------- movements

    public function movementsGo(int $direction): void
    {
        $this->emsActor('view');
        $this->movementsPage = max(1, $this->movementsPage + ($direction >= 0 ? 1 : -1));
    }

    public function updatedMovementType(): void
    {
        $this->movementsPage = 1;
    }

    // -------------------------------------------------------------------- snapshots

    public function viewSnapshot(string $uuid): void
    {
        $this->emsActor('view');
        abort_unless(InventorySnapshot::query()->where('uuid', $uuid)->exists(), 404);
        $this->snapshotUuid = $uuid;
    }

    public function closeSnapshot(): void
    {
        $this->emsActor('view');
        $this->snapshotUuid = null;
    }

    public function exportSnapshotAction(): Action
    {
        return Action::make('exportSnapshot')->color('gray')->size('sm')->outlined()
            ->label(fn (array $arguments): string => ($arguments['format'] ?? 'xlsx') === 'pdf' ? 'Export PDF' : 'Export Excel')
            ->icon(fn (array $arguments): string => ($arguments['format'] ?? 'xlsx') === 'pdf' ? 'heroicon-o-document-arrow-down' : 'heroicon-o-table-cells')
            ->action(function (array $arguments): StreamedResponse {
                $this->emsActor('view');
                $snapshot = InventorySnapshot::query()->where('uuid', (string) ($arguments['snapshot'] ?? ''))->firstOrFail();
                $detail = app(SnapshotPresenter::class)->detail($snapshot);
                $exporter = app(SnapshotExporter::class);
                $at = CarbonImmutable::now(EmsLabels::TIMEZONE);
                $pdf = ($arguments['format'] ?? 'xlsx') === 'pdf';
                $bytes = $pdf ? $exporter->pdf($detail, $at) : $exporter->xlsx($detail, $at);

                return response()->streamDownload(
                    static function () use ($bytes): void {
                        echo $bytes;
                    },
                    $exporter->filename($detail, $pdf ? 'pdf' : 'xlsx', $at),
                    ['Content-Type' => $pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'],
                );
            });
    }

    // ------------------------------------------------------------------ reconcile

    public function confirmReconciliationAction(): Action
    {
        return Action::make('confirmReconciliation')->label('Confirm reconciliation')->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (): bool => $this->emsCan('act'))
            ->requiresConfirmation()
            ->modalHeading('Confirm this reconciliation?')
            ->modalDescription('This saves the calculated levels shown below as a new snapshot. It is not a physical count, and items without an earlier count stay "not counted". Confirming twice does not count anything twice.')
            ->modalSubmitActionLabel('Confirm reconciliation')
            ->action(function (Action $action): void {
                $previewed = $this->previewLastMovementId;
                $key = 'recon-ui-'.substr(hash('sha256', (string) auth()->id().'|'.($previewed ?? 'none')), 0, 32);
                $result = $this->emsRun($action, 'act', function (User $user) use ($key, $previewed) {
                    try {
                        // The third argument is the movement the preview covered; the service refuses a stale preview.
                        return app(ReconciliationService::class)->confirm($user, $key, $previewed);
                    } catch (EmsException $exception) {
                        if ($exception->errorCode === 'stale_preview') {
                            $this->previewLastMovementId = null;
                            throw new EmsException('Stock changed while you were looking at this preview. It is refreshed below. Check it and confirm again.', 'stale_preview', 409);
                        }
                        throw $exception;
                    }
                }, 'Reconciliation saved');
                $this->forgetCurrentStock();
                if (is_array($result) && ($result['result']['unchanged'] ?? false)) {
                    \Filament\Notifications\Notification::make()->info()->title('Nothing new to reconcile')->body('The latest snapshot already includes every stock movement.')->send();
                }
            });
    }

    // ---------------------------------------------------------------------- orders

    public function recordOrderAction(): Action
    {
        return Action::make('recordOrder')->label('Record order placed')->icon('heroicon-o-shopping-cart')->color('primary')->size('sm')
            ->visible(fn (): bool => $this->emsCan('act'))
            ->modalHeading(fn (array $arguments): string => 'Record order placed: '.($this->itemName($arguments['item'] ?? 0)))
            ->modalDescription('Record this only after the order was actually placed. A reorder recommendation is advice, not an order. Supplier details come from the 2021 sheet and are unverified.')
            ->modalSubmitActionLabel('Record order')
            ->fillForm(function (array $arguments): array {
                $advice = collect(app(ReorderAdvisor::class)->advise()['advisories'])->firstWhere('supply_item_id', (int) ($arguments['item'] ?? 0));

                return [
                    'idempotency_key' => (string) Str::uuid(), 'packages' => $advice['suggested_packages'] ?? 1,
                    'supplier_code' => $advice['supplier_code'] ?? null, 'item_number' => $advice['item_number'] ?? null,
                ];
            })
            ->form([
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                TextInput::make('packages')->label('Packages ordered')->required()->numeric()->integer()->minValue(1)->maxValue(100000),
                TextInput::make('supplier_code')->label('Supplier code (historical, unverified)')->maxLength(40),
                TextInput::make('item_number')->label('Item number (historical, unverified)')->maxLength(80),
                Textarea::make('notes')->label('Notes')->rows(2)->maxLength(1000),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $input = array_filter([
                    'supply_item_id' => (int) ($arguments['item'] ?? 0), 'packages' => (int) ($data['packages'] ?? 0),
                    'supplier_code' => filled($data['supplier_code'] ?? null) ? trim((string) $data['supplier_code']) : null,
                    'item_number' => filled($data['item_number'] ?? null) ? trim((string) $data['item_number']) : null,
                    'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                ], fn ($value): bool => $value !== null);
                $this->emsRun($action, 'act', fn (User $user) => app(SupplyOrderService::class)->place($user, $this->emsKey($data), $input), 'Order recorded', 'It shows as "Order placed". Stock does not change until you mark it received.');
            });
    }

    public function receiveOrderAction(): Action
    {
        return Action::make('receiveOrder')->label('Mark received')->icon('heroicon-o-inbox-arrow-down')->color('success')->size('sm')
            ->visible(fn (): bool => $this->emsCan('act'))
            ->requiresConfirmation()
            ->modalHeading('Mark this order received?')
            ->modalDescription('This adds the received amount to stock. Do it only when the supplies are in the supply room.')
            ->modalSubmitActionLabel('Mark received')
            ->fillForm(fn (array $arguments): array => [
                'idempotency_key' => (string) Str::uuid(),
                'needs_quantity' => SupplyOrder::query()->whereKey((int) ($arguments['order'] ?? 0))->value('quantity_base') === null,
            ])
            ->form([
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                Hidden::make('needs_quantity'),
                TextInput::make('quantity_base')->label('Units received')->numeric()->integer()->minValue(1)->maxValue(10000000)
                    ->helperText('The package size for this item is not known, so say how many individual units arrived.')
                    ->visible(fn (Get $get): bool => (bool) $get('needs_quantity'))->required(fn (Get $get): bool => (bool) $get('needs_quantity')),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $input = filled($data['quantity_base'] ?? null) ? ['quantity_base' => (int) $data['quantity_base']] : [];
                $this->emsRun($action, 'act', fn (User $user) => app(SupplyOrderService::class)->receive((int) ($arguments['order'] ?? 0), $user, $this->emsKey($data), $input), 'Order received', 'The received amount is now in stock.');
                $this->forgetCurrentStock();
            });
    }

    public function cancelOrderAction(): Action
    {
        return Action::make('cancelOrder')->label('Cancel order')->icon('heroicon-o-x-mark')->color('danger')->size('sm')->outlined()
            ->visible(fn (): bool => $this->emsCan('act'))
            ->requiresConfirmation()
            ->modalHeading('Cancel this order?')
            ->modalDescription('The order stays in the list as cancelled. Stock does not change.')
            ->modalSubmitActionLabel('Cancel order')
            ->action(function (array $arguments, Action $action): void {
                $this->emsRun($action, 'act', fn (User $user) => app(SupplyOrderService::class)->cancel((int) ($arguments['order'] ?? 0), $user, 'cancel-order-'.(int) ($arguments['order'] ?? 0).'-'.$user->getKey()), 'Order cancelled');
            });
    }

    private function itemName(mixed $id): string
    {
        return (string) (SupplyItem::query()->whereKey((int) $id)->value('name') ?? 'item');
    }
}
