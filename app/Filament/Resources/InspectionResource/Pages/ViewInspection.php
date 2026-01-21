<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\InspectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
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
                        Infolists\Components\TextEntry::make('inspection_date')
                            ->label('Inspection Date')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('officer_name')
                            ->label('Officer Name'),
                        Infolists\Components\TextEntry::make('officer_badge')
                            ->label('Officer Badge'),
                        Infolists\Components\TextEntry::make('shift')
                            ->badge()
                            ->color(fn ($state) => match($state) {
                                'A' => 'primary',
                                'B' => 'warning',
                                'C' => 'success',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('notes')
                            ->columnSpanFull()
                            ->placeholder('No notes'),
                    ])
                    ->columns(2),

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
                                    ->color(fn ($state) => match($state) {
                                        'open' => 'danger',
                                        'in_progress' => 'warning',
                                        'resolved' => 'success',
                                        default => 'gray',
                                    }),
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
}
