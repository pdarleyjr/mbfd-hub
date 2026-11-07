<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrectionResource\Pages;

use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\ApparatusInventoryCorrection;
use App\Services\Inventory\InventoryOverlayHealth;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class ListInventoryCorrections extends ListRecords
{
    protected static string $resource = InventoryCorrectionResource::class;

    public const CONFLICTS_CACHE_KEY = 'inventory-corrections:overlay-conflicts';

    public function getSubheading(): string|Htmlable|null
    {
        $intro = 'Checklist entries members reported as wrong. Approving one changes the Daily Checkout checklist for the apparatus you choose.';
        $conflicts = $this->overlayConflicts();
        if ($conflicts === []) {
            return $intro;
        }

        // Approved changes the resolver skips (a template changed under them): each needs a decision.
        $items = collect($conflicts)->map(fn (array $conflict): string => '<li><a class="hub-insp-link" href="'
            .e(InventoryCorrectionResource::getUrl('view', ['record' => $conflict['correction_id']])).'">'
            .e($conflict['apparatus'].', change #'.$conflict['change_id']).'</a>: '.e($conflict['reason_label']).'</li>')->implode('');

        return new HtmlString('<span>'.e($intro).'</span><span class="hub-inv-conflict" role="status" data-overlay-conflicts="'.count($conflicts).'">'
            .'<strong>'.e(count($conflicts) === 1 ? '1 approved change is not applying to its checklist.' : count($conflicts).' approved changes are not applying to their checklists.').'</strong>'
            .' Members do not see them. Open each one to retire it or follow up.'
            .'<ul class="hub-inv-impact__excluded">'.$items.'</ul></span>');
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Needs a decision')
                ->badge(fn (): int => ApparatusInventoryCorrection::query()->open()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ApparatusInventoryCorrection::OPEN_STATUSES)),
            'waiting' => Tab::make('Waiting on the member')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusInventoryCorrection::STATUS_CLARIFICATION)),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusInventoryCorrection::STATUS_APPLIED)),
            'closed' => Tab::make('Closed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [...ApparatusInventoryCorrection::CLOSED_WITHOUT_CHANGE_STATUSES, ApparatusInventoryCorrection::STATUS_REVERSED])),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    /** @return list<array<string, mixed>> */
    private function overlayConflicts(): array
    {
        // Resolving every unit with approved changes is not free: keep it off every Livewire round trip.
        return rescue(
            static fn (): array => Cache::remember(self::CONFLICTS_CACHE_KEY, 60, static fn (): array => app(InventoryOverlayHealth::class)->fleetConflicts()),
            static fn (): array => app(InventoryOverlayHealth::class)->fleetConflicts(),
            report: false,
        );
    }
}
