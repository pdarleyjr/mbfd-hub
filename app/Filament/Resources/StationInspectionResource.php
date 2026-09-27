<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\StationInspectionResource\Pages;
use App\Models\StationInspection;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StationInspectionResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = StationInspection::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Station Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Station Inspections';

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('station.station_number')
                    ->label('Station')
                    ->sortable()
                    ->searchable(),
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
                Tables\Columns\TextColumn::make('review_status')
                    ->label('Review')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        'reviewed' => 'success',
                        'needs_follow_up' => 'danger',
                        default => 'warning',
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
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('overall_status')
                    ->label('Status')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'needs_attention' => 'Needs Attention',
                    ]),
                Tables\Filters\SelectFilter::make('station_id')
                    ->relationship('station', 'station_number')
                    ->label('Station'),
            ])
            ->defaultSort('inspection_date', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Inspection Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')->label('ID'),
                        Infolists\Components\TextEntry::make('station.station_number')->label('Station'),
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
                        Infolists\Components\IconEntry::make('sog_mandate_acknowledged')
                            ->label('SOG Mandate Acknowledged')
                            ->boolean(),
                        Infolists\Components\TextEntry::make('extinguishing_system_date')
                            ->label('Extinguishing System Date')
                            ->date(),
                        Infolists\Components\TextEntry::make('inspector.name')->label('Inspector'),
                        Infolists\Components\TextEntry::make('reviewer.name')->label('Reviewed By'),
                        Infolists\Components\TextEntry::make('reviewed_at')->label('Reviewed At')->dateTime(),
                        Infolists\Components\TextEntry::make('review_status')
                            ->label('Review Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString()),
                        Infolists\Components\TextEntry::make('review_note')->label('Review Note')->placeholder('—'),
                        Infolists\Components\TextEntry::make('notes'),
                        Infolists\Components\TextEntry::make('created_at')->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')->dateTime(),
                    ])->columns(2),
                Infolists\Components\Section::make('Checklist Items')
                    ->schema([
                        Infolists\Components\ViewEntry::make('form_data')
                            ->label('Inspection Checklist')
                            ->view('filament.infolists.station-inspection-checklist')
                            ->columnSpanFull(),
                    ]),
                Infolists\Components\Section::make('Signatures')
                    ->schema([
                        Infolists\Components\ViewEntry::make('inspector_signature')
                            ->label('Inspector Signature')
                            ->view('filament.infolists.station-inspection-signature'),
                        Infolists\Components\ViewEntry::make('reviewer_signature')
                            ->label('Reviewer Signature')
                            ->view('filament.infolists.station-inspection-signature'),
                    ])->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStationInspections::route('/'),
            'view' => Pages\ViewStationInspection::route('/{record}'),
        ];
    }
}
