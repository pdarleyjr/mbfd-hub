<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Enums\DailyCheckoutRequirement;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\Widgets\VehicleChangesBanner;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusServiceTicket;
use App\Support\Fleet\FleetQueueCounts;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * The Fleet worklist. Tabs answer "what needs me next?" before anyone has
 * to build a filter: open work, out-of-service units, PM windows, spares,
 * trucks a crew just changed, and units whose vehicle record still needs a
 * Fleet decision.
 */
class ListApparatuses extends ListRecords
{
    protected static string $resource = ApparatusResource::class;

    public const OUT_OF_SERVICE_STATUSES = ['Out of Service', 'out_of_service', 'in_repair', 'Maintenance', 'maintenance'];

    public const SPARE_STATUSES = ['Available', 'Reserve', 'reserve'];

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add apparatus'),
        ];
    }

    /** @return list<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [VehicleChangesBanner::class];
    }

    /** Redraws the tab counts after the banner acknowledged a change. */
    #[On(VehicleChangesBanner::ACKNOWLEDGED_EVENT)]
    public function vehicleChangeAcknowledged(): void {}

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('All'),
            'attention' => $this->tab('Needs attention', fn (Builder $query): Builder => self::needsAttention($query), 'danger'),
            'out_of_service' => $this->tab('Out of service', fn (Builder $query): Builder => $query->whereIn('status', self::OUT_OF_SERVICE_STATUSES), 'danger'),
            'pm' => $this->tab('PM due', fn (Builder $query): Builder => ApparatusResource::wherePm($query, 'attention'), 'warning'),
            'spares' => $this->tab('Reserve and spares', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                ->whereIn('status', self::SPARE_STATUSES)
                ->orWhere('daily_checkout_requirement', DailyCheckoutRequirement::Reserve->value)), 'gray'),
        ];

        if (auth()->user()?->can('viewAny', ApparatusChangeout::class) ?? false) {
            // The count is the sidebar badge's, from the same memoized query: this tab adds none.
            $tabs['vehicle_changes'] = Tab::make('Vehicle changes')
                ->modifyQueryUsing(fn (Builder $query): Builder => self::changedTrucks($query))
                ->badge(fn (): int => FleetQueueCounts::unacknowledgedChangeouts())
                ->badgeColor('warning');
        }

        $review = fn (Builder $query): Builder => self::needsVehicleRecordReview($query);
        if ($review(Apparatus::query())->exists()) {
            $tabs['vehicle_record'] = $this->tab('Vehicle record review', $review, 'warning');
        }

        return $tabs;
    }

    /** Units with something for Fleet to do now. */
    public static function needsAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereIn('status', self::OUT_OF_SERVICE_STATUSES)
            ->orWhereHas('openDefects')
            ->orWhereIn('id', ApparatusServiceTicket::query()->open()->select('apparatus_id'))
            ->orWhere(fn (Builder $pm): Builder => ApparatusResource::wherePm($pm, 'attention'))
            ->orWhere(fn (Builder $link): Builder => self::needsVehicleRecordReview($link)));
    }

    /** Both trucks of every change Fleet has not acknowledged. */
    public static function changedTrucks(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereIn('id', ApparatusChangeout::query()->unacknowledged()->select('from_apparatus_id'))
            ->orWhereIn('id', ApparatusChangeout::query()->unacknowledged()->whereNotNull('to_apparatus_id')->select('to_apparatus_id')));
    }

    public static function needsVehicleRecordReview(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->where('master_link_status', Apparatus::MASTER_LINK_NEEDS_REVIEW)
            ->orWhereNull('unit_master_vehicle_id'));
    }

    /** @param  callable(Builder): Builder  $scope */
    private function tab(string $label, callable $scope, string $color): Tab
    {
        return Tab::make($label)
            ->modifyQueryUsing($scope)
            ->badge(fn (): int => $scope(Apparatus::query())->count())
            ->badgeColor($color);
    }
}
