<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\StationResource;
use App\Filament\Support\ArchiveFilter;
use App\Models\Station;
use App\Models\StationInventorySubmission;
use App\Services\OperationalEvidenceArchiveService;
use Filament\Infolists;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InventorySubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventorySubmissions';

    protected static ?string $title = 'Inventory Submissions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Station && StationResource::canView($ownerRecord);
    }

    protected function canView(Model $record): bool
    {
        return $record instanceof StationInventorySubmission
            && (int) $record->station_id === (int) $this->getOwnerRecord()->getKey()
            && static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                ArchiveFilter::make(),
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            // These records deliberately have no standalone resource. The
            // owning Station is their canonical admin context. Evidence stays intact.
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist([
                        Infolists\Components\TextEntry::make('employee_name')->label('Submitted by')->placeholder('Unknown employee'),
                        Infolists\Components\TextEntry::make('shift')->label('Shift')->placeholder('—'),
                        Infolists\Components\TextEntry::make('submitted_at')->label('Submitted')->dateTime('M j, Y g:i A')->timezone('America/New_York'),
                        Infolists\Components\TextEntry::make('archived_at')->label('Archived At')->dateTime()->placeholder('Active'),
                        Infolists\Components\TextEntry::make('archivedBy.name')->label('Archived By')->placeholder('—'),
                        Infolists\Components\TextEntry::make('archive_reason')->label('Archive Reason')->placeholder('—'),
                        Infolists\Components\RepeatableEntry::make('archive_history')
                            ->label('Archive history')->schema([
                                Infolists\Components\TextEntry::make('event_type')->label('Action')
                                    ->formatStateUsing(fn (string $state): string => $state === 'record_archived' ? 'Archived' : 'Restored'),
                                Infolists\Components\TextEntry::make('actor_name')->label('By'),
                                Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime(),
                                Infolists\Components\TextEntry::make('metadata.archive_reason')->label('Archive reason'),
                            ])->columns(2)->columnSpanFull()
                            ->visible(fn (StationInventorySubmission $record): bool => filled($record->archive_history)),
                        Infolists\Components\TextEntry::make('notes')->label('Notes')->placeholder('No notes')->columnSpanFull(),
                        Infolists\Components\TextEntry::make('items')
                            ->label('Submitted inventory')
                            ->state(static fn (StationInventorySubmission $record): string => collect($record->items ?? [])
                                ->map(static fn (array $item): string => collect($item)
                                    ->filter(static fn (mixed $value): bool => $value !== null && $value !== '')
                                    ->map(static fn (mixed $value, string $key): string => str($key)->headline().': '.$value)
                                    ->implode(' · '))
                                ->implode("\n"))
                            ->placeholder('No submitted inventory lines')
                            ->columnSpanFull(),
                    ]),
                Tables\Actions\Action::make('downloadPdf')
                    ->label('Download PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (StationInventorySubmission $record): string => route('download-inventory-pdf', $record))
                    ->visible(fn (StationInventorySubmission $record): bool => filled($record->pdf_path)),
                Tables\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->form([\Filament\Forms\Components\Textarea::make('archive_reason')->label('Reason')->required()->maxLength(2000)])
                    ->visible(fn (StationInventorySubmission $record): bool => (auth()->user()?->can('admin.stations.manage') ?? false) && ! $record->isArchived())
                    ->action(fn (StationInventorySubmission $record, array $data) => app(OperationalEvidenceArchiveService::class)->archive($record, auth()->user(), $data['archive_reason'] ?? null)),
                Tables\Actions\Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (StationInventorySubmission $record): bool => (auth()->user()?->can('admin.stations.manage') ?? false) && $record->isArchived())
                    ->action(fn (StationInventorySubmission $record) => app(OperationalEvidenceArchiveService::class)->restore($record, auth()->user())),
            ])
            ->bulkActions([]);
    }
}
