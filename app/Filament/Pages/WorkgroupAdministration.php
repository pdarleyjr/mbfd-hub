<?php

declare(strict_types=1);

namespace App\Filament\Pages;

final class WorkgroupAdministration extends \App\Filament\Workgroup\Pages\AdminDashboard
{
    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?string $navigationLabel = 'Workgroup Administration';

    protected static ?string $title = 'Workgroup Administration';

    protected static ?string $slug = 'workgroup-administration';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.workgroups.view') ?? false;
    }
}
