<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class PersonnelUniformsEquipment extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Inventory & Logistics';

    protected static ?string $navigationLabel = 'Personnel Uniforms / Equipment';

    protected static ?string $title = 'Personnel Uniforms / Equipment';

    protected static ?string $slug = 'personnel-uniforms-equipment';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->can('admin.personnel.view') ?? false)
            || ($user?->can('admin.equipment.view') ?? false);
    }
}
