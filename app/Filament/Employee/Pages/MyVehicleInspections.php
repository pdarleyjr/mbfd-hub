<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
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

    public function mount(): void
    {
        // Fail closed with the canonical 403 when the account has no employee context.
        $this->authenticatedEmployee();
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
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Submitted (Eastern)')
                    ->dateTime('M j, Y g:i A', $history::TIMEZONE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('designation_at_time')
                    ->label('Apparatus')
                    ->weight('semibold')
                    ->getStateUsing(function (ApparatusInspection $record): string {
                        $apparatus = $record->getRelationValue('apparatus');
                        $apparatus = $apparatus instanceof Apparatus ? $apparatus : null;

                        return $record->designation_at_time
                            ?: ($apparatus?->designation ?: ($apparatus?->name ?: 'Apparatus'));
                    })
                    ->description(fn (ApparatusInspection $record): ?string => $record->vehicle_number ? 'Vehicle #'.$record->vehicle_number : null),
                Tables\Columns\TextColumn::make('apparatus.station.name')
                    ->label('Station')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('inspection_reference')
                    ->label('Reference')
                    ->fontFamily('mono')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $this->applySearch($query, $search)),
                Tables\Columns\TextColumn::make('processing_status')
                    ->label('Processing')
                    ->badge()
                    ->getStateUsing(fn (ApparatusInspection $record): string => $history->processingStatusLabel($record->processing_status))
                    ->color(fn (string $state): string => str_contains($state, 'flagged') ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('review_status')
                    ->label('Review')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $history->reviewStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending_review' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('findings')
                    ->label('Findings')
                    ->badge()
                    ->getStateUsing(fn (ApparatusInspection $record): string => $history->findingsLabel($record))
                    ->color(fn (string $state): string => $state === 'None' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('follow_up')
                    ->label('Follow-up')
                    ->getStateUsing(fn (ApparatusInspection $record): string => $history->followUpSummary($record))
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('review_status')
                    ->label('Review status')
                    ->options([
                        'pending_review' => 'Pending review',
                        'approved' => 'Approved',
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
                    ->url(fn (ApparatusInspection $record): string => MyVehicleInspectionView::getUrl(['inspection' => $record->getKey()], panel: 'employee')),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (ApparatusInspection $record): string => route('employee.vehicle-inspections.pdf', ['inspection' => $record->getKey()]))
                    ->openUrlInNewTab(),
            ])
            ->recordUrl(fn (ApparatusInspection $record): string => MyVehicleInspectionView::getUrl(['inspection' => $record->getKey()], panel: 'employee'))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('apparatus_inspections.completed_at')
                ->orderByDesc('apparatus_inspections.id'))
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No vehicle inspections yet')
            ->emptyStateDescription('Daily Checkouts you submit will appear here. Inspections still queued on a device appear after that device syncs.');
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
