<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnitMasterVehicleResource\Pages;

use App\Filament\Resources\UnitMasterVehicleResource;
use App\Filament\Resources\UnitMasterVehicleResource\RelationManagers\DocumentsRelationManager;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentSource;
use App\Services\Fleet\VehicleDocumentLibrary;
use App\Support\Fleet\VehicleDocumentAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class ReviewVehicleDocuments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = UnitMasterVehicleResource::class;

    protected static string $view = 'filament.fleet.vehicle-document-review';

    protected static ?string $title = 'Historical document review';

    public function mount(): void
    {
        abort_unless(VehicleDocumentAccess::canManage(auth()->user()), 403);
    }

    protected function getHeaderActions(): array
    {
        return [Actions\Action::make('inventory')->label('Master Vehicle Inventory')->color('gray')->url(UnitMasterVehicleResource::getUrl())];
    }

    /** Counts come from durable imported records, never estimated source totals. */
    public function importCounts(): array
    {
        $query = $this->visibleDocuments();
        $ids = (clone $query)->select('vehicle_documents.id');

        return [
            'Imported originals' => (clone $query)->count(),
            'Source references' => VehicleDocumentSource::query()->whereIn('document_id', $ids)->count(),
            'Verified' => (clone $query)->where('review_status', 'verified')->count(),
            'Needs review' => (clone $query)->where('review_status', 'needs_review')->count(),
            'Unmatched' => (clone $query)->where('review_status', 'unmatched')->count(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query($this->visibleDocuments()->where('review_status', '!=', 'verified')->with('sources'))
            ->columns([
                Split::make([
                    Stack::make([
                        Tables\Columns\TextColumn::make('title')->searchable()->wrap()->weight('semibold'),
                        Tables\Columns\TextColumn::make('original_filename')->searchable()->color('gray')->wrap(),
                    ]),
                    Tables\Columns\TextColumn::make('category')->formatStateUsing(fn (VehicleDocument $record): string => $record->categoryLabel())->badge(),
                    Tables\Columns\TextColumn::make('review_status')->badge()->color('warning')
                        ->formatStateUsing(fn (string $state): string => $state === 'needs_review' ? 'Needs review' : 'Unmatched'),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('review_status')->label('Status')->options(['needs_review' => 'Needs review', 'unmatched' => 'Unmatched']),
                Tables\Filters\SelectFilter::make('category')->options(VehicleDocument::CATEGORY_LABELS),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')->label('Preview')->icon('heroicon-o-eye')->color('gray')
                    ->visible(fn (VehicleDocument $record): bool => $record->canPreview())
                    ->url(fn (VehicleDocument $record): string => route('vehicle-documents.review-preview', $record))->openUrlInNewTab(),
                Tables\Actions\Action::make('download')->label('Download')->color('gray')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (VehicleDocument $record): string => route('vehicle-documents.review-download', $record)),
                Tables\Actions\Action::make('assignVehicle')->label('Review / assign vehicle')->icon('heroicon-o-link')
                    ->modalHeading('Review the document and confirm its vehicle')
                    ->form([
                        Forms\Components\Textarea::make('evidence_summary')->label('Observed vehicle identifiers and source references')->disabled()->rows(5),
                        ...DocumentsRelationManager::metadataFields(),
                        Forms\Components\Select::make('vehicle_ids')->label('Vehicles actually involved')->multiple()->searchable()->required()
                            ->helperText('Confirm from the original document. Current apparatus assignments alone do not establish historical identity.')
                            ->options(fn (): array => UnitMasterVehicle::query()->orderBy('veh_number')->get()->mapWithKeys(fn (UnitMasterVehicle $vehicle): array => [$vehicle->id => $vehicle->displayName()])->all()),
                        Forms\Components\TextInput::make('decision_note')->label('Evidence supporting this decision')->required()->maxLength(500),
                    ])
                    ->fillForm(fn (VehicleDocument $record): array => [
                        ...$record->only(['title', 'category', 'description', 'document_date', 'report_number', 'is_sensitive', 'legal_hold', 'retention_series']),
                        'vehicle_ids' => $record->vehicles()->pluck('unit_master_vehicles.id')->all(),
                        'evidence_summary' => $this->evidenceSummary($record),
                    ])
                    ->action(function (VehicleDocument $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && VehicleDocumentAccess::canManageDocument($actor, $record), 403);
                        $data['review_status'] = 'verified';
                        $data['classification_evidence'] = [
                            ...($record->classification_evidence ?? []),
                            'reviewed_by' => $actor->id,
                            'reviewed_at' => now()->toIso8601String(),
                            'decision_note' => $data['decision_note'],
                        ];
                        app(VehicleDocumentLibrary::class)->updateMetadata($record, $actor, $data, $data['vehicle_ids']);
                        Notification::make()->title('Vehicle links confirmed')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No documents waiting for review')
            ->emptyStateDescription('Verified documents are available within their vehicle profiles.')
            ->defaultSort('id')->paginated([10, 25, 50]);
    }

    private function visibleDocuments(): \Illuminate\Database\Eloquent\Builder
    {
        abort_unless(VehicleDocumentAccess::canManage(auth()->user()), 403);
        $query = VehicleDocument::query();
        if (! VehicleDocumentAccess::canSensitive(auth()->user())) {
            $query->where('is_sensitive', false);
        }

        return $query;
    }

    private function evidenceSummary(VehicleDocument $record): string
    {
        $evidence = $record->classification_evidence ?? [];
        $lines = ['Original source references:', ...$record->sources->pluck('source_relative_path')->all()];
        $observations = collect($evidence['observations'] ?? [])->filter(fn ($item): bool => is_array($item));
        $lines[] = '';
        $lines[] = 'Identifiers observed in the original:';
        foreach ($observations as $observation) {
            $kind = match ($observation['kind'] ?? '') {
                'vin' => 'VIN',
                'fleet', 'fleet_number' => 'Fleet number',
                'plate' => 'License plate',
                default => str((string) ($observation['kind'] ?? 'Identifier'))->headline()->toString(),
            };
            $page = isset($observation['page']) ? ' (page '.$observation['page'].')' : '';
            $lines[] = $kind.': '.($observation['value'] ?? $observation['normalized'] ?? '').$page;
        }
        if ($observations->isEmpty()) {
            $lines[] = 'No supported vehicle identifier was extracted. Review the original.';
        }
        foreach ($evidence['review_reasons'] ?? [] as $reason) {
            $lines[] = 'Check: '.str((string) $reason)->headline()->toString();
        }
        $candidateIds = $record->sources->flatMap(fn ($source): array => $source->metadata['candidate_vehicle_ids'] ?? [])->unique()->all();
        if ($candidateIds !== []) {
            $lines[] = '';
            $lines[] = 'Possible vehicles to check against the original (not confirmed):';
            foreach (UnitMasterVehicle::query()->whereKey($candidateIds)->orderBy('veh_number')->get() as $vehicle) {
                $lines[] = $vehicle->displayName();
            }
        }

        return implode("\n", $lines);
    }
}
