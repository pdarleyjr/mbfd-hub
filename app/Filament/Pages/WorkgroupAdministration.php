<?php

declare(strict_types=1);

namespace App\Filament\Pages;

final class WorkgroupAdministration extends \App\Filament\Workgroup\Pages\AdminDashboard
{
    protected static ?int $navigationSort = 40;

    protected static ?string $navigationGroup = 'Programs';

    protected static ?string $navigationLabel = 'Workgroups';

    protected static ?string $title = 'Workgroup Administration';

    protected static ?string $slug = 'workgroup-administration';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.workgroups.view') ?? false;
    }
}
