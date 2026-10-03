<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Support\ArchiveFilter;
use App\Models\StationInventoryAudit;
use App\Models\StationSupplyRequest;
use App\Services\StationSupplyRequestWorkflowService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationSupplyRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplyRequests';

    protected static ?string $title = 'Supply Requests';

    public function isReadOnly(): bool
    {
        return ! auth()->user()->can('admin.stations.manage');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('request_text')
                    ->label('Request')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_by_name')
                    ->label('Requested By')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_by_shift')
                    ->label('Shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'open',
                        'info' => 'ordered',
                        'success' => 'replenished',
                        'danger' => 'denied',
                    ]),
                Tables\Columns\TextColumn::make('admin_notes')
                    ->label('Admin Notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                ArchiveFilter::make(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'ordered' => 'Ordered',
                        'replenished' => 'Replenished',
                        'denied' => 'Denied',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->infolist([
                    Infolists\Components\TextEntry::make('request_text')->label('Request'),
                    Infolists\Components\TextEntry::make('created_by_name')->label('Requested by'),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('public_response')->label('Member response'),
                    Infolists\Components\TextEntry::make('admin_notes')->label('Internal notes'),
                    Infolists\Components\TextEntry::make('history')->label('History')
                        ->state(fn (StationSupplyRequest $record): string => StationInventoryAudit::query()
                            ->where('station_id', $record->station_id)->where('to_value->request_id', $record->id)
                            ->orderBy('id')->get()->map(fn (StationInventoryAudit $event): string => $event->created_at->timezone('America/New_York')->format('M j, Y g:i A').' · '.$event->actor_name.' · '.str($event->action)->headline()
                                .' · '.($event->to_value['status'] ?? '')
                            )->implode("\n"))->columnSpanFull(),
                ]),
                Tables\Actions\EditAction::make()
                    ->visible(fn (StationSupplyRequest $record): bool => auth()->user()->can('admin.stations.manage') && ! $record->isArchived())
                    ->using(function (StationSupplyRequest $record, array $data): StationSupplyRequest {
                        app(StationSupplyRequestWorkflowService::class)->update($record, auth()->user(), $data);

                        return $record;
                    })
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'open' => 'Open',
                                'ordered' => 'Ordered',
                                'replenished' => 'Replenished',
                                'denied' => 'Denied',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Internal Notes')->maxLength(5000),
                        Forms\Components\Textarea::make('public_response')
                            ->label('Member response')->maxLength(5000),
                    ]),
                Tables\Actions\Action::make('archive')
                    ->label('Archive')->icon('heroicon-o-archive-box')->color('gray')
                    ->visible(fn (StationSupplyRequest $record): bool => auth()->user()->can('admin.stations.manage') && ! $record->isArchived())
                    ->form([Forms\Components\Textarea::make('reason')->label('Archive reason')->maxLength(2000)])
                    ->action(fn (StationSupplyRequest $record, array $data) => app(StationSupplyRequestWorkflowService::class)->archive($record, auth()->user(), $data['reason'] ?? null)),
                Tables\Actions\Action::make('restore')
                    ->label('Restore')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (StationSupplyRequest $record): bool => auth()->user()->can('admin.stations.manage') && $record->isArchived())
                    ->action(fn (StationSupplyRequest $record) => app(StationSupplyRequestWorkflowService::class)->restore($record, auth()->user())),
            ])
            ->bulkActions([]);
    }
}
