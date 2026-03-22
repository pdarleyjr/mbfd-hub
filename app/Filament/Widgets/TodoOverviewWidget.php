<?php

namespace App\Filament\Widgets;

use App\Models\Todo;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TodoOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Todo::query()
                    ->where('is_completed', false)
                    ->orderBy('sort')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
            )
            ->columns([
                Split::make([
                    TextColumn::make('title')
                        ->label('Task')
                        ->searchable()
                        ->sortable()
                        ->weight('medium')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->iconColor('primary')
                        ->description(function ($record) {
                            $desc = Str::of(strip_tags($record->description ?? ''))->squish()->limit(80);
                            $assigned = $record->assignees->pluck('name')->filter()->join(', ');
                            $meta = collect([
                                $assigned ? "Assigned: {$assigned}" : 'Unassigned',
                                $record->createdBy?->name ? "By: {$record->createdBy->name}" : null,
                            ])->filter()->join(' • ');

                            return trim($desc . ($meta ? "\n{$meta}" : ''));
                        }),
                    TextColumn::make('assignee_names')
                        ->label('Assigned To')
                        ->badge()
                        ->color('primary')
                        ->visibleFrom('md'),
                    TextColumn::make('created_at')
                        ->label('Created')
                        ->dateTime('M j, Y')
                        ->sortable()
                        ->since()
                        ->toggleable()
                        ->visibleFrom('md'),
                ])->from('md'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Todo $record): string => route('filament.admin.resources.todos.view', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->heading('Recent & Pending Todos')
            ->description('Quick view of active todo items')
            ->headerActions([
                Tables\Actions\Action::make('create')
                    ->label('New Todo')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(route('filament.admin.resources.todos.create')),
            ])
            ->emptyStateHeading('No pending todos')
            ->emptyStateDescription('All todos are completed or no todos exist.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
