<?php

namespace App\Filament\Widgets;

use App\Models\ProjectUpdate;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentProjectUpdatesWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    
    protected int | string | array $columnSpan = 'full';

    public function getPollingInterval(): ?string
    {
        return '60s';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProjectUpdate::query()
                    ->with(['capitalProject', 'user'])
                    ->latest()
                    ->limit(10)
            )
            ->heading('Recent Project Updates')
            ->description('Latest updates across all projects')
            ->columns([
                Tables\Columns\TextColumn::make('capitalProject.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable()
                    ->url(fn (ProjectUpdate $record): string => route('filament.admin.resources.capital-projects.view', ['record' => $record->capital_project_id]))
                    ->color('primary')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('update_text')
                    ->label('Update')
                    ->limit(100)
                    ->tooltip(fn (ProjectUpdate $record): string => $record->update_text)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Posted By')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i A')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View Project')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ProjectUpdate $record): string => route('filament.admin.resources.capital-projects.view', ['record' => $record->capital_project_id]))
                    ->openUrlInNewTab(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('viewAll')
                    ->label('View All Updates')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(route('filament.admin.resources.capital-projects.index'))
                    ->openUrlInNewTab(),
            ])
            ->paginated(false);
    }
}
