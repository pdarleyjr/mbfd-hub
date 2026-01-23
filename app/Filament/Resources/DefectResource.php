<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DefectResource\Pages;
use App\Models\ApparatusDefect;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

class DefectResource extends Resource
{
    protected static ?string $model = ApparatusDefect::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Missing / Damaged Equipment';

    protected static ?string $modelLabel = 'Equipment Issue';

    protected static ?string $pluralModelLabel = 'Missing / Damaged Equipment';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('apparatus_id')
                    ->relationship('apparatus', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                
                Forms\Components\TextInput::make('compartment')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\TextInput::make('item')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\Select::make('issue_type')
                    ->required()
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ]),
                
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull()
                    ->rows(3),
                
                Forms\Components\Select::make('status')
                    ->required()
                    ->default('open')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                    ]),
                
                Forms\Components\Textarea::make('resolution_notes')
                    ->columnSpanFull()
                    ->rows(3)
                    ->visible(fn (Forms\Get $get) => $get('status') === 'resolved'),
                
                Forms\Components\DateTimePicker::make('resolved_at')
                    ->visible(fn (Forms\Get $get) => $get('status') === 'resolved'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('apparatus.name')
                    ->label('Apparatus')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('compartment')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('item')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('status')
                    ->colors([
                        'danger' => 'open',
                        'warning' => 'in_progress',
                        'success' => 'resolved',
                    ]),
                
                Tables\Columns\TextColumn::make('issue_type')
                    ->label('Issue Type')
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state))),
                
                Tables\Columns\TextColumn::make('reported_date')
                    ->label('Reported Date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                    ])
                    ->default('open'),
                
                SelectFilter::make('issue_type')
                    ->label('Issue Type')
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_resolved')
                    ->label('Mark Resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ApparatusDefect $record) => $record->status !== 'resolved')
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Resolution Notes')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (ApparatusDefect $record, array $data) {
                        $record->update([
                            'status' => 'resolved',
                            'resolution_notes' => $data['resolution_notes'],
                            'resolved_at' => now(),
                        ]);
                    })
                    ->successNotification(
                        fn () => \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Defect Resolved')
                            ->body('The defect has been marked as resolved.')
                    ),
                
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('reported_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDefects::route('/'),
            'edit' => Pages\EditDefect::route('/{record}/edit'),
        ];
    }
}
