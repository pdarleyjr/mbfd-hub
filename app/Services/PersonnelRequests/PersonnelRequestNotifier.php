<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Models\PersonnelRequest;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

final class PersonnelRequestNotifier
{
    public function created(PersonnelRequest $request): void
    {
        $admins = User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', [
            'super_admin', 'admin', 'logistics_admin',
        ]))->get();

        foreach ($admins as $admin) {
            Notification::make()
                ->title('New '.$request->type->label())
                ->body("{$request->request_number} for {$request->beneficiary_name} is ready for review.")
                ->icon('heroicon-o-clipboard-document-check')
                ->actions([
                    Action::make('view')
                        ->url('/admin/personnel-uniforms-equipment/personnel-requests/'.$request->getRouteKey())
                        ->markAsRead(),
                ])
                ->sendToDatabase($admin);
        }

        if ($request->type->value === 'equipment' && $request->beneficiary) {
            Notification::make()
                ->title('Personnel equipment request submitted')
                ->body("{$request->requester_name} submitted {$request->request_number} on your behalf.")
                ->actions([
                    Action::make('view')->url('/employee/my-requests/'.$request->getRouteKey())->markAsRead(),
                ])
                ->sendToDatabase($request->beneficiary);
        }
    }

    public function statusChanged(PersonnelRequest $request): void
    {
        if (! $request->beneficiary) {
            return;
        }

        Notification::make()
            ->title("Request {$request->status->label()}")
            ->body("{$request->request_number} is now {$request->status->label()}.")
            ->actions([
                Action::make('view')->url('/employee/my-requests/'.$request->getRouteKey())->markAsRead(),
            ])
            ->sendToDatabase($request->beneficiary);
    }

    public function employeeResponded(PersonnelRequest $request, string $event = 'response'): void
    {
        $admins = User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'admin', 'logistics_admin']))->get();
        foreach ($admins as $admin) {
            Notification::make()
                ->title($event === 'document' ? 'Requested document uploaded' : 'Employee supplied requested information')
                ->body("{$request->request_number} for {$request->beneficiary_name} needs review.")
                ->actions([
                    Action::make('view')->url('/admin/personnel-uniforms-equipment/personnel-requests/'.$request->getRouteKey())->markAsRead(),
                ])->sendToDatabase($admin);
        }
    }
}
