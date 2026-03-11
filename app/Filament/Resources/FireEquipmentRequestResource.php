<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FireEquipmentRequestResource\Pages;
use App\Models\FireEquipmentRequest;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FireEquipmentRequestResource extends Resource
{
    protected static ?string $model = FireEquipmentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Station Management';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Equipment Requests';
    protected static ?string $modelLabel = 'Equipment Request';
    protected static ?string $pluralModelLabel = 'Equipment Requests';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('station.station_number')
                    ->label('Station')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('equipment_type')
                    ->label('Equipment')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'critical', 'emergency' => 'danger',
                        'high' => 'warning',
                        'medium', 'normal' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'approved', 'completed' => 'success',
                        'pending' => 'warning',
                        'denied', 'rejected' => 'danger',
                        'in_progress', 'processing' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('requestedBy.name')
                    ->label('Requested By')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'denied' => 'Denied',
                        'completed' => 'Completed',
                        'in_progress' => 'In Progress',
                    ]),
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'critical' => 'Critical',
                    ]),
                Tables\Filters\SelectFilter::make('station_id')
                    ->relationship('station', 'station_number')
                    ->label('Station'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Request Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')->label('ID'),
                        Infolists\Components\TextEntry::make('station.station_number')->label('Station'),
                        Infolists\Components\TextEntry::make('equipment_type')->label('Equipment Type'),
                        Infolists\Components\TextEntry::make('description'),
                        Infolists\Components\TextEntry::make('priority')
                            ->badge()
                            ->color(fn (string $state): string => match (strtolower($state)) {
                                'critical', 'emergency' => 'danger',
                                'high' => 'warning',
                                'medium', 'normal' => 'info',
                                'low' => 'gray',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match (strtolower($state)) {
                                'approved', 'completed' => 'success',
                                'pending' => 'warning',
                                'denied', 'rejected' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('requestedBy.name')->label('Requested By'),
                        Infolists\Components\TextEntry::make('approvedBy.name')->label('Approved By'),
                        Infolists\Components\TextEntry::make('approved_at')->label('Approved At')->dateTime(),
                        Infolists\Components\TextEntry::make('notes'),
                        Infolists\Components\TextEntry::make('created_at')->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')->dateTime(),
                    ])->columns(2),
                Infolists\Components\Section::make('Form Data')
                    ->schema([
                        Infolists\Components\TextEntry::make('form_data')
                            ->label('Submitted Form Data')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'No form data';
                                }
                                $data = is_array($state) ? $state : json_decode($state, true);
                                if (!is_array($data)) {
                                    return (string) $state;
                                }
                                $lines = [];
                                foreach ($data as $key => $value) {
                                    $label = str_replace('_', ' ', ucfirst($key));
                                    $val = is_array($value) ? json_encode($value) : $value;
                                    $lines[] = "<strong>{$label}:</strong> {$val}";
                                }
                                return implode('<br>', $lines);
                            })
                            ->html()
                            ->columnSpanFull(),
                    ]),
                Infolists\Components\Section::make('Signature')
                    ->schema([
                        Infolists\Components\TextEntry::make('signature')
                            ->label('Signature')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'No signature';
                                }
                                return "<img src=\"{$state}\" alt=\"Signature\" style=\"max-width: 400px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px;\" />";
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFireEquipmentRequests::route('/'),
            'view' => Pages\ViewFireEquipmentRequest::route('/{record}'),
        ];
    }
}
