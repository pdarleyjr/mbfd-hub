<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\SupplyItem;
use App\Filament\Support\EmsLabels;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use App\Services\Ems\Exports\SnapshotExporter;
use App\Services\Ems\InventoryCountService;
use App\Services\Ems\ReconciliationService;
use App\Services\Ems\ReorderAdvisor;
use App\Services\Ems\SnapshotPresenter;
use App\Services\Ems\SupplyItemMaintenance;
use App\Services\Ems\SupplyOrderService;
use App\Services\Ems\SupplyRoomQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/** Medical Supply Room API: items, counts, reconciliation, advisories, orders, movements, snapshots. */
final class SupplyRoomController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly SupplyRoomQuery $query,
        private readonly InventoryCountService $counts,
        private readonly ReconciliationService $reconciliation,
        private readonly ReorderAdvisor $advisor,
        private readonly SupplyOrderService $orders,
        private readonly SupplyItemMaintenance $maintenance,
        private readonly SnapshotPresenter $snapshots,
    ) {}

    // ---------------------------------------------------------------- reads

    public function items(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $bool = ['nullable', 'in:0,1,true,false'];
            $page = ['nullable', 'integer', 'min:1', 'max:100000'];
            $perPage = ['nullable', 'integer', 'min:1', 'max:200'];
            $result = $this->query->items($this->filters($request, [
                'q' => ['nullable', 'string', 'max:100'], 'location' => ['nullable', 'string', 'max:24'],
                'below_threshold' => $bool, 'needs_review' => $bool, 'controlled' => $bool, 'active' => $bool,
                'page' => $page, 'per_page' => $perPage,
            ]));

            return $this->ok($result['items'], $result['meta']);
        });
    }

    public function item(int $item): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->query->item(SupplyItem::query()->find($item) ?? throw EmsException::notFound('supply item'))));
    }

    public function locations(): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->query->locations()));
    }

    public function advisories(): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->advisor->advise()));
    }

    public function movements(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $page = ['nullable', 'integer', 'min:1', 'max:100000'];
            $perPage = ['nullable', 'integer', 'min:1', 'max:200'];
            $result = $this->query->movements($this->filters($request, [
                'supply_item_id' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', 'in:issued,received,adjusted'],
                'page' => $page, 'per_page' => $perPage,
            ]));

            return $this->ok($result['movements'], $result['meta']);
        });
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->query->orders($this->filters($request, [
            'status' => ['nullable', 'in:placed,received,cancelled'], 'supply_item_id' => ['nullable', 'integer', 'min:1'],
        ]))));
    }

    public function reconciliationPreview(): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->reconciliation->preview()));
    }

    public function snapshots(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->query->snapshots($this->filters($request, [
            'kind' => ['nullable', 'in:physical_count,reconciliation,baseline'], 'status' => ['nullable', 'in:draft,final'],
        ]), $this->snapshots)));
    }

    public function counts(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->query->snapshots(['kind' => InventorySnapshot::KIND_COUNT] + $this->filters($request, ['status' => ['nullable', 'in:draft,final']]), $this->snapshots)));
    }

    public function snapshot(string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->snapshots->detail($this->findSnapshot($uuid), withItems: true)));
    }

    /** Excel copy of one count or reconciliation: the same file the Admin page offers. Needs iew. */
    public function exportSnapshotExcel(string $uuid): Response
    {
        return $this->snapshotDownload($uuid, 'xlsx');
    }

    /** PDF copy of one count or reconciliation. Needs iew. */
    public function exportSnapshotPdf(string $uuid): Response
    {
        return $this->snapshotDownload($uuid, 'pdf');
    }

    // --------------------------------------------------------------- writes
    public function startCount(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->counts->start(
            $this->user($request), $this->idempotencyKey($request), $request->input('location_code'), $request->input('notes'),
        ), creates: true));
    }

    public function saveCount(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->counts->saveLines(
            (int) $this->findSnapshot($uuid)->getKey(), $this->user($request), $this->idempotencyKey($request), $request->all(),
        )));
    }

    public function finalizeCount(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->counts->finalize(
            (int) $this->findSnapshot($uuid)->getKey(), $this->user($request), $this->idempotencyKey($request),
        )));
    }

    public function discardCount(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->counts->discard(
            (int) $this->findSnapshot($uuid)->getKey(), $this->user($request), $this->idempotencyKey($request),
        )));
    }

    public function confirmReconciliation(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->reconciliation->confirm($this->user($request), $this->idempotencyKey($request), $request->input('expected_last_movement_id')), creates: true));
    }

    public function placeOrder(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->orders->place($this->user($request), $this->idempotencyKey($request), $request->all()), creates: true));
    }

    public function receiveOrder(Request $request, int $order): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->orders->receive($order, $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function cancelOrder(Request $request, int $order): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->orders->cancel($order, $this->user($request), $this->idempotencyKey($request))));
    }

    public function adjust(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->orders->adjust($this->user($request), $this->idempotencyKey($request), $request->all()), creates: true));
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->maintenance->update($item, $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    /**
     * Validated query-string filters (arrays and over-long text are refused with a friendly message).
     *
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $rules): array
    {
        return EmsValidator::validate($request->query(), $rules, [
            'in' => 'That filter value is not one of the allowed choices.', 'integer' => 'Filters that take a number must be a whole number.',
            'string' => 'Filters must be plain text.', 'max' => 'A filter value is too long.',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw EmsException::forbidden();
        }

        return $user;
    }

    private function snapshotDownload(string $uuid, string $format): Response
    {
        try {
            $detail = $this->snapshots->detail($this->findSnapshot($uuid));
            $exporter = app(SnapshotExporter::class);
            $at = CarbonImmutable::now(EmsLabels::TIMEZONE);
            $pdf = $format === 'pdf';
            $bytes = $pdf ? $exporter->pdf($detail, $at) : $exporter->xlsx($detail, $at);

            return response()->streamDownload(
                static function () use ($bytes): void {
                    echo $bytes;
                },
                $exporter->filename($detail, $format, $at),
                ['Content-Type' => $pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'],
            );
        } catch (EmsException $exception) {
            return $this->fail($exception->getMessage(), $exception->errorCode, $exception->status, $exception->fields);
        }
    }

    private function findSnapshot(string $uuid): InventorySnapshot
    {
        return InventorySnapshot::query()->where('uuid', $uuid)->first() ?? throw EmsException::notFound('count');
    }
}
