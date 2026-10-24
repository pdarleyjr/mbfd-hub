<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Enums\DailyCheckoutRequirement;
use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Fleet worklist. Tabs answer "what needs me next?" before anyone has
 * to build a filter: open work, out-of-service units, PM windows, spares, and
 * units whose vehicle record still needs a Fleet decision.
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
            ->orWhereHas('serviceTickets', fn (Builder $tickets): Builder => $tickets->open())
            ->orWhere(fn (Builder $pm): Builder => ApparatusResource::wherePm($pm, 'attention'))
            ->orWhere(fn (Builder $link): Builder => self::needsVehicleRecordReview($link)));
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
