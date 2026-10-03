<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup\RelationManagers;

use App\Filament\Resources\Workgroup\RelationManagers\Concerns\AuthorizesWorkgroupOwner;
use App\Models\WorkgroupSharedUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class SharedUploadsRelationManager extends RelationManager
{
    use AuthorizesWorkgroupOwner;

    protected static string $relationship = 'sharedUploads';

    protected static ?string $title = 'Shared Uploads';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScopes([SoftDeletingScope::class])
                ->whereHas('session', fn (Builder $sessions): Builder => $sessions->where('workgroup_id', $this->getOwnerRecord()->getKey())))
            ->columns([
                Tables\Columns\TextColumn::make('filename')->searchable(),
                Tables\Columns\TextColumn::make('session.name')->label('Session')->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label('Uploaded By')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')->label('Moved to Trash')->dateTime()->placeholder('Active'),
            ])
            ->filters([Tables\Filters\TrashedFilter::make()->label('Trash')->trueLabel('All')->falseLabel('Trash')->placeholder('Active')])
            ->actions([
                Tables\Actions\Action::make('download')->label('Download')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (WorkgroupSharedUpload $record): string => route('workgroup.shared-upload.download', $record))
                    ->visible(fn (WorkgroupSharedUpload $record): bool => ! $record->trashed()),
            ])
            ->bulkActions([]);
    }
}
