<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup\Concerns;

use App\Models\User;
use App\Support\Workgroups\WorkgroupAccess;

trait ResolvesWorkgroupAccess
{
    public static function getNavigationGroup(): ?string
    {
        return filament()->getCurrentPanel()?->getId() === 'admin'
            ? 'Programs'
            : parent::getNavigationGroup();
    }

    private static function workgroupAccess(): WorkgroupAccess
    {
        return app(WorkgroupAccess::class);
    }

    private static function currentWorkgroupUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
