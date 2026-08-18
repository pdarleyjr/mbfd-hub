<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource;
use App\Models\Uniform;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPersonnelRequest extends ViewRecord
{
    protected static string $resource = PersonnelRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->transitionAction('acknowledge', 'Acknowledge', PersonnelRequestStatus::Acknowledged, 'primary', 'heroicon-o-hand-raised'),
            Action::make('request_information')
                ->label('Request Information')
                ->icon('heroicon-o-question-mark-circle')
                ->color('warning')
                ->visible(fn (): bool => app(PersonnelRequestWorkflowService::class)->canTransition($this->record, PersonnelRequestStatus::NeedsInformation))
                ->form([
                    CheckboxList::make('types')->label('Information requested')->options([
                        'police_report' => 'Police Report',
                        'police_case_number' => 'Police Case Number',
                        'damage_photo' => 'Photo of Damage',
                        'additional_explanation' => 'Additional Explanation',
                        'other' => 'Other Information',
                    ])->required()->columns(2),
                    Textarea::make('message')->label('Instructions visible to employee')->required()->maxLength(2000),
                    Textarea::make('internal_note')->label('Internal note')->maxLength(2000),
                ])->action(function (array $data): void {
                    app(PersonnelRequestWorkflowService::class)->requestInformation($this->record, auth()->user(), $data['types'], $data['message'], $data['internal_note'] ?? null);
                    $this->refreshFormData(['status', 'information_requested', 'employee_response', 'admin_status_detail']);
                }),
            $this->transitionAction('order', 'Mark Ordered', PersonnelRequestStatus::Ordered, 'primary', 'heroicon-o-shopping-cart'),
            $this->transitionAction('arrived', 'Mark Arrived', PersonnelRequestStatus::Arrived, 'info', 'heroicon-o-truck'),
            $this->transitionAction('ready', 'Ready for Pickup', PersonnelRequestStatus::ReadyForPickup, 'info', 'heroicon-o-bell-alert'),
            Action::make('issue_uniform')
                ->label('Issue Uniform')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('success')
                ->visible(fn (): bool => $this->record->type === PersonnelRequestType::Uniform && $this->record->items()->where('fulfillment_status', '!=', 'fulfilled')->exists())
                ->form([
                    Select::make('item_id')->label('Request item')->options(fn () => $this->record->items()->where('fulfillment_status', '!=', 'fulfilled')->pluck('item_name', 'id'))->required(),
                    Select::make('uniform_id')->label('Uniform inventory')->options(fn () => Uniform::query()->where('quantity_on_hand', '>', 0)->orderBy('item_name')->get()->mapWithKeys(fn (Uniform $uniform) => [$uniform->id => "{$uniform->item_name} — {$uniform->size} — {$uniform->quantity_on_hand} on hand"]))->searchable()->required(),
                    DatePicker::make('issued_at')->default(today())->required(),
                    DatePicker::make('expires_at')->label('Expiration date (optional)')->afterOrEqual('issued_at'),
                    Textarea::make('notes')->maxLength(2000),
                ])->action(function (array $data): void {
                    $item = $this->record->items()->findOrFail($data['item_id']);
                    app(PersonnelRequestFulfillmentService::class)->issueUniform($item, Uniform::findOrFail($data['uniform_id']), auth()->user(), $data['issued_at'], $data['expires_at'] ?? null, $data['notes'] ?? null);
                    Notification::make()->title('Uniform issued and inventory updated')->success()->send();
                }),
            Action::make('assign_equipment')
                ->label('Assign PPE')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->visible(fn (): bool => $this->record->type === PersonnelRequestType::Equipment && $this->record->items()->where('fulfillment_status', '!=', 'fulfilled')->exists())
                ->form([
                    Select::make('item_id')->label('Request item')->options(fn () => $this->record->items()->where('fulfillment_status', '!=', 'fulfilled')->pluck('item_name', 'id'))->required(),
                    DatePicker::make('issued_at')->default(today())->required(),
                    DatePicker::make('expires_at')->label('Expiration date (optional)')->afterOrEqual('issued_at'),
                    Textarea::make('notes')->maxLength(2000),
                ])->action(function (array $data): void {
                    $item = $this->record->items()->findOrFail($data['item_id']);
                    app(PersonnelRequestFulfillmentService::class)->issueEquipment($item, auth()->user(), $data['issued_at'], $data['expires_at'] ?? null, $data['notes'] ?? null);
                    Notification::make()->title('PPE assigned to employee record')->success()->send();
                }),
            $this->transitionAction('complete', 'Complete', PersonnelRequestStatus::Completed, 'success', 'heroicon-o-check-badge'),
            $this->transitionAction('deny', 'Deny', PersonnelRequestStatus::Denied, 'danger', 'heroicon-o-x-circle'),
            Action::make('add_note')->label('Add Note')->icon('heroicon-o-chat-bubble-left-right')->form([
                Textarea::make('employee_note')->label('Employee-visible note')->maxLength(2000),
                Textarea::make('internal_note')->label('Admin-only internal note')->maxLength(2000),
            ])->action(fn (array $data) => app(PersonnelRequestWorkflowService::class)->addNote($this->record, auth()->user(), $data['employee_note'] ?? null, $data['internal_note'] ?? null)),
        ];
    }

    private function transitionAction(string $name, string $label, PersonnelRequestStatus $status, string $color, string $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (): bool => app(PersonnelRequestWorkflowService::class)->canTransition($this->record, $status))
            ->form([
                Textarea::make('employee_note')->label('Employee-visible note')->maxLength(2000),
                Textarea::make('internal_note')->label('Admin-only internal note')->maxLength(2000),
            ])->requiresConfirmation()
            ->action(function (array $data) use ($status): void {
                app(PersonnelRequestWorkflowService::class)->transition($this->record, $status, auth()->user(), $data['employee_note'] ?? null, $data['internal_note'] ?? null);
                $this->refreshFormData(['status', 'employee_response', 'admin_status_detail']);
            });
    }
}
