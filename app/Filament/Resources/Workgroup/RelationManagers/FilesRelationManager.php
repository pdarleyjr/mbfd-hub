<?php

namespace App\Filament\Resources\Workgroup\RelationManagers;

use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Files';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('filepath')
                    ->label('File')
                    ->directory('workgroup-files')
                    ->visibility('private')
                    ->required(),
                Forms\Components\Select::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\TextInput::make('file_type')
                    ->label('File Type')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->columns([
                Tables\Columns\TextColumn::make('filename')
                    ->label('Filename')
                    ->searchable(),
                Tables\Columns\TextColumn::make('session.name')
                    ->label('Session')
                    ->searchable(),
                Tables\Columns\TextColumn::make('file_type')
                    ->label('Type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('Uploaded By')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Uploaded'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => route('workgroup.file.download', ['file' => $record->id]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
