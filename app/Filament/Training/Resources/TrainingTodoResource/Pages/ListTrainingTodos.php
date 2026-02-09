<?php

namespace App\Filament\Training\Resources\TrainingTodoResource\Pages;

use App\Filament\Training\Resources\TrainingTodoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTrainingTodos extends ListRecords
{
    protected static string $resource = TrainingTodoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'assigned_to_me' => Tab::make('Assigned to me')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereJsonContains('assigned_to', (string) auth()->id())),
            'created_by_me' => Tab::make('Created by me')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('created_by', auth()->id())),
        ];
    }
}
