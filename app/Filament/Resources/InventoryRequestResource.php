<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\InventoryRequestResource\Pages;
use App\Filament\Support\EmsLabels;
use App\Filament\Support\EmsPanelAccess;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestMessage;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin -> EMS -> Rescue Inventory. The same request records Captain 5 works from, read here and
 * acted on through the EMS services. Members submit requests and history is never edited, so this
 * resource has no create, edit or delete.
 */
class InventoryRequestResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = InventoryRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'EMS';

    protected static ?string $navigationLabel = 'Rescue Inventory';

    protected static ?string $modelLabel = 'request';

    protected static ?string $pluralModelLabel = 'Rescue Inventory';

    protected static ?string $slug = 'rescue-inventory';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return EmsPanelAccess::canView(auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    /** The list query: item count, requested and issued quantity, and who wrote last. */
    public static function getEloquentQuery(): Builder
    {
        return static::withTotals(parent::getEloquentQuery());
    }

    /**
     * Adds lines_count, qty_requested, qty_issued (quantity lines only; Yes/No answers are not quantities)
     * and last_message_context. The exporter reads the same attributes.
     *
     * @param  Builder<InventoryRequest>  $query
     * @return Builder<InventoryRequest>
     */
    public static function withTotals(Builder $query): Builder
    {
        return $query
            ->withCount('lines')
            ->withSum(['lines as qty_requested' => fn ($lines) => $lines->where('response_type', 'quantity')], 'requested_qty')
            ->withSum(['lines as qty_issued' => fn ($lines) => $lines->where('response_type', 'quantity')], 'issued_qty')
            ->addSelect(['last_message_context' => static::lastMessageContext()]);
    }

    /** @return Builder<InventoryRequestMessage> */
    private static function lastMessageContext(): Builder
    {
        return InventoryRequestMessage::query()->select('author_context')
            ->whereColumn('ems_inventory_request_messages.request_id', 'ems_inventory_requests.id')
            ->orderByDesc('id')->limit(1);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->defaultSort('submitted_at', 'desc')
            ->recordUrl(fn (InventoryRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No requests match')
            ->emptyStateDescription('Change or clear the filters to see more requests. Members send requests from the Employee Portal.')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Request')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('submitted_at')->label('Submitted')
                    ->dateTime('M j, Y g:i A', EmsLabels::TIMEZONE)->sortable(),
                Tables\Columns\TextColumn::make('submitter_name')->label('Member')->searchable()
                    ->description(fn (InventoryRequest $record): ?string => $record->submitter_rank),
                Tables\Columns\TextColumn::make('target_label')->label('Unit or Detail')->searchable()
                    ->description(fn (InventoryRequest $record): ?string => filled($record->event_assignment) ? (string) $record->event_assignment : null),
                Tables\Columns\TextColumn::make('lines_count')->label('Items')->numeric()->alignEnd(),
                Tables\Columns\TextColumn::make('qty_requested')->label('Requested')->numeric()->alignEnd()->sortable()
                    ->state(fn (InventoryRequest $record): int => (int) $record->qty_requested),
                Tables\Columns\TextColumn::make('qty_issued')->label('Issued')->numeric()->alignEnd()->sortable()
                    ->state(fn (InventoryRequest $record): int => (int) $record->qty_issued),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => EmsLabels::status($state))
                    ->color(fn (string $state): string => EmsLabels::STATUS_COLOR[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('last_message_context')->label('Messages')
                    ->state(fn (InventoryRequest $record): ?string => $record->last_message_context === 'member' ? 'Waiting for a reply' : null)
                    ->badge()->color('warning'),
                Tables\Columns\TextColumn::make('last_activity_at')->label('Last activity')
                    ->state(fn (InventoryRequest $record) => $record->last_activity_at ?? $record->submitted_at)
                    ->dateTime('M j, g:i A', EmsLabels::TIMEZONE)->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->multiple()
                    ->options(EmsLabels::STATUS)->default(InventoryRequest::OPEN_STATUSES),
                SelectFilter::make('apparatus_id')->label('Unit')
                    ->options(fn (): array => static::unitOptions()),
                SelectFilter::make('target_kind')->label('Kind of request')->options(EmsLabels::TARGET_KIND),
                Filter::make('submitted')->label('Submitted between')
                    ->form([
                        Grid::make(2)->schema([
                            DatePicker::make('from')->label('From')->native(false)->displayFormat('M j, Y'),
                            DatePicker::make('until')->label('Until')->native(false)->displayFormat('M j, Y'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where('submitted_at', '>=', CarbonImmutable::parse((string) $data['from'], EmsLabels::TIMEZONE)->startOfDay()->utc()))
                            ->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where('submitted_at', '<=', CarbonImmutable::parse((string) $data['until'], EmsLabels::TIMEZONE)->endOfDay()->utc()));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['from'] ?? null)) {
                            $indicators[] = 'From '.CarbonImmutable::parse((string) $data['from'])->format('M j, Y');
                        }
                        if (filled($data['until'] ?? null)) {
                            $indicators[] = 'Until '.CarbonImmutable::parse((string) $data['until'])->format('M j, Y');
                        }

                        return $indicators;
                    }),
                Filter::make('waiting_for_reply')->label('Member message waiting for a reply')
                    ->query(fn (Builder $query): Builder => $query->where(static::lastMessageContext(), 'member')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Open'),
            ])
            ->bulkActions([]);
    }

    /**
     * Units that have at least one request, so the filter lists what exists.
     *
     * @return array<int, string>
     */
    public static function unitOptions(): array
    {
        return InventoryRequest::query()->whereNotNull('apparatus_id')
            ->selectRaw('apparatus_id, MAX(target_label) as label')->groupBy('apparatus_id')->orderBy('label')
            ->pluck('label', 'apparatus_id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryRequests::route('/'),
            'view' => Pages\ViewInventoryRequest::route('/{record}'),
        ];
    }
}
