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
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false;
    }
}
