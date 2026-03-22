<?php

namespace App\Filament\Resources\FireEquipmentRequestResource\Pages;

use App\Filament\Resources\FireEquipmentRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFireEquipmentRequest extends ViewRecord
{
    protected static string $resource = FireEquipmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('shift_chief_approve')
                ->label('Shift Chief Approve')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Shift Chief Approval')
                ->modalDescription('Confirm that the Shift Chief has reviewed and approved this equipment request.')
                ->visible(fn () => $this->record->status === 'pending')
                ->action(function () {
                    $this->record->update([
                        'status' => 'shift_chief_approved',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]);
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('support_services_approve')
                ->label('Support Services Approve')
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Support Services Approval')
                ->modalDescription('Confirm that Support Services has reviewed and approved this equipment request.')
                ->visible(fn () => $this->record->status === 'shift_chief_approved')
                ->action(function () {
                    $this->record->update([
                        'status' => 'support_services_approved',
                    ]);
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('mark_completed')
                ->label('Mark Completed')
                ->icon('heroicon-o-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Complete Request')
                ->modalDescription('Mark this equipment request as fulfilled and completed.')
                ->visible(fn () => $this->record->status === 'support_services_approved')
                ->action(function () {
                    $this->record->update([
                        'status' => 'completed',
                    ]);
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('deny')
                ->label('Deny')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Deny Request')
                ->modalDescription('Are you sure you want to deny this equipment request?')
                ->visible(fn () => in_array($this->record->status, ['pending', 'shift_chief_approved']))
                ->action(function () {
                    $this->record->update([
                        'status' => 'denied',
                    ]);
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
