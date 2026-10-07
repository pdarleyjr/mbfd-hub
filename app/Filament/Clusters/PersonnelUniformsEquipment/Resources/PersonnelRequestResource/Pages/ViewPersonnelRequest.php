<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource;
use App\Filament\Concerns\ArchivesRequests;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\Uniform;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ViewPersonnelRequest extends ViewRecord
{
    use ArchivesRequests;

    protected static string $resource = PersonnelRequestResource::class;

    private ?array $inventoryChoices = null;

    protected function getHeaderActions(): array
    {
        /** @var PersonnelRequest $request */
        $request = $this->record;

        return [
            ...$this->getArchiveActions(),
            $this->transitionAction('acknowledge', 'Acknowledge', PersonnelRequestStatus::Acknowledged, 'primary', 'heroicon-o-hand-raised'),
            Action::make('request_information')
                ->authorize('update', $request)
                ->label('Request Information')
                ->icon('heroicon-o-question-mark-circle')
                ->color('warning')
                ->visible(fn (): bool => app(PersonnelRequestWorkflowService::class)->canTransition($request, PersonnelRequestStatus::NeedsInformation))
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
                ])->action(function (array $data) use ($request): void {
                    app(PersonnelRequestWorkflowService::class)->requestInformation($request, auth()->user(), $data['types'], $data['message'], $data['internal_note'] ?? null);
                    $this->record->refresh()->load('items', 'updates');
                    $this->refreshFormData(['status', 'information_requested', 'employee_response', 'admin_status_detail']);
                }),
            $this->transitionAction('order', 'Mark Ordered', PersonnelRequestStatus::Ordered, 'primary', 'heroicon-o-shopping-cart'),
            $this->transitionAction('arrived', 'Mark Arrived', PersonnelRequestStatus::Arrived, 'info', 'heroicon-o-truck'),
            $this->transitionAction('ready', 'Ready for Pickup', PersonnelRequestStatus::ReadyForPickup, 'info', 'heroicon-o-bell-alert'),
            ActionGroup::make([
                Action::make('acknowledge_item')->label('Acknowledge Item')->icon('heroicon-o-hand-raised')
                    ->authorize('update', $request)->visible(fn (): bool => $this->canActOnItems($request) || $this->hasActionReceipt($request, 'acknowledge_item'))
                    ->form([
                        $this->actionKey(),
                        Select::make('item_id')->label('Request item')->options(fn (): array => $this->itemOptions($request))->required()->rules([Rule::in($request->items()->pluck('id')->all())]),
                        Textarea::make('message')->label('Message visible to member (optional)')->maxLength(2000),
                    ])->action(function (array $data) use ($request): void {
                        $item = $request->items()->findOrFail($data['item_id']);
                        $this->performItemAction(fn () => app(PersonnelRequestWorkflowService::class)->acknowledgeItem($item, auth()->user(), $data['message'] ?? null, $data['idempotency_key']), 'Item acknowledged');
                    }),
                Action::make('arrive_item')->label('Record Item Arrival')->icon('heroicon-o-truck')
                    ->authorize('update', $request)->visible(fn (): bool => $this->hasActionReceipt($request, 'arrive_item') || ($this->canActOnItems($request) && $request->items()->whereColumn('arrived_quantity', '<', 'quantity')->exists()))
                    ->form([
                        $this->actionKey(),
                        Select::make('item_id')->label('Request item')->options(fn (): array => $this->itemOptions($request, 'arrived_quantity'))->required()->rules([Rule::in($request->items()->pluck('id')->all())])->live()
                            ->afterStateUpdated(fn (Set $set) => $set('quantity', 1)),
                        TextInput::make('quantity')->label('Quantity arrived now')->numeric()->integer()->minValue(1)->default(1)->required()
                            ->maxValue(fn (Get $get): int => $this->actionQuantityLimit($request, $get('item_id'), 'arrived_quantity')),
                        Textarea::make('employee_note')->label('Message visible to member (optional)')->maxLength(2000),
                        Textarea::make('internal_note')->label('Admin-only internal note')->maxLength(2000),
                    ])->action(function (array $data) use ($request): void {
                        $item = $request->items()->findOrFail($data['item_id']);
                        $this->performItemAction(fn () => app(PersonnelRequestWorkflowService::class)->arriveItem($item, auth()->user(), (int) $data['quantity'], $data['idempotency_key'], $data['employee_note'] ?? null, $data['internal_note'] ?? null), 'Item arrival recorded');
                    }),
                $this->batchIssueAction($request),
            ])->label('Item Actions')->icon('heroicon-o-queue-list')->button(),
            Action::make('issue_uniform')
                ->authorize('update', $request)
                ->label('Issue Uniform')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('success')
                ->visible(fn (): bool => $request->type === PersonnelRequestType::Uniform && ($this->hasActionReceipt($request, 'issue_uniform') || ($this->canActOnItems($request) && $request->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->exists())))
                ->form([
                    $this->actionKey(),
                    $this->issueItemSelect($request),
                    $this->inventorySelect()->required(),
                    $this->issueQuantity($request),
                    DatePicker::make('issued_at')->default(today())->required(),
                    DatePicker::make('expires_at')->label('Expiration date (optional)')->afterOrEqual('issued_at'),
                    Textarea::make('notes')->maxLength(2000),
                ])->action(function (array $data) use ($request): void {
                    $item = $request->items()->findOrFail($data['item_id']);
                    $this->performItemAction(fn () => app(PersonnelRequestFulfillmentService::class)->issueUniform($item, Uniform::findOrFail($data['uniform_id']), auth()->user(), $data['issued_at'], $data['expires_at'] ?? null, $data['notes'] ?? null, (int) $data['quantity'], $data['idempotency_key']), 'Uniform issued and inventory updated');
                }),
            Action::make('assign_equipment')
                ->authorize('update', $request)
                ->label('Assign PPE')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->visible(fn (): bool => $request->type === PersonnelRequestType::Equipment && ($this->hasActionReceipt($request, 'assign_equipment') || ($this->canActOnItems($request) && $request->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->exists())))
                ->form([
                    $this->actionKey(),
                    $this->issueItemSelect($request),
                    $this->issueQuantity($request),
                    DatePicker::make('issued_at')->default(today())->required(),
                    DatePicker::make('expires_at')->label('Expiration date (optional)')->afterOrEqual('issued_at'),
                    Textarea::make('notes')->maxLength(2000),
                ])->action(function (array $data) use ($request): void {
                    $item = $request->items()->findOrFail($data['item_id']);
                    $this->performItemAction(fn () => app(PersonnelRequestFulfillmentService::class)->issueEquipment($item, auth()->user(), $data['issued_at'], $data['expires_at'] ?? null, $data['notes'] ?? null, (int) $data['quantity'], $data['idempotency_key']), 'PPE assigned to employee record');
                }),
            $this->transitionAction('complete', 'Complete', PersonnelRequestStatus::Completed, 'success', 'heroicon-o-check-badge'),
            $this->transitionAction('deny', 'Deny', PersonnelRequestStatus::Denied, 'danger', 'heroicon-o-x-circle'),
            $this->transitionAction('cancel', 'Cancel', PersonnelRequestStatus::Cancelled, 'danger', 'heroicon-o-x-mark'),
            Action::make('add_note')->label('Add Note')->icon('heroicon-o-chat-bubble-left-right')->authorize('update', $request)->form([
                $this->actionKey(),
                Select::make('item_id')->label('Applies to')->placeholder('Whole request')->options(fn (): array => $this->itemOptions($request))->rules([Rule::in($request->items()->pluck('id')->all())]),
                Textarea::make('employee_note')->label('Employee-visible note')->maxLength(2000),
                Textarea::make('internal_note')->label('Admin-only internal note')->maxLength(2000),
            ])->action(function (array $data) use ($request): void {
                $item = filled($data['item_id'] ?? null) ? $request->items()->findOrFail($data['item_id']) : null;
                $this->performItemAction(fn () => app(PersonnelRequestWorkflowService::class)->addNote($request, auth()->user(), $data['employee_note'] ?? null, $data['internal_note'] ?? null, $item, $data['idempotency_key']), 'Note added');
            }),
        ];
    }

    private function actionKey(): Hidden
    {
        return Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required()->rules(['uuid']);
    }

    private function canActOnItems(PersonnelRequest $request): bool
    {
        return ! $request->isArchived() && ! $request->status->isTerminal();
    }

    private function itemOptions(PersonnelRequest $request, ?string $progressColumn = null): array
    {
        return $request->items()->when($progressColumn, fn ($query) => $query->whereColumn($progressColumn, '<', 'quantity'))
            ->orderBy('id')->get()->mapWithKeys(fn (PersonnelRequestItem $item): array => [
                $item->id => $item->item_name.(filled($item->size) ? ' — '.$item->size : '')
                    .($progressColumn ? ' — '.($item->quantity - $item->{$progressColumn}).' remaining' : ''),
            ])->all();
    }

    private function remaining(PersonnelRequest $request, mixed $itemId, string $progressColumn = 'fulfilled_quantity'): int
    {
        if ((! is_int($itemId) && ! is_string($itemId)) || filter_var($itemId, FILTER_VALIDATE_INT) === false) {
            return 0;
        }
        $item = $request->items()->find((int) $itemId);

        return $item ? max(0, $item->quantity - $item->{$progressColumn}) : 0;
    }

    private function hasActionReceipt(PersonnelRequest $request, string $actionName): bool
    {
        $index = array_key_last($this->mountedActions);
        if ($index === null || $this->mountedActions[$index] !== $actionName) {
            return false;
        }
        $key = $this->mountedActionsData[$index]['idempotency_key'] ?? null;
        if (! is_string($key)) {
            return false;
        }
        $event = match ($actionName) {
            'issue_uniform', 'assign_equipment' => 'item_fulfilled',
            'issue_batch' => 'items_fulfilled',
            'arrive_item' => 'item_arrived',
            'acknowledge_item' => 'item_acknowledged',
            default => null,
        };

        return $event !== null && $request->updates()->where('event', $event)->where('metadata->idempotency_key', $key)->exists();
    }

    private function actionQuantityLimit(PersonnelRequest $request, mixed $itemId, string $progressColumn = 'fulfilled_quantity'): int
    {
        $index = array_key_last($this->mountedActions);
        if ($index !== null && $this->hasActionReceipt($request, $this->mountedActions[$index])) {
            if ((! is_int($itemId) && ! is_string($itemId)) || filter_var($itemId, FILTER_VALIDATE_INT) === false) {
                return 0;
            }

            return (int) ($request->items()->find((int) $itemId)->quantity ?? 0);
        }

        return $this->remaining($request, $itemId, $progressColumn);
    }

    private function issueItemSelect(PersonnelRequest $request): Select
    {
        return Select::make('item_id')->label('Request item')->options(fn (): array => $this->itemOptions($request, 'fulfilled_quantity'))->required()->rules([Rule::in($request->items()->pluck('id')->all())])->live()
            ->afterStateUpdated(fn (Set $set, $state) => $set('quantity', $this->remaining($request, $state)));
    }

    private function issueQuantity(PersonnelRequest $request): TextInput
    {
        return TextInput::make('quantity')->label('Quantity to issue now')->numeric()->integer()->minValue(1)->default(1)->required()
            ->maxValue(fn (Get $get): int => $this->actionQuantityLimit($request, $get('item_id')));
    }

    private function inventorySelect(): Select
    {
        return Select::make('uniform_id')->label('Uniform inventory')->options(function (): array {
            return $this->inventoryChoices ??= Uniform::query()->where('quantity_on_hand', '>', 0)->orderBy('item_name')->get()
                ->mapWithKeys(fn (Uniform $uniform): array => [$uniform->id => "{$uniform->item_name} — {$uniform->size} — {$uniform->quantity_on_hand} on hand"])->all();
        })->searchable();
    }

    private function batchIssueAction(PersonnelRequest $request): Action
    {
        return Action::make('issue_batch')->label('Issue Selected Items')->icon('heroicon-o-archive-box-arrow-down')->color('success')
            ->authorize('update', $request)->visible(fn (): bool => $this->hasActionReceipt($request, 'issue_batch') || ($this->canActOnItems($request) && $request->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->exists()))
            ->modalWidth('3xl')->form([
                $this->actionKey(),
                Repeater::make('lines')->label('Items to issue')->helperText('Enter the quantity to issue now. Leave 0 for items staying on the order.')
                    ->addable(false)->deletable(false)->reorderable(false)->columns(2)
                    ->itemLabel(fn (array $state): string => $request->items->firstWhere('id', $state['item_id'] ?? null)->item_name ?? 'Request item')
                    ->default(fn (): array => $request->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->orderBy('id')->get()
                        ->map(fn (PersonnelRequestItem $item): array => ['item_id' => $item->id, 'quantity' => 0])->all())
                    ->schema([
                        Hidden::make('item_id')->required()->rules(['integer', Rule::in($request->items()->pluck('id')->all())]),
                        TextInput::make('quantity')->label('Issue now')->numeric()->integer()->minValue(0)->required()->live()
                            ->maxValue(fn (Get $get): int => $this->actionQuantityLimit($request, $get('item_id')))
                            ->helperText(fn (Get $get): string => $this->remaining($request, $get('item_id')).' remaining to issue'),
                        $this->inventorySelect()->visible(fn (): bool => $request->type === PersonnelRequestType::Uniform)
                            ->required(fn (Get $get): bool => (int) $get('quantity') > 0 && $request->type === PersonnelRequestType::Uniform)
                            ->disabled(fn (Get $get): bool => (int) $get('quantity') === 0),
                    ]),
                DatePicker::make('issued_at')->default(today())->required(),
                DatePicker::make('expires_at')->label('Expiration date (optional)')->afterOrEqual('issued_at'),
                Textarea::make('notes')->maxLength(2000),
            ])->action(function (array $data) use ($request): void {
                $lines = [];
                foreach ($data['lines'] as $line) {
                    if ((int) $line['quantity'] === 0) {
                        continue;
                    }
                    $item = $request->items()->findOrFail($line['item_id']);
                    $lines[] = ['item_id' => $item->id, 'quantity' => (int) $line['quantity']]
                        + ($request->type === PersonnelRequestType::Uniform ? ['uniform_id' => (int) $line['uniform_id']] : []);
                }
                $this->performItemAction(fn () => app(PersonnelRequestFulfillmentService::class)->issueBatch($request, $lines, auth()->user(), $data['issued_at'], $data['idempotency_key'], $data['expires_at'] ?? null, $data['notes'] ?? null), 'Selected items issued');
            });
    }

    private function performItemAction(\Closure $operation, string $successMessage): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $prefix = $this->getMountedActionForm()->getStatePath();
            $batch = $this->getMountedAction()?->getName() === 'issue_batch';
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(function (array $messages, string $key) use ($prefix, $batch): array {
                $field = match ($key) {
                    'items', 'item' => $batch ? 'lines' : 'item_id',
                    'key' => 'idempotency_key',
                    'note' => 'employee_note',
                    default => $batch && in_array($key, ['quantity', 'uniform_id'], true) ? 'lines' : $key,
                };

                return [$prefix.'.'.$field => $messages];
            })->all());
        }
        $this->record->refresh()->load('items', 'updates');
        Notification::make()->title($successMessage)->success()->send();
    }

    private function transitionAction(string $name, string $label, PersonnelRequestStatus $status, string $color, string $icon): Action
    {
        /** @var PersonnelRequest $request */
        $request = $this->record;

        return Action::make($name)
            ->authorize('update', $request)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (): bool => app(PersonnelRequestWorkflowService::class)->canTransition($request, $status))
            ->form([
                Textarea::make('employee_note')->label('Employee-visible note')->maxLength(2000),
                Textarea::make('internal_note')->label('Admin-only internal note')->maxLength(2000),
            ])->requiresConfirmation()
            ->action(function (array $data) use ($request, $status): void {
                app(PersonnelRequestWorkflowService::class)->transition($request, $status, auth()->user(), $data['employee_note'] ?? null, $data['internal_note'] ?? null);
                $this->record->refresh()->load('items', 'updates');
                $this->refreshFormData(['status', 'employee_response', 'admin_status_detail']);
            });
    }
}
