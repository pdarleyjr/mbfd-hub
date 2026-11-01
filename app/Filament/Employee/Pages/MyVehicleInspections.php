<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inventory\InventoryCorrectionProjection;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Read-only history of the Daily Checkouts the signed-in member submitted.
 * Rows are limited server-side to inspections attributed to the member by
 * foreign key; nothing the browser sends can widen that set.
 */
class MyVehicleInspections extends Page implements HasTable
{
    use InteractsWithTable;
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'My work';

    protected static ?string $navigationLabel = 'My Vehicle Inspections';

    protected static ?string $title = 'My Vehicle Inspections';

    protected static ?string $slug = 'my-vehicle-inspections';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.employee.pages.my-vehicle-inspections';

    /**
     * Keep the list's search, filters and sort in the address bar. Back from an
     * inspection returns here with the same view, and the URL stays shareable.
     * These values can only narrow the member's own rows (see listQuery()).
     *
     * @return array<string, array{except: mixed}>
     */
    protected function queryString(): array
    {
        return [
            'tableFilters' => ['except' => null],
            'tableSearch' => ['except' => ''],
            'tableSortColumn' => ['except' => null],
            'tableSortDirection' => ['except' => null],
            'tableRecordsPerPage' => ['except' => null],
        ];
    }

    public function mount(): void
    {
        // Fail closed with the canonical 403 when the account has no employee context.
        $this->authenticatedEmployee();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            // Only corrections this member reported; the projection never widens the set.
            'inventoryCorrections' => collect(app(InventoryCorrectionProjection::class)->memberList($this->member(), 20))
                ->map(fn (array $row): array => [...$row, 'url' => MyInventoryCorrectionView::getUrl(['correction' => $row['id']], panel: 'employee')])
                ->all(),
        ];
    }

    private function history(): EmployeeInspectionHistoryService
    {
        return app(EmployeeInspectionHistoryService::class);
    }

    private function member(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return Builder<ApparatusInspection> */
    private function listQuery(): Builder
    {
        return $this->history()->withFollowUpCounts(
            $this->history()->ownedQuery($this->member()),
        );
    }

    public function table(Table $table): Table
    {
        $history = $this->history();

        return $table
            ->query(fn (): Builder => $this->listQuery())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['apparatus:id,name,designation,vehicle_number,station_id', 'apparatus.station:id,name,station_number']))
            ->columns([
                // Phones and small tablets: one readable card per row, with the actions beside it.
                Tables\Columns\ViewColumn::make('summary')
                    ->label('Inspection')
                    ->view('filament.employee.tables.inspection-summary')
                    ->getStateUsing(fn (ApparatusInspection $record): array => $this->summary($record))
                    ->hiddenFrom('md'),
                // From md up: four compact columns so View and PDF are always inside the table width.
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Submitted (ET)')
                    ->dateTime('M j, Y', $history::TIMEZONE)
                    ->description(fn (ApparatusInspection $record): ?string => $this->submittedTime($record))
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('designation_at_time')
                    ->label('Apparatus')
                    ->weight('semibold')
                    ->getStateUsing(fn (ApparatusInspection $record): string => $this->apparatusTitle($record))
                    ->description(fn (ApparatusInspection $record): ?string => $this->apparatusMeta($record))
                    ->wrap()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('review_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state, ApparatusInspection $record): string => $this->status($record)['label'])
                    ->color(fn (mixed $state, ApparatusInspection $record): string => $this->status($record)['color'])
                    ->description(fn (ApparatusInspection $record): ?string => $this->status($record)['note'])
                    ->tooltip(fn (ApparatusInspection $record): string => $this->status($record)['tooltip'])
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('findings')
                    ->label('Findings')
                    ->badge()
                    ->getStateUsing(fn (ApparatusInspection $record): string => $this->findings($record)['label'])
                    ->color(fn (string $state, ApparatusInspection $record): string => $this->findings($record)['color'])
                    ->description(fn (ApparatusInspection $record): string => $this->findings($record)['follow_up'])
                    ->tooltip(fn (ApparatusInspection $record): string => $this->findings($record)['tooltip'])
                    ->wrap()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('inspection_reference')
                    ->label('Reference')
                    ->fontFamily('mono')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $this->applySearch($query, $search))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('review_status')
                    ->label('Review status')
                    ->options([
                        'pending_review' => 'Waiting for Logistics review',
                        'approved' => 'Accepted',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\TernaryFilter::make('has_findings')
                    ->label('Findings')
                    ->placeholder('All inspections')
                    ->trueLabel('With missing or damaged items')
                    ->falseLabel('No findings')
                    ->queries(
                        true: fn (Builder $query): Builder => $this->history()->whereHasFindings($query),
                        false: fn (Builder $query): Builder => $this->history()->whereHasFindings($query, false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('apparatus_id')
                    ->label('Apparatus')
                    ->options(fn (): array => $this->apparatusOptions()),
                Tables\Filters\Filter::make('submitted_between')
                    ->label('Date range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $this->applyDateRange($query, $data))
                    ->indicateUsing(function (array $data): array {
                        return array_values(array_filter([
                            filled($data['from'] ?? null) ? 'From '.$data['from'] : null,
                            filled($data['until'] ?? null) ? 'Until '.$data['until'] : null,
                        ]));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ApparatusInspection $record): string => $this->detailUrl($record)),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (ApparatusInspection $record): string => route('employee.vehicle-inspections.pdf', ['inspection' => $record->getKey()])),
            ])
            ->recordUrl(fn (ApparatusInspection $record): string => $this->detailUrl($record))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('apparatus_inspections.completed_at')
                ->orderByDesc('apparatus_inspections.id'))
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No vehicle inspections yet')
            ->emptyStateDescription('Daily Checkouts you submit will appear here. Inspections still queued on a device appear after that device syncs.');
    }

    /**
     * Detail link for a row. It carries this list's own filters, search, sort
     * and page as return_to, so Back from the detail restores exactly this view.
     * HubNavigation::safePath() validates the value again when it is consumed.
     */
    private function detailUrl(ApparatusInspection $record): string
    {
        return MyVehicleInspectionView::getUrl([
            'inspection' => $record->getKey(),
            'return_to' => $this->listReturnTo(),
        ], panel: 'employee');
    }

    private function listReturnTo(): string
    {
        $path = parse_url(self::getUrl(panel: 'employee'), PHP_URL_PATH) ?: '/employee/my-vehicle-inspections';
        $state = $this->withoutBlanks([
            'tableFilters' => $this->tableFilters,
            'tableSearch' => $this->tableSearch,
            'tableSortColumn' => $this->tableSortColumn,
            'tableSortDirection' => $this->tableSortDirection,
            'tableRecordsPerPage' => (string) $this->tableRecordsPerPage === (string) $this->getDefaultTableRecordsPerPageSelectOption()
                ? null
                : $this->tableRecordsPerPage,
            'page' => $this->getTablePage() > 1 ? $this->getTablePage() : null,
        ]);

        return $state === [] ? $path : $path.'?'.http_build_query($state);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutBlanks(array $values): array
    {
        $kept = [];
        foreach ($values as $key => $value) {
            $value = is_array($value) ? $this->withoutBlanks($value) : $value;
            if ($value !== null && $value !== '' && $value !== []) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    private function submittedTime(ApparatusInspection $record): ?string
    {
        return $record->completed_at?->copy()->setTimezone(EmployeeInspectionHistoryService::TIMEZONE)->format('g:i A');
    }

    private function apparatusTitle(ApparatusInspection $record): string
    {
        $apparatus = $record->getRelationValue('apparatus');
        $apparatus = $apparatus instanceof Apparatus ? $apparatus : null;

        return $record->designation_at_time
            ?: ($apparatus?->designation ?: ($apparatus?->name ?: 'Apparatus'));
    }

    /** Vehicle number and station share one short line under the apparatus. */
    private function apparatusMeta(ApparatusInspection $record): ?string
    {
        $apparatus = $record->getRelationValue('apparatus');
        $station = $apparatus instanceof Apparatus ? $apparatus->getRelationValue('station') : null;
        $parts = array_filter([
            $record->vehicle_number ? 'Vehicle #'.$record->vehicle_number : null,
            $station?->name,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * One status for the row: the review outcome, with the processing result
     * folded in as a short note and a tooltip carrying the full wording.
     *
     * @return array{label: string, color: string, note: ?string, tooltip: string}
     */
    private function status(ApparatusInspection $record): array
    {
        $history = $this->history();
        $flagged = $record->processing_status === 'accepted_with_exception';
        [$label, $color] = match ($record->review_status) {
            'approved' => ['Accepted', 'success'],
            'rejected' => ['Rejected', 'danger'],
            'pending_review' => ['Waiting for Logistics review', 'warning'],
            default => [$flagged ? 'Accepted' : $history->processingStatusLabel($record->processing_status), $flagged ? 'warning' : 'gray'],
        };

        return [
            'label' => $label,
            'color' => $color,
            'note' => $flagged ? 'Meter flagged' : null,
            // The service still calls this review state "Needs approval"; members cannot approve anything.
            'tooltip' => $history->processingStatusLabel($record->processing_status).' · '.($record->review_status === 'pending_review' ? $label : $history->reviewStatusLabel($record->review_status)),
        ];
    }

    /**
     * Findings as a short count badge, with the missing/damaged split in the
     * tooltip and the follow-up progress as the secondary line.
     *
     * @return array{label: string, color: string, tooltip: string, follow_up: string}
     */
    private function findings(ApparatusInspection $record): array
    {
        $history = $this->history();
        $counts = $history->countFindings($record->results);
        $total = $counts['missing'] + $counts['damaged'];

        return [
            'label' => $total === 0 ? 'None' : $total.($total === 1 ? ' finding' : ' findings'),
            'color' => $total === 0 ? 'success' : 'danger',
            'tooltip' => $history->findingsLabel($record),
            'follow_up' => $history->followUpSummary($record),
        ];
    }

    /**
     * Everything the phone card needs, computed once per row.
     *
     * @return array<string, mixed>
     */
    private function summary(ApparatusInspection $record): array
    {
        return [
            'apparatus' => $this->apparatusTitle($record),
            'meta' => $this->apparatusMeta($record),
            'submitted' => $record->completed_at?->copy()->setTimezone(EmployeeInspectionHistoryService::TIMEZONE)->format('M j, Y g:i A'),
            'status' => $this->status($record),
            'findings' => $this->findings($record),
        ];
    }

    /** @return Builder<ApparatusInspection> */
    private function applySearch(Builder $query, string $search): Builder
    {
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';

        return $query->where(function (Builder $match) use ($needle): void {
            $match->whereRaw("LOWER(apparatus_inspections.inspection_reference) LIKE ? ESCAPE '\\'", [$needle])
                ->orWhereRaw("LOWER(apparatus_inspections.designation_at_time) LIKE ? ESCAPE '\\'", [$needle])
                ->orWhereRaw("LOWER(apparatus_inspections.vehicle_number) LIKE ? ESCAPE '\\'", [$needle])
                ->orWhereHas('apparatus', fn (Builder $apparatus): Builder => $apparatus
                    ->whereRaw("LOWER(apparatuses.name) LIKE ? ESCAPE '\\'", [$needle]));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Builder<ApparatusInspection>
     */
    private function applyDateRange(Builder $query, array $data): Builder
    {
        $zone = EmployeeInspectionHistoryService::TIMEZONE;
        $storage = (string) config('app.timezone', 'UTC');
        $from = $this->parseDay($data['from'] ?? null, $zone)?->startOfDay()->setTimezone($storage);
        $until = $this->parseDay($data['until'] ?? null, $zone)?->endOfDay()->setTimezone($storage);

        return $query
            ->when($from, fn (Builder $q, CarbonImmutable $moment): Builder => $q->where('apparatus_inspections.completed_at', '>=', $moment))
            ->when($until, fn (Builder $q, CarbonImmutable $moment): Builder => $q->where('apparatus_inspections.completed_at', '<=', $moment));
    }

    private function parseDay(mixed $value, string $zone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $zone);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string> */
    private function apparatusOptions(): array
    {
        return Apparatus::query()
            ->whereIn('id', $this->history()->ownedQuery($this->member())->select('apparatus_inspections.apparatus_id'))
            ->orderBy('designation')
            ->get(['id', 'name', 'designation'])
            ->mapWithKeys(fn (Apparatus $apparatus): array => [$apparatus->getKey() => (string) ($apparatus->designation ?: $apparatus->name)])
            ->all();
    }
}
