<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\ApparatusDefectServiceTicketService;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Support\Fleet\DefectActionStatus;
use Closure;
use Filament\Actions\MountableAction as Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Filament actions that answer an equipment issue through DefectCorrectiveActionService.
 * Every builder takes a resolver `fn (Action): ?ApparatusDefect` so the same action works
 * on the inspection page (defect id passed as a mount argument) and on table rows.
 *
 * Each action fills a fresh idempotency key when its modal mounts, so a double submit of
 * one modal is one event. All rules live in the service; this class only collects input,
 * and turns validation and permission failures into plain-language notifications.
 */
final class DefectCorrectiveActions
{
    public const ACKNOWLEDGE = 'acknowledgeIssue';

    public const UPDATE = 'updateIssue';

    public const RESOLVE = 'resolveIssue';

    public const DISMISS = 'dismissIssue';

    public const REOPEN = 'reopenIssue';

    public const VERIFY = 'verifyCorrection';

    public const CLASSIFY = 'classifyImpact';

    public const TICKET = 'linkServiceTicket';

    /** @var array<string, string> */
    public const RESOLUTION_LABELS = [
        'replacement_installed' => 'Replacement installed',
        'spare_issued' => 'Spare issued',
        'repair_completed' => 'Repair completed',
        'item_located' => 'Item located',
        'report_corrected' => 'Report was corrected',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const IMPACT_LABELS = [
        'non_blocking' => 'Informational: the apparatus can remain in service',
        'needs_repair' => 'Needs repair',
        'needs_admin_review' => 'Further review needed',
        'out_of_service' => 'Place the apparatus Out of Service',
    ];

    /**
     * The actions that make sense for an issue in its current state, in display order.
     *
     * @return list<string>
     */
    public static function applicable(ApparatusDefect $defect, bool $appearsCorrected): array
    {
        $status = (string) ($defect->action_status ?? DefectActionStatus::REPORTED);
        if (DefectActionStatus::isClosed($status)) {
            return [self::REOPEN];
        }

        return array_values(array_filter([
            $status === DefectActionStatus::REPORTED ? self::ACKNOWLEDGE : null,
            self::UPDATE,
            $appearsCorrected ? self::VERIFY : null,
            self::RESOLVE,
            self::CLASSIFY,
            $defect->service_ticket_id === null ? self::TICKET : null,
            self::DISMISS,
        ]));
    }

    public static function canManage(?User $user): bool
    {
        return $user instanceof User && Gate::forUser($user)->allows('manageCorrectiveAction', ApparatusDefect::class);
    }

    // --------------------------------------------------------------- builders

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function acknowledge(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Acknowledge', 'heroicon-o-hand-thumb-up', 'primary')
            ->modalHeading('Acknowledge this issue')
            ->modalDescription('Tell the member Logistics has seen it. This does not resolve the issue.')
            ->modalSubmitActionLabel('Acknowledge')
            ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['idempotency_key' => self::newKey()]))
            ->form(self::messageFields())
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Issue acknowledged',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->acknowledge($id, $user, $payload, $key)));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function update(Action $action, Closure $defectOf): Action
    {
        $replacement = static fn (string $item): string => sprintf(
            'A replacement %s is available in Logistics. Please contact Support Services or arrange pickup.',
            $item,
        );

        return self::base($action, $defectOf, 'Update action', 'heroicon-o-arrow-path', 'gray')
            ->modalHeading('Update the corrective action')
            ->modalDescription('Record what Logistics is doing about this issue. The member sees the status and any message you add.')
            ->modalSubmitActionLabel('Save update')
            ->modalWidth('2xl')
            ->mountUsing(function (Forms\ComponentContainer $form, Action $action) use ($defectOf, $replacement): void {
                $defect = $defectOf($action);
                abort_unless($defect instanceof ApparatusDefect, 404);
                $status = in_array($defect->action_status, DefectCorrectiveActionService::ACTION_STATUSES, true)
                    ? (string) $defect->action_status
                    : DefectActionStatus::ACTION_PENDING;
                $details = is_array($defect->action_details) ? $defect->action_details : [];
                $form->fill([
                    'idempotency_key' => self::newKey(),
                    'item_name' => (string) $defect->item,
                    'action_status' => $status,
                    'details' => [
                        'parts_ordered' => is_scalar($details['parts_ordered'] ?? null) ? (string) $details['parts_ordered'] : null,
                        'vendor_reference' => $details['vendor_reference'] ?? null,
                        'expected_delivery' => $details['expected_delivery'] ?? null,
                        'repair_appointment' => $details['repair_appointment'] ?? null,
                        'assigned_to' => is_string($details['assigned_to'] ?? null) ? $details['assigned_to'] : null,
                        'assigned_department' => $details['assigned_department'] ?? null,
                        'pickup_instructions' => $details['pickup_instructions'] ?? null,
                    ],
                    'member_message' => $status === DefectActionStatus::REPLACEMENT_AVAILABLE ? $replacement((string) $defect->item) : null,
                ]);
            })
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\Hidden::make('item_name'),
                Forms\Components\Select::make('action_status')
                    ->label('Status')
                    ->options(array_combine(
                        DefectCorrectiveActionService::ACTION_STATUSES,
                        array_map(fn (string $status): string => DefectActionStatus::label($status), DefectCorrectiveActionService::ACTION_STATUSES),
                    ))
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($replacement): void {
                        if ($state === DefectActionStatus::REPLACEMENT_AVAILABLE && blank($get('member_message'))) {
                            $set('member_message', $replacement((string) $get('item_name')));
                        }
                    }),
                Forms\Components\Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Forms\Components\TextInput::make('details.parts_ordered')->label('Parts ordered')->maxLength(255),
                    Forms\Components\TextInput::make('details.vendor_reference')->label('Vendor or PO reference')->maxLength(255),
                    Forms\Components\DatePicker::make('details.expected_delivery')->label('Expected delivery'),
                    Forms\Components\DateTimePicker::make('details.repair_appointment')->label('Repair appointment'),
                    Forms\Components\TextInput::make('details.assigned_to')->label('Assigned to')->maxLength(255),
                    Forms\Components\TextInput::make('details.assigned_department')->label('Assigned department')->maxLength(255),
                ]),
                Forms\Components\Textarea::make('details.pickup_instructions')->label('Pickup instructions')->rows(2)->maxLength(2000),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(function (array $data, Action $action) use ($defectOf): void {
                $defect = $defectOf($action);
                abort_unless($defect instanceof ApparatusDefect, 404);
                $payload = [
                    'action_status' => $data['action_status'] ?? null,
                    'details' => self::detailsToSend((array) ($data['details'] ?? []), is_array($defect->action_details) ? $defect->action_details : []),
                    'member_message' => $data['member_message'] ?? null,
                    'internal_note' => $data['internal_note'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ];
                self::run($action, $defectOf, $payload, 'Corrective action updated',
                    fn (int $id, User $user, array $body, ?string $key) => app(DefectCorrectiveActionService::class)->updateAction($id, $user, $body, $key));
            });
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function resolve(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Resolve', 'heroicon-o-check-circle', 'success')
            ->modalHeading('Resolve this issue')
            ->modalDescription('Resolving closes the issue and tells everyone who reported or confirmed it. It does not change the unit\'s operational status.')
            ->modalSubmitActionLabel('Resolve issue')
            ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['idempotency_key' => self::newKey()]))
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\Select::make('resolution_type')
                    ->label('How was it resolved?')
                    ->options(self::RESOLUTION_LABELS)
                    ->required()
                    ->native(false),
                Forms\Components\Textarea::make('resolution_notes')
                    ->label('Resolution notes')
                    ->helperText('Required. This stays in the issue history.')
                    ->required()
                    ->rows(3)
                    ->maxLength(5000),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Issue resolved',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->resolve($id, $user, $payload, $key)));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function dismiss(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Dismiss', 'heroicon-o-x-circle', 'gray')
            ->modalHeading('Close with no further action')
            ->modalDescription('Use this when the report was a mistake or nothing needs to be done. The history is kept.')
            ->modalSubmitActionLabel('Close issue')
            ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['idempotency_key' => self::newKey()]))
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\Textarea::make('reason')->label('Reason')->required()->rows(3)->maxLength(5000),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Issue closed',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->dismiss($id, $user, $payload, $key)));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function reopen(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Reopen', 'heroicon-o-arrow-uturn-left', 'warning')
            ->modalHeading('Reopen this issue')
            ->modalDescription('The same record becomes active again and keeps its full history.')
            ->modalSubmitActionLabel('Reopen issue')
            ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['idempotency_key' => self::newKey()]))
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\Textarea::make('reason')->label('Reason for reopening')->required()->rows(3)->maxLength(5000),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Issue reopened',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->reopen($id, $user, $payload, $key)));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function verify(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Verify correction', 'heroicon-o-shield-check', 'success')
            ->modalHeading('Verify the correction')
            ->modalDescription('The latest inspection reported this item as present. Confirm it, and the issue is resolved.')
            ->modalSubmitActionLabel('Verify and resolve')
            ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['idempotency_key' => self::newKey(), 'resolution_type' => 'item_located']))
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\Select::make('resolution_type')->label('How was it corrected?')->options(self::RESOLUTION_LABELS)->native(false),
                Forms\Components\Textarea::make('notes')->label('Notes')->rows(2)->maxLength(5000),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Correction verified',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->verifyCorrection($id, $user, $payload, $key)));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function classify(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Classify impact', 'heroicon-o-exclamation-triangle', 'gray')
            ->modalHeading('Classify the operational impact')
            ->modalSubmitActionLabel('Save classification')
            ->mountUsing(function (Forms\ComponentContainer $form, Action $action) use ($defectOf): void {
                $defect = $defectOf($action);
                abort_unless($defect instanceof ApparatusDefect, 404);
                $current = (string) $defect->operational_impact;
                $form->fill([
                    'idempotency_key' => self::newKey(),
                    'operational_impact' => array_key_exists($current, self::IMPACT_LABELS) ? $current : null,
                ]);
            })
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\Select::make('operational_impact')->label('Operational impact')->options(self::IMPACT_LABELS)->required()->native(false)->live(),
                Forms\Components\Placeholder::make('oos_warning')
                    ->label('Operational hold')
                    ->content('This places the physical apparatus Out of Service. Returning it to service follows the existing fleet workflow.')
                    ->visible(fn (Get $get): bool => $get('operational_impact') === 'out_of_service'),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Operational impact saved',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->classifyImpact($id, $user, $payload, $key)));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    public static function ticket(Action $action, Closure $defectOf): Action
    {
        return self::base($action, $defectOf, 'Create service ticket', 'heroicon-o-wrench-screwdriver', 'gray')
            ->modalHeading('Create a service ticket for this issue')
            ->modalDescription('A ticket does not resolve the issue. Completing the ticket leaves the issue open until you resolve it.')
            ->modalSubmitActionLabel('Create ticket')
            ->modalWidth('2xl')
            ->mountUsing(function (Forms\ComponentContainer $form, Action $action) use ($defectOf): void {
                $defect = $defectOf($action);
                abort_unless($defect instanceof ApparatusDefect, 404);
                $form->fill([...app(ApparatusDefectServiceTicketService::class)->defaults($defect), 'idempotency_key' => self::newKey()]);
            })
            ->form([
                Forms\Components\Hidden::make('idempotency_key'),
                Forms\Components\TextInput::make('title')->label('Ticket title')->required()->maxLength(255),
                Forms\Components\Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Forms\Components\Select::make('category')->options(ApparatusServiceTicketCategory::options())->required()->native(false),
                    Forms\Components\Select::make('priority')->options(ApparatusServiceTicketPriority::options())->required()->native(false),
                ]),
                Forms\Components\Textarea::make('description')->required()->rows(5)->maxLength(10000),
                ...array_slice(self::messageFields(), 1),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $defectOf, $data, 'Service ticket created',
                fn (int $id, User $user, array $payload, ?string $key) => app(DefectCorrectiveActionService::class)->linkTicket($id, $user, $payload, $key)));
    }

    // ---------------------------------------------------------------- internals

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @return TAction
     */
    private static function base(Action $action, Closure $defectOf, string $label, string $icon, string $color): Action
    {
        return $action
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Action $action): bool => self::canManage(auth()->user()) && $defectOf($action) instanceof ApparatusDefect);
    }

    /** @return list<Forms\Components\Component> first entry is the idempotency key */
    private static function messageFields(): array
    {
        return [
            Forms\Components\Hidden::make('idempotency_key'),
            Forms\Components\Textarea::make('member_message')
                ->label('Message to the member')
                ->helperText('Optional. The member sees this on their inspection and in their notifications.')
                ->rows(3)
                ->maxLength(2000),
            Forms\Components\Textarea::make('internal_note')
                ->label('Internal note')
                ->helperText('Optional. Staff only. The member never sees this.')
                ->rows(2)
                ->maxLength(4000),
        ];
    }

    private static function newKey(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Only fields that carry a value, or that clear a stored one, go to the service, so an
     * untouched blank field can never erase an existing detail (including a user id in assigned_to).
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private static function detailsToSend(array $submitted, array $existing): array
    {
        $send = [];
        foreach (DefectCorrectiveActionService::DETAIL_KEYS as $key) {
            $value = $submitted[$key] ?? null;
            $blank = $value === null || (is_string($value) && trim($value) === '');
            if (! $blank) {
                $send[$key] = $value;
            } elseif (array_key_exists($key, $existing) && ! ($key === 'assigned_to' && is_int($existing[$key]))) {
                $send[$key] = null;
            }
        }

        return $send;
    }

    /**
     * @param  Closure(Action): ?ApparatusDefect  $defectOf
     * @param  array<string, mixed>  $data
     * @param  Closure(int, User, array<string, mixed>, ?string): mixed  $call
     */
    private static function run(Action $action, Closure $defectOf, array $data, string $success, Closure $call): void
    {
        $defect = $defectOf($action);
        abort_unless($defect instanceof ApparatusDefect, 404);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $key = filled($data['idempotency_key'] ?? null) ? (string) $data['idempotency_key'] : null;
        unset($data['idempotency_key'], $data['item_name']);

        try {
            $call((int) $defect->getKey(), $user, $data, $key);
        } catch (ValidationException $exception) {
            Notification::make()->danger()
                ->title('That could not be saved')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))
                ->send();
            $action->halt();

            return;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('You do not have permission to do this.')->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
