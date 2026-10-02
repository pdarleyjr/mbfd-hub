<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Mbfd\PolicyLibrary\Support\LibraryAccess;

abstract class LibraryResource extends Resource
{
    public static function canViewAny(): bool
    {
        return LibraryAccess::canManage(auth('web')->user());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
