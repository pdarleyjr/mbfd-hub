<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources;

use App\Filament\Clusters\PersonnelUniformsEquipment;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource\Pages;

class PersonnelUniformResource extends \App\Filament\Resources\UniformResource
{
    protected static ?string $cluster = PersonnelUniformsEquipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Uniform Inventory';

    protected static ?string $slug = 'uniform-inventory';

    protected static ?int $navigationSort = -60;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPersonnelUniforms::route('/'),
            'create' => Pages\CreatePersonnelUniform::route('/create'),
            'edit' => Pages\EditPersonnelUniform::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.personnel.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('admin.personnel.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
