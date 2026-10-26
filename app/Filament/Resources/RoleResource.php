<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\RoleResource\Pages;
use BezhanSalleh\FilamentShield\Resources\RoleResource as ShieldRoleResource;
use Filament\Tables\Table;

/** Published Shield extension: preserve native role editing and add the shared exports. */
class RoleResource extends ShieldRoleResource
{
    use HasExportActions;

    public static function table(Table $table): Table
    {
        $table = parent::table($table);
        $table->getColumn('name')?->sortable();

        return static::withExportActions($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
