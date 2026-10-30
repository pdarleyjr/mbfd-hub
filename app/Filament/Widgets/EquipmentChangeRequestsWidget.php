<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\Apparatus;
use App\Models\ApparatusInventoryCorrection;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Exports\ExportDataset;
use App\Services\Inventory\InventoryCorrectionDiff;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Services\Inventory\InventoryOverlay;
use App\Support\Fleet\InventoryCorrectionAccess;
use App\Support\HubTimezone;
use Filament\Widgets\Widget;
use Throwable;

/**
 * Admin home card: member requests to change the equipment list of a vehicle's Daily Checkout
 * (docs/apparatus-inventory-corrections.md). It reads the same corrections the Fleet queue
 * decides, so there is one authoritative record and one place where decisions are made: each row
 * links to that request's own page, which shows the before/after and carries approve and reject.
 *
 * The card never changes anything itself, shows the same people who may decide, and is kept off the
 * wall display because it names members.
 */
final class EquipmentChangeRequestsWidget extends Widget
{
    private const PREVIEW_ROWS = 5;

    protected static string $view = 'filament.widgets.equipment-change-requests-widget';

    protected static ?int $sort = 90;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (request()->boolean('display')) {
            return false;
        }

        return InventoryCorrectionAccess::canReview(auth()->user());
    }

    /** Runs on every Livewire request, so a demoted reviewer loses the card at the next poll. */
    public function hydrate(): void
    {
        $this->authorizedActor();
    }

    /** Poll target: re-authorization only. */
    public function poll(): void
    {
        $this->authorizedActor();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->authorizedActor();

        $open = ApparatusInventoryCorrection::query()->open()->count();
        $waitingOnMember = ApparatusInventoryCorrection::query()->where('status', ApparatusInventoryCorrection::STATUS_CLARIFICATION)->count();

        $rows = ApparatusInventoryCorrection::query()
            ->open()
            ->with(['apparatus:id,designation,name,unit_id', 'reporter:id,name,display_name'])
            ->orderByDesc('id')
            ->limit(self::PREVIEW_ROWS)
            ->get()
            ->map(fn (ApparatusInventoryCorrection $correction): array => $this->row($correction))
            ->all();

        return [
            'open' => $open,
            'waitingOnMember' => $waitingOnMember,
            'rows' => $rows,
            'listUrl' => InventoryCorrectionResource::getUrl('index'),
        ];
    }

    /**
     * Reports recorded before the destination name was stored with them read it from the
     * apparatus's current checklist (rare, and only for a move).
     *
     * @return array<string, string> compartment id => name
     */
    private function compartmentNames(ApparatusInventoryCorrection $correction): array
    {
        $snapshot = $correction->target_snapshot ?: [];
        $destination = ($correction->proposal ?: [])['to_compartment_id'] ?? null;
        if ($correction->reason !== InventoryCorrectionReason::MOVED || $destination === null || isset($snapshot['to_compartment'])) {
            return [];
        }
        try {
            // The card loads a few apparatus columns; the checklist resolver needs the whole row.
            $apparatus = Apparatus::query()->find($correction->apparatus_id);
            if ($apparatus === null) {
                return [];
            }
            $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return collect($resolution['checklist']['compartments'] ?? [])
            ->mapWithKeys(static fn (array $compartment): array => [(string) ($compartment['id'] ?? '') => InventoryOverlay::compartmentName($compartment)])
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(ApparatusInventoryCorrection $correction): array
    {
        $reporter = $correction->reporter;

        return [
            'id' => (int) $correction->getKey(),
            'reference' => $correction->reference(),
            'url' => InventoryCorrectionResource::getUrl('view', ['record' => $correction->getKey()]),
            'unit' => InventoryCorrectionResource::unitLabel($correction),
            'item' => InventoryCorrectionResource::itemLabel($correction),
            'reason' => InventoryCorrectionReason::label($correction->reason),
            'diff' => InventoryCorrectionDiff::rows($correction, $this->compartmentNames($correction)),
            'member' => (string) ($reporter?->display_name ?: $reporter?->name ?: 'Member'),
            'note' => $correction->member_note,
            'status' => $correction->status,
            'status_label' => ApparatusInventoryCorrection::statusLabel($correction->status),
            'reported_at' => $correction->created_at,
        ];
    }

    /**
     * Dashboard export contract (tests/Feature/Exports/PanelExportCoverageTest): the open
     * requests with their before and after, for the people who may decide them.
     */
    public function canExportData(User $actor): bool
    {
        return InventoryCorrectionAccess::canReview($actor);
    }

    public function exportDataset(User $actor): ExportDataset
    {
        abort_unless($this->canExportData($actor), 403);

        $rows = ApparatusInventoryCorrection::query()
            ->open()
            ->with(['apparatus:id,designation,name,unit_id', 'reporter:id,name,display_name'])
            ->orderBy('id')
            ->get()
            ->map(fn (ApparatusInventoryCorrection $correction): array => [
                $correction->reference(),
                InventoryCorrectionResource::unitLabel($correction),
                InventoryCorrectionResource::itemLabel($correction),
                InventoryCorrectionReason::label($correction->reason),
                InventoryCorrectionDiff::summary($correction, $this->compartmentNames($correction)),
                (string) ($correction->reporter?->display_name ?: $correction->reporter?->name ?: 'Member'),
                (string) $correction->member_note,
                ApparatusInventoryCorrection::statusLabel($correction->status),
                $correction->created_at?->timezone(HubTimezone::NAME)->format('Y-m-d H:i'),
            ])
            ->all();

        return ExportDataset::fromRows(
            $actor,
            'Equipment change requests',
            ['Reference', 'Apparatus', 'Item', 'Reported problem', 'Change (before → after)', 'Member', 'Reason given', 'Status', 'Reported (Eastern)'],
            $rows,
            'Requests waiting for a decision',
        );
    }

    private function authorizedActor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && InventoryCorrectionAccess::canReview($user), 403);

        return $user;
    }
}
