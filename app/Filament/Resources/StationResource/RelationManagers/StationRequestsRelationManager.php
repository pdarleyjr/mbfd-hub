<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Enums\StationRequestStatus;
use App\Enums\StationRequestType;
use App\Filament\Resources\StationRequestResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'stationRequests';

    protected static ?string $title = 'Requests';

    protected static ?string $recordTitleAttribute = 'request_number';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('request_number')->label('Request')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('room.name')
                    ->formatStateUsing(fn (?string $state, $record): string => $state ?: $record->room_name_snapshot ?: 'Station-wide'),
                Tables\Columns\TextColumn::make('request_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StationRequestType::from($state)->label()),
                Tables\Columns\TextColumn::make('title')->limit(50)->searchable(),
                Tables\Columns\TextColumn::make('requester_name_snapshot')->label('Requested By')->searchable(),
                Tables\Columns\TextColumn::make('priority')->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StationRequestStatus::from($state)->label())
                    ->color(fn (string $state): string => StationRequestStatus::from($state)->color()),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Last Updated')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('request_type')
                    ->options(collect(StationRequestType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(StationRequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
                Tables\Filters\SelectFilter::make('priority')
                    ->options(['critical' => 'Critical', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low']),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record): string => StationRequestResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
