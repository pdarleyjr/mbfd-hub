<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('accountExceptions')->label(fn (): string => 'Account exceptions ('.\App\Models\User::query()->whereNull('employee_profile_id')->count().')')
                ->visible(fn (): bool => \App\Filament\Resources\AccountProfileResource::canViewAny())
                ->url(\App\Filament\Resources\AccountProfileResource::getUrl()),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => \Filament\Resources\Components\Tab::make('All employees'),
            'awaiting' => \Filament\Resources\Components\Tab::make('Awaiting account')->modifyQueryUsing(fn ($query) => $query->whereDoesntHave('user')),
            'active' => \Filament\Resources\Components\Tab::make('Active logins')->modifyQueryUsing(fn ($query) => $query->whereHas('user', fn ($users) => $users->where('account_status', 'active'))),
            'disabled' => \Filament\Resources\Components\Tab::make('Disabled logins')->modifyQueryUsing(fn ($query) => $query->whereHas('user', fn ($users) => $users->where('account_status', 'disabled'))),
        ];
    }
}
