<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource;

use App\Filament\Resources\ApparatusInspectionExceptionResource;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\DefectResource;
use App\Filament\Resources\StationRequestResource;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\StationOperationsSummaryService;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Carbon;

/**
 * "Station Operations" summary on the Admin station profile: open station
 * repair/equipment requests, apparatus service tickets, unresolved equipment
 * issues, Daily Checkout exceptions and recent checkouts, each linking to the
 * station-filtered admin list where the work is progressed. An entry is shown
 * only when the viewer can open the list it links to.
 */
final class StationOperationsSection
{
    public static function make(): Section
    {
        return Section::make('Station Operations')
            ->id('station-operations')
            ->description('Open requests, apparatus service, equipment issues, and Daily Checkout activity for this station.')
            ->schema([
                self::countEntry('open_station_requests', 'Open station requests', StationRequestResource::canViewAny(...),
                    fn (Station $record): string => StationRequestResource::getUrl('index', self::filters($record, ['open' => ['value' => true]]))),
                self::countEntry('open_service_tickets', 'Open apparatus service', ApparatusServiceTicketResource::canViewAny(...),
                    fn (Station $record): string => ApparatusServiceTicketResource::getUrl('index', self::filters($record, ['open' => ['value' => true]]))),
                self::countEntry('open_equipment_issues', 'Unresolved equipment issues', DefectResource::canViewAny(...),
                    fn (Station $record): string => self::equipmentIssuesUrl($record)),
                self::countEntry('open_inspection_exceptions', 'Checkout exceptions to review', ApparatusInspectionExceptionResource::canViewAny(...),
                    fn (Station $record): string => ApparatusInspectionExceptionResource::getUrl('index', self::filters($record, ['open' => ['value' => true]]))),
                TextEntry::make('checkouts_last_24h')
                    ->label('Daily Checkouts (24 h)')
                    ->state(fn (Station $record): int => self::summary($record)['checkouts_last_24h'])
                    ->helperText(function (Station $record): string {
                        $latest = self::summary($record)['last_checkout_at'];

                        return $latest === null
                            ? 'No checkouts recorded'
                            : 'Last '.$latest->timezone('America/New_York')->format('M j, g:i A');
                    })
                    ->badge()
                    ->color('info')
                    ->visible(fn (): bool => auth()->user()?->can('viewAny', ApparatusInspection::class) ?? false),
            ])
            ->columns(['default' => 1, 'sm' => 2, 'xl' => 5]);
    }

    /**
     * Equipment-issue list showing exactly what the "Unresolved equipment
     * issues" count counts: this station, not resolved (open or in progress).
     * The status filter is cleared so its "open only" default cannot hide
     * in-progress issues.
     */
    public static function equipmentIssuesUrl(Station $record): string
    {
        return DefectResource::getUrl('index', self::filters($record, [
            'status' => ['value' => null],
            'unresolved' => ['value' => true],
        ]));
    }

    /**
     * Query-string filter state for a station-filtered resource list.
     *
     * @param  array<string, array<string, mixed>>  $extra
     * @return array{tableFilters: array<string, array<string, mixed>>}
     */
    public static function filters(Station $record, array $extra = []): array
    {
        return ['tableFilters' => ['station_id' => ['value' => (string) $record->getKey()], ...$extra]];
    }

    /**
     * @param  callable(): bool  $canView
     * @param  callable(Station): string  $url
     */
    private static function countEntry(string $key, string $label, callable $canView, callable $url): TextEntry
    {
        return TextEntry::make($key)
            ->label($label)
            ->state(fn (Station $record): int => self::summary($record)[$key])
            ->badge()
            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success')
            ->url(fn (Station $record): string => $url($record))
            ->visible(fn (): bool => $canView());
    }

    /**
     * @return array{open_station_requests: int, open_service_tickets: int, open_equipment_issues: int, open_inspection_exceptions: int, checkouts_last_24h: int, last_checkout_at: ?Carbon}
     */
    private static function summary(Station $record): array
    {
        return app(StationOperationsSummaryService::class)->summaryFor($record);
    }
}
