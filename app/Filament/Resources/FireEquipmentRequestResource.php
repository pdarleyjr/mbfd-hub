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
                Tables\Columns\TextColumn::make('requested_by_name')
                    ->label('Requested By')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'completed' => 'success',
                        'support_services_approved' => 'info',
                        'shift_chief_approved' => 'warning',
                        'pending' => 'gray',
                        'denied', 'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match (strtolower($state)) {
                        'pending' => 'Pending',
                        'shift_chief_approved' => 'Shift Chief Approved',
                        'support_services_approved' => 'Support Svcs Approved',
                        'completed' => 'Completed',
                        'denied' => 'Denied',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('pd_case_number')
                    ->label('PD Case #')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'shift_chief_approved' => 'Shift Chief Approved',
                        'support_services_approved' => 'Support Svcs Approved',
                        'completed' => 'Completed',
                        'denied' => 'Denied',
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
                        Infolists\Components\TextEntry::make('requested_by_name')->label('Requested By'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match (strtolower($state)) {
                                'completed' => 'success',
                                'support_services_approved' => 'info',
                                'shift_chief_approved' => 'warning',
                                'pending' => 'gray',
                                'denied' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match (strtolower($state)) {
                                'pending' => 'Pending',
                                'shift_chief_approved' => 'Shift Chief Approved',
                                'support_services_approved' => 'Support Svcs Approved',
                                'completed' => 'Completed',
                                'denied' => 'Denied',
                                default => ucfirst(str_replace('_', ' ', $state)),
                            }),
                        Infolists\Components\TextEntry::make('pd_case_number')
                            ->label('PD Case Number')
                            ->visible(fn ($record) => !empty($record->pd_case_number)),
                        Infolists\Components\TextEntry::make('explanation')->label('Explanation'),
                        Infolists\Components\TextEntry::make('description')->label('Legacy Description')
                            ->visible(fn ($record) => !empty($record->description)),
                        Infolists\Components\TextEntry::make('approvedBy.name')->label('Approved By'),
                        Infolists\Components\TextEntry::make('approved_at')->label('Approved At')->dateTime(),
                        Infolists\Components\TextEntry::make('notes'),
                        Infolists\Components\TextEntry::make('created_at')->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')->dateTime(),
                    ])->columns(2),
                Infolists\Components\Section::make('Requested Items')
                    ->schema([
                        Infolists\Components\TextEntry::make('form_data')
                            ->label('Items')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'No items data';
                                }
                                $data = is_array($state) ? $state : json_decode($state, true);
                                if (!is_array($data)) {
                                    return (string) $state;
                                }

                                $items = $data['items'] ?? $data;
                                if (!is_array($items) || empty($items)) {
                                    // Fallback: render as key-value
                                    $lines = [];
                                    foreach ($data as $key => $value) {
                                        $label = str_replace('_', ' ', ucfirst($key));
                                        $val = is_array($value) ? json_encode($value) : $value;
                                        $lines[] = "<strong>{$label}:</strong> {$val}";
                                    }
                                    return implode('<br>', $lines);
                                }

                                $html = '<div style="display: grid; gap: 12px;">';
                                foreach ($items as $idx => $item) {
                                    $num = $idx + 1;
                                    $desc = htmlspecialchars($item['description'] ?? 'N/A');
                                    $qty = $item['quantity'] ?? 1;
                                    $reason = htmlspecialchars($item['reason'] ?? 'N/A');
                                    $pdCase = $item['pd_case_number'] ?? null;
                                    $photo = $item['photo'] ?? null;

                                    $html .= "<div style='background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;'>";
                                    $html .= "<div style='font-weight: 600; margin-bottom: 4px;'>#{$num}: {$desc} × {$qty}</div>";
                                    $html .= "<div style='color: #6b7280; font-size: 0.875rem;'>Reason: <strong>{$reason}</strong></div>";

                                    if ($pdCase) {
                                        $html .= "<div style='color: #dc2626; font-size: 0.875rem; margin-top: 4px;'>PD Case No: <strong>" . htmlspecialchars($pdCase) . "</strong></div>";
                                    }
                                    if ($photo && str_starts_with($photo, 'data:image')) {
                                        $html .= "<div style='margin-top: 8px;'><img src=\"{$photo}\" alt=\"Damage Photo\" style=\"max-width: 300px; max-height: 200px; border: 1px solid #e5e7eb; border-radius: 8px;\" /></div>";
                                    }

                                    $html .= '</div>';
                                }
                                $html .= '</div>';
                                return $html;
                            })
                            ->html()
                            ->columnSpanFull(),
                    ]),
                Infolists\Components\Section::make('Signatures')
                    ->schema([
                        Infolists\Components\TextEntry::make('signature')
                            ->label('Member Signature')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'No signature';
                                }
                                return "<img src=\"{$state}\" alt=\"Member Signature\" style=\"max-width: 400px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px;\" />";
                            })
                            ->html(),
                        Infolists\Components\TextEntry::make('officer_signature')
                            ->label('Company Officer Signature')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'No signature';
                                }
                                return "<img src=\"{$state}\" alt=\"Officer Signature\" style=\"max-width: 400px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px;\" />";
                            })
                            ->html(),
                    ])->columns(2)
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
