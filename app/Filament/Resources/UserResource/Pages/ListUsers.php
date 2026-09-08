<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\Page;

class ListUsers extends Page
{
    protected static string $resource = UserResource::class;

    protected static string $view = 'filament.employees.legacy-redirect';

    public function mount(): void
    {
        abort_unless(UserResource::canViewAny(), 403);
        $this->redirect(EmployeeResource::getUrl());
    }
}
