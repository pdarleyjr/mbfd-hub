<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\StationResource;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Services\StationSupplyRequestWorkflowService;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationSupplyRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplyRequests';

    protected static ?string $title = 'Supply Requests';

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
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'ordered' => 'Ordered',
                        'replenished' => 'Replenished',
                        'denied' => 'Denied',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('markOrdered')
                    ->label('Mark Ordered')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('info')
                    ->visible(fn (StationSupplyRequest $record): bool => $record->status === 'open' && StationResource::canEdit($this->getOwnerRecord()))
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Private Admin Note')
                            ->maxLength(2000),
                    ])
                    ->action(fn (StationSupplyRequest $record, array $data) => $this->transition($record, 'ordered', $data['admin_notes'] ?? null)),
                Tables\Actions\Action::make('markReplenished')
                    ->label('Mark Replenished')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StationSupplyRequest $record): bool => $record->status === 'ordered' && StationResource::canEdit($this->getOwnerRecord()))
                    ->action(fn (StationSupplyRequest $record) => $this->transition($record, 'replenished')),
                Tables\Actions\Action::make('deny')
                    ->label('Deny')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (StationSupplyRequest $record): bool => $record->status === 'open' && StationResource::canEdit($this->getOwnerRecord()))
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Private Denial Note')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->action(fn (StationSupplyRequest $record, array $data) => $this->transition($record, 'denied', $data['admin_notes'])),
            ])
            ->bulkActions([]);
    }

    private function transition(StationSupplyRequest $request, string $status, ?string $note = null): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && StationResource::canEdit($this->getOwnerRecord()), 403);
        app(StationSupplyRequestWorkflowService::class)->transition($request, $status, $actor, $note);
    }
}
