<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EquipmentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'fireEquipmentRequests';

    protected static ?string $title = 'Equipment Requests';

    protected static ?string $recordTitleAttribute = 'equipment_type';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
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
                    ->sortable()
                    ->toggleable(),
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
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(fn (Infolist $infolist) => $infolist
                        ->schema([
                            Infolists\Components\Section::make('Request Details')
                                ->schema([
                                    Infolists\Components\TextEntry::make('id')->label('ID'),
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
                        ])
                    ),
            ]);
    }
}
