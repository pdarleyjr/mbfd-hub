<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Vehicle Inspections';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('completed_at')
            ->columns([
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('inspection_reference')
                    ->label('Inspection')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('current_designation')
                    ->label('Designation')
                    ->getStateUsing(fn (ApparatusInspection $record) => $record->apparatus?->designation ?? $record->designation_at_time ?? '—')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle #')
                    ->getStateUsing(fn (ApparatusInspection $record) => $record->vehicle_number ?? $record->apparatus?->vehicle_number ?? '—'),

                Tables\Columns\TextColumn::make('operator_name')
                    ->label('Operator')
                    ->searchable(),

                Tables\Columns\TextColumn::make('rank')
                    ->label('Rank'),

                Tables\Columns\TextColumn::make('engine_hours')
                    ->label('Engine Hours')
                    ->numeric(decimalPlaces: 1)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('miles')
                    ->label('Mileage')
                    ->numeric()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'primary',
                        'B' => 'warning',
                        'C' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('review_status')
                    ->label('Review')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_review' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('view_results')
                    ->label('View Results')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (ApparatusInspection $record): string => route('filament.admin.resources.apparatuses.view-inspection', [
                        'record' => $record->apparatus_id,
                        'inspection' => $record->id,
                    ]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('approveInspection')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ApparatusInspection $record): bool => $record->review_status === 'pending_review'
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->action(function (ApparatusInspection $record, ApparatusInspectionApprovalService $approvalService): void {
                        $reviewer = auth()->user();
                        abort_unless($reviewer instanceof User && $reviewer->can('approve', $record), 403);
                        $approvalService->approve((int) $record->getKey(), $reviewer);
                    }),
                Tables\Actions\Action::make('rejectInspection')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')
                            ->label('Review note')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->visible(fn (ApparatusInspection $record): bool => $record->review_status === 'pending_review'
                        && (auth()->user()?->can('reject', $record) ?? false))
                    ->action(function (ApparatusInspection $record, array $data, ApparatusInspectionApprovalService $approvalService): void {
                        $reviewer = auth()->user();
                        abort_unless($reviewer instanceof User && $reviewer->can('reject', $record), 403);
                        $approvalService->reject((int) $record->getKey(), $reviewer, trim($data['review_notes']));
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('completed_at', 'desc');
    }
}
