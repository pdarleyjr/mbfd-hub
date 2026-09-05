<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TrainingTodoAdminResource\Pages;

final class TrainingTodoAdminResource extends \App\Filament\Training\Resources\TrainingTodoResource
{
    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Training Management';

    protected static ?string $slug = 'training-management';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrainingTodos::route('/'),
            'create' => Pages\CreateTrainingTodo::route('/create'),
            'view' => Pages\ViewTrainingTodo::route('/{record}'),
            'edit' => Pages\EditTrainingTodo::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.training.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('admin.training.manage') ?? false;
    }
}
