<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationInspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'stationInspections';

    protected static ?string $title = 'Station Inspections';

    protected static ?string $recordTitleAttribute = 'inspection_type';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('inspection_type')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inspection_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('overall_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'pass', 'passed', 'satisfactory' => 'success',
                        'fail', 'failed', 'unsatisfactory' => 'danger',
                        'partial', 'needs_attention', 'warning' => 'warning',
                        'pending', 'in_progress' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('sog_mandate_acknowledged')
                    ->label('SOG')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inspector.name')
                    ->label('Inspector')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('overall_status')
                    ->label('Status')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'needs_attention' => 'Needs Attention',
                    ]),
            ])
            ->defaultSort('inspection_date', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(fn (Infolist $infolist) => $infolist
                        ->schema([
                            Infolists\Components\Section::make('Inspection Details')
                                ->schema([
                                    Infolists\Components\TextEntry::make('id')->label('ID'),
                                    Infolists\Components\TextEntry::make('inspection_type')->label('Type'),
                                    Infolists\Components\TextEntry::make('inspection_date')->label('Date')->date(),
                                    Infolists\Components\TextEntry::make('overall_status')
                                        ->label('Status')
                                        ->badge()
                                        ->color(fn (string $state): string => match (strtolower($state)) {
                                            'pass', 'passed', 'satisfactory' => 'success',
                                            'fail', 'failed', 'unsatisfactory' => 'danger',
                                            'partial', 'needs_attention' => 'warning',
                                            default => 'gray',
                                        }),
                                    Infolists\Components\TextEntry::make('inspector.name')->label('Inspector'),
                                    Infolists\Components\TextEntry::make('reviewer.name')->label('Reviewed By'),
                                    Infolists\Components\TextEntry::make('reviewed_at')->label('Reviewed At')->dateTime(),
                                    Infolists\Components\TextEntry::make('notes'),
                                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                                ])->columns(2),
                            Infolists\Components\Section::make('Checklist Items')
                                ->schema([
                                    Infolists\Components\TextEntry::make('form_data')
                                        ->label('Inspection Checklist')
                                        ->formatStateUsing(function ($state) {
                                            if (empty($state)) {
                                                return 'No checklist data';
                                            }
                                            $data = is_array($state) ? $state : json_decode($state, true);
                                            if (!is_array($data)) {
                                                return (string) $state;
                                            }
                                            $html = '<div style="display: grid; gap: 8px;">';
                                            foreach ($data as $key => $value) {
                                                $label = str_replace('_', ' ', ucfirst($key));
                                                if (is_bool($value)) {
                                                    $icon = $value ? '✅' : '❌';
                                                    $html .= "<div>{$icon} <strong>{$label}</strong></div>";
                                                } elseif (is_array($value)) {
                                                    $html .= "<div><strong>{$label}:</strong><ul style='margin-left: 16px;'>";
                                                    foreach ($value as $subKey => $subVal) {
                                                        $subLabel = is_string($subKey) ? str_replace('_', ' ', ucfirst($subKey)) : $subKey;
                                                        $subDisplay = is_bool($subVal) ? ($subVal ? '✅' : '❌') : $subVal;
                                                        $html .= "<li>{$subLabel}: {$subDisplay}</li>";
                                                    }
                                                    $html .= '</ul></div>';
                                                } else {
                                                    $html .= "<div><strong>{$label}:</strong> {$value}</div>";
                                                }
                                            }
                                            $html .= '</div>';
                                            return $html;
                                        })
                                        ->html()
                                        ->columnSpanFull(),
                                ]),
                            Infolists\Components\Section::make('Signatures')
                                ->schema([
                                    Infolists\Components\TextEntry::make('inspector_signature')
                                        ->label('Inspector Signature')
                                        ->formatStateUsing(function ($state) {
                                            if (empty($state)) {
                                                return 'No signature';
                                            }
                                            return "<img src=\"{$state}\" alt=\"Inspector Signature\" style=\"max-width: 400px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px;\" />";
                                        })
                                        ->html(),
                                    Infolists\Components\TextEntry::make('reviewer_signature')
                                        ->label('Reviewer Signature')
                                        ->formatStateUsing(function ($state) {
                                            if (empty($state)) {
                                                return 'No signature';
                                            }
                                            return "<img src=\"{$state}\" alt=\"Reviewer Signature\" style=\"max-width: 400px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px;\" />";
                                        })
                                        ->html(),
                                ])->columns(2)
                                ->collapsible(),
                        ])
                    ),
            ]);
    }
}
