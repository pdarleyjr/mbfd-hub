<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\InspectionResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        $inspection = $this->getRecord();
        abort_unless($inspection instanceof ApparatusInspection, 404);

        return [
            Actions\Action::make('reviewFullInspection')
                ->label('Review full inspection')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(fn (): string => ApparatusResource::getUrl('view-inspection', [
                    'record' => $inspection->apparatus_id,
                    'inspection' => $inspection->getKey(),
                ]))
                ->visible(function () use ($inspection): bool {
                    $apparatus = $inspection->getAttribute('apparatus');

                    return $inspection->review_status === 'pending_review'
                        && $apparatus instanceof Apparatus
                        && ApparatusResource::canView($apparatus);
                }),
            Actions\DeleteAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Inspection Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('apparatus.name')
                            ->label('Apparatus'),
                        Infolists\Components\TextEntry::make('completed_at')
                            ->label('Inspection Date')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('operator_name')
                            ->label('Operator Name'),
                        Infolists\Components\TextEntry::make('employee.employee_id')
                            ->label('Employee ID')
                            ->placeholder('Not linked'),
                        Infolists\Components\TextEntry::make('shift')
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'A' => 'primary',
                                'B' => 'warning',
                                'C' => 'success',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('inspection_reference')
                            ->label('Inspection Reference')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('review_status')
                            ->label('Review Status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('engine_hours')
                            ->label('Engine Hours')
                            ->numeric(decimalPlaces: 1)
                            ->placeholder('Not reported'),
                        Infolists\Components\TextEntry::make('miles')
                            ->label('Miles')
                            ->numeric()
                            ->placeholder('Not reported'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Pending Review Evidence')
                    ->description('This submission has not changed readiness, defects, or meter records. Use Review full inspection before deciding; that view contains the complete checklist evidence and review actions.')
                    ->visible(fn ($record): bool => $record->review_status === 'pending_review')
                    ->schema([
                        Infolists\Components\TextEntry::make('engine_hours')
                            ->label('Reported Engine Hours')
                            ->numeric(decimalPlaces: 1)
                            ->placeholder('Not reported'),
                        Infolists\Components\TextEntry::make('miles')
                            ->label('Reported Miles')
                            ->numeric()
                            ->placeholder('Not reported'),
                        Infolists\Components\RepeatableEntry::make('pending_effects.checklist_v2.field_values')
                            ->label('Checklist v2 evidence — reported fields')
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Field'),
                                Infolists\Components\TextEntry::make('value')
                                    ->label('Reported value')
                                    ->formatStateUsing(fn (mixed $state): string => $this->formatV2ChecklistValue($state)),
                                Infolists\Components\TextEntry::make('input_type')
                                    ->label('Type')
                                    ->formatStateUsing(fn (mixed $state): string => ucfirst((string) $state)),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->visible(fn (mixed $state): bool => is_array($state) && $state !== []),
                        Infolists\Components\RepeatableEntry::make('pending_effects.checklist_v2.scheduled_tasks')
                            ->label('Checklist v2 evidence — scheduled duties')
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Duty'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Result')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'Present' => 'success',
                                        'Missing' => 'danger',
                                        'Damaged' => 'warning',
                                        default => 'gray',
                                    }),
                                Infolists\Components\TextEntry::make('recurrence_label')
                                    ->label('Recurrence'),
                                Infolists\Components\TextEntry::make('instructions')
                                    ->label('Instructions')
                                    ->columnSpanFull()
                                    ->placeholder('No instructions'),
                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Notes')
                                    ->columnSpanFull()
                                    ->placeholder('No notes'),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->visible(fn (mixed $state): bool => is_array($state) && $state !== []),
                        Infolists\Components\RepeatableEntry::make('pending_effects.defects')
                            ->label('Reported Pending Defects')
                            ->schema([
                                Infolists\Components\TextEntry::make('compartment')
                                    ->label('Compartment'),
                                Infolists\Components\TextEntry::make('item')
                                    ->label('Item'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color('warning'),
                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Notes')
                                    ->columnSpanFull()
                                    ->placeholder('No notes'),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->hidden(fn ($record): bool => empty($record->pending_effects['defects'] ?? [])),
                        Infolists\Components\TextEntry::make('no_pending_defects')
                            ->label('')
                            ->default('No defects were reported with this pending submission.')
                            ->color('success')
                            ->hidden(fn ($record): bool => ! empty($record->pending_effects['defects'] ?? [])),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Review History')
                    ->visible(fn ($record): bool => $record->reviewEvents()->exists())
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('reviewEvents')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Reviewed at')
                                    ->dateTime(),
                                Infolists\Components\TextEntry::make('previous_status')
                                    ->label('Previous status')
                                    ->badge(),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Decision')
                                    ->badge(),
                                Infolists\Components\TextEntry::make('changedByUser.name')
                                    ->label('Reviewer')
                                    ->placeholder('User no longer active'),
                                Infolists\Components\TextEntry::make('metadata.reviewer_name')
                                    ->label('Reviewer name snapshot')
                                    ->placeholder('Not recorded'),
                                Infolists\Components\TextEntry::make('internal_note')
                                    ->label('Review note')
                                    ->placeholder('No note')
                                    ->columnSpanFull(),
                                Infolists\Components\RepeatableEntry::make('metadata.submitted_effects.checklist_v2.field_values')
                                    ->label('Checklist v2 evidence — reported fields')
                                    ->schema([
                                        Infolists\Components\TextEntry::make('name')
                                            ->label('Field'),
                                        Infolists\Components\TextEntry::make('value')
                                            ->label('Reported value')
                                            ->formatStateUsing(fn (mixed $state): string => $this->formatV2ChecklistValue($state)),
                                        Infolists\Components\TextEntry::make('input_type')
                                            ->label('Type')
                                            ->formatStateUsing(fn (mixed $state): string => ucfirst((string) $state)),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull()
                                    ->visible(fn (mixed $state): bool => is_array($state) && $state !== []),
                                Infolists\Components\RepeatableEntry::make('metadata.submitted_effects.checklist_v2.scheduled_tasks')
                                    ->label('Checklist v2 evidence — scheduled duties')
                                    ->schema([
                                        Infolists\Components\TextEntry::make('name')
                                            ->label('Duty'),
                                        Infolists\Components\TextEntry::make('status')
                                            ->label('Result')
                                            ->badge()
                                            ->color(fn ($state) => match ($state) {
                                                'Present' => 'success',
                                                'Missing' => 'danger',
                                                'Damaged' => 'warning',
                                                default => 'gray',
                                            }),
                                        Infolists\Components\TextEntry::make('recurrence_label')
                                            ->label('Recurrence'),
                                        Infolists\Components\TextEntry::make('instructions')
                                            ->label('Instructions')
                                            ->columnSpanFull()
                                            ->placeholder('No instructions'),
                                        Infolists\Components\TextEntry::make('notes')
                                            ->label('Notes')
                                            ->columnSpanFull()
                                            ->placeholder('No notes'),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull()
                                    ->visible(fn (mixed $state): bool => is_array($state) && $state !== []),
                            ])
                            ->columns(2),
                    ]),

                Infolists\Components\Section::make('Defects Found')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('defects')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('compartment')
                                    ->label('Compartment'),
                                Infolists\Components\TextEntry::make('item')
                                    ->label('Item'),
                                Infolists\Components\TextEntry::make('issue_type')
                                    ->label('Issue Type')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state))),
                                Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'Missing' => 'danger',
                                        'Damaged' => 'warning',
                                        'Present' => 'success',
                                        default => 'gray',
                                    }),
                                Infolists\Components\IconEntry::make('resolved')
                                    ->label('Resolved')
                                    ->boolean(),
                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Notes')
                                    ->columnSpanFull()
                                    ->placeholder('No notes'),
                            ])
                            ->columns(2)
                            ->hidden(fn ($record) => $record->defects->isEmpty()),
                        Infolists\Components\TextEntry::make('no_defects')
                            ->label('')
                            ->default('No defects found during this inspection.')
                            ->color('success')
                            ->hidden(fn ($record) => $record->defects->isNotEmpty()),
                    ]),
            ]);
    }

    private function formatV2ChecklistValue(mixed $value): string
    {
        return match ($value) {
            null, '' => 'Not reported',
            true => 'Yes',
            false => 'No',
            default => (string) $value,
        };
    }
}
