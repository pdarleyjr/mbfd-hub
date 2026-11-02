<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Filament\Support\EmsLabels;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\EmsException;
use App\Services\Ems\Exports\SupplyDataExporter;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Supply Data: what was distributed from the supply room (docs/ems/supply-data-contract.md). Read only. Every
 * number comes from SupplyDataReport; this class validates, calls it and presents the result.
 */
final class SupplyDataController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(private readonly SupplyDataReport $report, private readonly SupplyDataExporter $exporter) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->report->overview(SupplyDataFilters::fromArray($request->query()))));
    }

    public function item(Request $request, int $item): JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            // A controlled substance is not tracked here, so for this page it does not exist.
            $model = SupplyItem::query()->whereKey($item)->where('controlled_substance', false)->first() ?? throw EmsException::notFound('supply item');

            return $this->ok($this->report->item($model, SupplyDataFilters::fromArray($this->without($request->query(), 'item', 'category'))));
        });
    }

    public function unit(Request $request, string $unitKey): JsonResponse
    {
        return $this->guarded(function () use ($request, $unitKey): JsonResponse {
            if (preg_match('/^(unit:[1-9][0-9]{0,17}|detail|unassigned)\z/', $unitKey) !== 1) {
                throw EmsException::notFound('unit');
            }

            return $this->ok($this->report->unit($unitKey, SupplyDataFilters::fromArray($this->without($request->query(), 'unit'))));
        });
    }

    public function export(Request $request): Response
    {
        try {
            // The file lists EVERY supply distributed in the period (bounded by the catalogue), whatever limit a page uses.
            $filters = SupplyDataFilters::fromArray($request->query())->withLimit(PHP_INT_MAX);
            $overview = $this->report->overview($filters);
            $at = CarbonImmutable::now(EmsLabels::TIMEZONE);
            $bytes = $this->exporter->xlsx($overview, $filters, $at);

            return response()->streamDownload(
                static function () use ($bytes): void {
                    echo $bytes;
                },
                $this->exporter->filename($at),
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'],
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->fail((string) collect($exception->errors())->flatten()->first(), 'validation_failed', 422, $exception->errors());
        }
    }

    /**
     * The page's own item or unit comes from the path, so the same key in the query string is ignored (an item page has no use for a category filter either).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function without(array $query, string ...$keys): array
    {
        return array_diff_key($query, array_flip($keys));
    }
}
