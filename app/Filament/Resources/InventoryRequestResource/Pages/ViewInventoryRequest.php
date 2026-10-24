<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryRequestResource\Pages;

use App\Filament\Concerns\RunsEmsActions;
use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Support\EmsLabels;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\RequestMessageService;
use App\Services\Ems\RequestPresenter;
use App\Services\Ems\RequestWorkflowService;
use Filament\Actions\Action;
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

/**
 * One request, start to finish: who asked, what for, what was prepared and issued, what it did to
 * stock, the history and the messages. Every button calls the same service Captain 5 uses, as the
 * signed-in person, and the service checks EmsAccess again.
 */
class ViewInventoryRequest extends ViewRecord
{
    use RunsEmsActions;

    protected static string $resource = InventoryRequestResource::class;

    protected static string $view = 'filament.admin.resources.inventory-request.view';

    private const OTHER_REASON = '__other';

    public function getTitle(): string
    {
        return 'Request '.$this->getRecord()->number;
    }

    /** Runs on every Livewire request, so an open page loses its actions when access is withdrawn. */
    public function hydrate(): void
    {
        abort_unless(InventoryRequestResource::canViewAny(), 403);
    }

    // ------------------------------------------------------------------- view data

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(InventoryRequestResource::canViewAny(), 403);
        $request = $this->request();
        $detail = $this->detail($request);
        $issuances = $this->issuances($detail);

        return [
            'request' => $request,
            'detail' => $detail,
            'groups' => $this->groupedLines($detail['lines'], $issuances),
            'timeline' => array_reverse(array_map(fn (array $event): array => $event + [
                'sentence' => EmsLabels::eventSentence($event),
                'stock' => $event['action'] === 'issued' ? EmsLabels::stockEffect((array) $event['payload']) : null,
                'at' => EmsLabels::et($event['created_at']),
            ], $detail['history'])),
            'messages' => array_map(fn (array $m): array => $m + ['at' => EmsLabels::et($m['created_at'])], $detail['messages']),
            'canAct' => $this->emsCan('act'),
            'open' => $request->isOpen(),
            'statusLabel' => EmsLabels::status($request->status),
            'statusColor' => EmsLabels::STATUS_COLOR[$request->status] ?? 'gray',
            'submittedAt' => EmsLabels::et($request->submitted_at),
            'lastActivity' => EmsLabels::et($request->last_activity_at ?? $request->submitted_at),
        ];
    }

    /**
     * Each issuance as it stands now: quantity and stock effect after any later correction.
     *
     * @param  array<string, mixed>  $detail
     * @return list<array<string, mixed>>
     */
    private function issuances(array $detail): array
    {
        $events = collect($detail['history']);
        $corrections = $events->where('action', 'issue_corrected')
            ->groupBy(fn (array $e): int => (int) ($e['payload']['corrects_event_id'] ?? 0))
            ->map(fn ($group): array => $group->last());
        $itemIds = $events->map(fn (array $e): mixed => $e['payload']['supply_item_id'] ?? null)->filter()->unique()->all();
        $itemNames = SupplyItem::query()->whereIn('id', $itemIds)->pluck('name', 'id');

        return $events->where('action', 'issued')->map(function (array $event) use ($corrections, $itemNames): array {
            $payload = (array) $event['payload'];
            $correction = $corrections->get($event['id']);
            $cp = (array) ($correction['payload'] ?? []);
            $effectPayload = $correction === null ? $payload : [
                'stock_effect' => $cp['stock_effect'] ?? $payload['stock_effect'] ?? null,
                'supply_item_name' => $itemNames[$cp['supply_item_id'] ?? 0] ?? null,
                'stock_note' => $payload['stock_note'] ?? null,
            ];

            return [
                'event_id' => $event['id'],
                'line_id' => $event['request_line_id'],
                'label' => $payload['label'] ?? 'Item',
                'quantity' => $correction === null ? (int) ($payload['quantity'] ?? 0) : (int) ($cp['new_quantity'] ?? 0),
                'original' => (int) ($payload['quantity'] ?? 0),
                'corrected' => $correction !== null,
                'by' => $event['actor_name'],
                'at' => EmsLabels::et($event['created_at'], 'M j, g:i A'),
                'stock' => EmsLabels::stockEffect($effectPayload),
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $issuances
     * @return list<array{heading: string, lines: list<array<string, mixed>>}>
     */
    private function groupedLines(array $lines, array $issuances): array
    {
        $byLine = collect($issuances)->groupBy('line_id');
        $groups = [];
        foreach ($lines as $line) {
            $heading = (EmsLabels::BAG[$line['bag_key'] ?? ''] ?? 'Items').': '.($line['section_label'] ?: 'Other');
            $groups[$heading] ??= ['heading' => $heading, 'lines' => []];
            $wanted = $this->wanted($line);
            $groups[$heading]['lines'][] = $line + [
                'wanted' => $wanted,
                'issuances' => ($byLine->get($line['id']) ?? collect())->all(),
                'can_not_issue' => ! in_array($line['line_status'], ['issued', 'not_issued'], true) && $wanted > 0,
            ];
        }

        return array_values($groups);
    }

    // --------------------------------------------------------------------- actions

    protected function getHeaderActions(): array
    {
        return [
            $this->reviewAction(),
            $this->prepareAction(),
            $this->issueAction(),
            $this->correctAction(),
            $this->messageAction(),
            $this->closeAction(),
            $this->reopenAction(),
        ];
    }

    private function request(): InventoryRequest
    {
        return InventoryRequest::query()->findOrFail($this->getRecord()->getKey());
    }

    /** @return array<string, mixed> */
    private function detail(InventoryRequest $request): array
    {
        return app(RequestPresenter::class)->detail($request, RequestPresenter::AUDIENCE_STAFF, withUrls: false);
    }

    private function canWork(): bool
    {
        return $this->emsCan('act') && $this->request()->isOpen();
    }

    private function keyField(): Hidden
    {
        return Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid());
    }

    private function reviewAction(): Action
    {
        return Action::make('review')->label('Review')->icon('heroicon-o-eye')->color('gray')
            ->visible(fn (): bool => $this->emsCan('act') && $this->request()->status === InventoryRequest::SUBMITTED)
            ->action(function (Action $action): void {
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->review((int) $request->getKey(), $user, (string) Str::uuid()), 'Request reviewed');
            });
    }

    private function prepareAction(): Action
    {
        return Action::make('prepare')->label('Prepare quantities')->icon('heroicon-o-archive-box')->color('gray')
            ->visible(fn (): bool => $this->canWork())
            ->modalHeading('Prepare quantities')
            ->modalDescription('Set aside what you plan to hand out. Preparing does not take anything out of stock. Leave a line blank to clear it.')
            ->modalSubmitActionLabel('Save prepared quantities')
            ->modalWidth('4xl')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid(), 'lines' => $this->prepareRows()])
            ->form([
                $this->keyField(),
                Repeater::make('lines')->label('Items')->addable(false)->deletable(false)->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema([
                        Hidden::make('line_id'),
                        Hidden::make('label'),
                        TextInput::make('prepared_qty')->label('Prepared quantity')->numeric()->integer()->minValue(0)->maxValue(999),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $rows = collect($data['lines'] ?? [])->filter(fn ($row) => isset($row['line_id']))
                    ->map(fn (array $row): array => ['line_id' => (int) $row['line_id'], 'prepared_qty' => ($row['prepared_qty'] ?? '') === '' ? null : (int) $row['prepared_qty']])->values()->all();
                if ($rows === []) {
                    Notification::make()->warning()->title('Nothing to save')->send();
                    $action->halt();

                    return;
                }
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->prepare((int) $request->getKey(), $user, $this->emsKey($data), ['lines' => $rows]), 'Quantities prepared');
            });
    }

    private function issueAction(): Action
    {
        return Action::make('issue')->label('Issue')->icon('heroicon-o-arrow-up-tray')->color('success')
            ->visible(fn (): bool => $this->canWork())
            ->modalHeading('Issue supplies')
            ->modalDescription('Enter what is leaving the supply room now. Lines left at 0 are skipped. Stock is taken from the matching supply room item. If several items match, choose one, or leave it blank to record the issue only.')
            ->modalSubmitActionLabel('Issue')
            ->modalWidth('4xl')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid(), 'lines' => $this->issueRows()])
            ->form([
                $this->keyField(),
                Repeater::make('lines')->label('Items')->addable(false)->deletable(false)->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => $state['summary'] ?? null)
                    ->schema([
                        Hidden::make('line_id'), Hidden::make('summary'), Hidden::make('wanted'), Hidden::make('issued'), Hidden::make('candidates'),
                        TextInput::make('quantity')->label('Quantity to issue')->numeric()->integer()->minValue(0)->maxValue(999)->live(onBlur: true),
                        Select::make('supply_item_id')->label('Take stock from')->placeholder('Record only, do not change stock')
                            ->options(fn (Get $get): array => (array) ($get('candidates') ?? []))
                            ->visible(fn (Get $get): bool => count((array) ($get('candidates') ?? [])) > 1),
                        Textarea::make('reason')->label('Why issue more than was requested?')->rows(2)->maxLength(500)
                            ->visible(fn (Get $get): bool => (int) $get('issued') + (int) $get('quantity') > (int) $get('wanted')),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $issuances = collect($data['lines'] ?? [])
                    ->filter(fn ($row) => isset($row['line_id']) && (int) ($row['quantity'] ?? 0) > 0)
                    ->map(fn (array $row): array => array_filter([
                        'line_id' => (int) $row['line_id'], 'quantity' => (int) $row['quantity'],
                        'supply_item_id' => filled($row['supply_item_id'] ?? null) ? (int) $row['supply_item_id'] : null,
                        'reason' => filled($row['reason'] ?? null) ? trim((string) $row['reason']) : null,
                    ], fn ($value): bool => $value !== null))->values()->all();
                if ($issuances === []) {
                    Notification::make()->warning()->title('Enter a quantity to issue')->send();
                    $action->halt();

                    return;
                }
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->issue((int) $request->getKey(), $user, $this->emsKey($data), ['issuances' => $issuances]), 'Supplies issued');
            });
    }

    /** Per line "mark not issued"; rendered inside the lines list, not in the page header. */
    public function notIssuedAction(): Action
    {
        return Action::make('notIssued')->label('Mark not issued')->icon('heroicon-o-no-symbol')->color('danger')->size('sm')->outlined()
            ->visible(fn (): bool => $this->canWork())
            ->modalHeading(fn (array $arguments): string => 'Mark not issued: '.$this->lineLabel($arguments))
            ->modalDescription('This line stays on the request as not issued, with your reason. It can be issued later if plans change.')
            ->modalSubmitActionLabel('Mark not issued')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid()])
            ->form([
                $this->keyField(),
                Textarea::make('reason')->label('Reason')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->markNotIssued(
                    (int) $request->getKey(), $user, $this->emsKey($data), ['line_id' => (int) ($arguments['line'] ?? 0), 'reason' => (string) ($data['reason'] ?? '')],
                ), 'Marked not issued');
            });
    }

    private function closeAction(): Action
    {
        return Action::make('close')->label('Close')->icon('heroicon-o-check-circle')->color('gray')
            ->visible(fn (): bool => $this->canWork())
            ->requiresConfirmation()
            ->modalHeading('Close this request?')
            ->modalDescription('Closing says this request is finished. It stays in the record and can be reopened.')
            ->modalSubmitActionLabel('Close request')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid(), 'reason_choice' => RequestWorkflowService::CLOSE_REASONS[0]])
            ->form([
                $this->keyField(),
                Select::make('reason_choice')->label('Reason')->required()->live()
                    ->options(array_combine(RequestWorkflowService::CLOSE_REASONS, RequestWorkflowService::CLOSE_REASONS) + [self::OTHER_REASON => 'Other (write the reason)']),
                Textarea::make('other_reason')->label('Reason')->rows(2)->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('reason_choice') === self::OTHER_REASON)
                    ->required(fn (Get $get): bool => $get('reason_choice') === self::OTHER_REASON),
            ])
            ->action(function (array $data, Action $action): void {
                $reason = ($data['reason_choice'] ?? '') === self::OTHER_REASON ? trim((string) ($data['other_reason'] ?? '')) : (string) ($data['reason_choice'] ?? '');
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->close((int) $request->getKey(), $user, $this->emsKey($data), ['reason' => $reason]), 'Request closed');
            });
    }

    private function reopenAction(): Action
    {
        return Action::make('reopen')->label('Reopen')->icon('heroicon-o-arrow-path')->color('gray')
            ->visible(fn (): bool => $this->emsCan('act') && $this->request()->status === InventoryRequest::CLOSED)
            ->modalHeading('Reopen this request')
            ->modalSubmitActionLabel('Reopen')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid()])
            ->form([$this->keyField(), Textarea::make('reason')->label('Reason')->required()->minLength(3)->maxLength(255)->rows(2)])
            ->action(function (array $data, Action $action): void {
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->reopen((int) $request->getKey(), $user, $this->emsKey($data), ['reason' => (string) ($data['reason'] ?? '')]), 'Request reopened');
            });
    }

    private function correctAction(): Action
    {
        return Action::make('correct')->label('Correct an issuance')->icon('heroicon-o-pencil-square')->color('gray')
            ->visible(fn (): bool => $this->emsCan('act') && $this->request()->status !== InventoryRequest::CANCELLED && $this->issuanceOptions() !== [])
            ->requiresConfirmation()
            ->modalHeading('Correct an issuance')
            ->modalDescription('The original entry stays in the history. A correction is added, and stock is put back or taken again if the issue changed it.')
            ->modalSubmitActionLabel('Save correction')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid()])
            ->form([
                $this->keyField(),
                Select::make('event_id')->label('Which issuance?')->required()->live()
                    ->options(fn (): array => $this->issuanceOptions())
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $set('new_quantity', $this->issuanceQuantity((int) $state));
                        $set('supply_item_id', null);
                    }),
                TextInput::make('new_quantity')->label('Correct quantity')->required()->numeric()->integer()->minValue(0)->maxValue(999),
                Select::make('supply_item_id')->label('Supply room item (only if the wrong item was used)')->placeholder('Keep the item as recorded')
                    ->options(fn (Get $get): array => $this->candidateOptionsForIssuance((int) ($get('event_id') ?? 0))),
                Textarea::make('reason')->label('Reason for the correction')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $request = $this->request();
                $payload = array_filter([
                    'event_id' => (int) ($data['event_id'] ?? 0), 'new_quantity' => (int) ($data['new_quantity'] ?? 0),
                    'supply_item_id' => filled($data['supply_item_id'] ?? null) ? (int) $data['supply_item_id'] : null,
                    'reason' => (string) ($data['reason'] ?? ''),
                ], fn ($value): bool => $value !== null);
                $this->emsRun($action, 'act', fn ($user) => app(RequestWorkflowService::class)->correct((int) $request->getKey(), $user, $this->emsKey($data), $payload), 'Issuance corrected');
            });
    }

    private function messageAction(): Action
    {
        return Action::make('message')->label('Message the member')->icon('heroicon-o-chat-bubble-left-right')->color('gray')
            ->visible(fn (): bool => $this->emsCan('act') && $this->request()->status !== InventoryRequest::CANCELLED)
            ->modalHeading('Message the member')
            ->modalDescription('The member sees this on their request and is told there is a new message.')
            ->modalSubmitActionLabel('Send message')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid()])
            ->form([$this->keyField(), Textarea::make('body')->label('Message')->required()->maxLength(RequestMessageService::BODY_MAX)->rows(4)])
            ->action(function (array $data, Action $action): void {
                $request = $this->request();
                $this->emsRun($action, 'act', fn ($user) => app(RequestMessageService::class)->postAsStaff((int) $request->getKey(), $user, $this->emsKey($data), (string) ($data['body'] ?? '')), 'Message sent');
            });
    }

    // ------------------------------------------------------------- form defaults

    /** @return list<array<string, mixed>> */
    private function prepareRows(): array
    {
        return collect($this->detailLines())->filter(fn (array $l): bool => $this->wanted($l) > 0 && $l['line_status'] !== 'not_issued')
            ->map(fn (array $l): array => [
                'line_id' => $l['id'], 'label' => $l['label'].($l['variant_label'] ? ' ('.$l['variant_label'].')' : '').': requested '.$this->wanted($l),
                'prepared_qty' => $l['prepared_qty'] ?? max(0, $this->wanted($l) - (int) $l['issued_qty']),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function issueRows(): array
    {
        return collect($this->detailLines())->filter(fn (array $l): bool => $this->wanted($l) > 0 && $l['line_status'] !== 'not_issued')
            ->map(function (array $l): array {
                $outstanding = max(0, $this->wanted($l) - (int) $l['issued_qty']);
                $candidates = collect($l['supply_candidates'])->mapWithKeys(fn (array $c): array => [$c['id'] => $c['name'].($c['location_code'] ? ' ('.$c['location_code'].')' : '')])->all();

                return [
                    'line_id' => $l['id'],
                    'summary' => $l['label'].($l['variant_label'] ? ' ('.$l['variant_label'].')' : '').': requested '.$this->wanted($l).', issued '.(int) $l['issued_qty'],
                    'wanted' => $this->wanted($l), 'issued' => (int) $l['issued_qty'], 'candidates' => $candidates,
                    'quantity' => $l['prepared_qty'] !== null ? max(0, (int) $l['prepared_qty'] - (int) $l['issued_qty']) : $outstanding,
                ];
            })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function detailLines(): array
    {
        return $this->detail($this->request())['lines'];
    }

    /** @param  array<string, mixed>  $line */
    private function wanted(array $line): int
    {
        return $line['response_type'] === 'yes_no' ? ($line['requested_flag'] ? 1 : 0) : (int) $line['requested_qty'];
    }

    /** @param  array<string, mixed>  $arguments */
    private function lineLabel(array $arguments): string
    {
        $line = collect($this->detailLines())->firstWhere('id', (int) ($arguments['line'] ?? 0));

        return $line['label'] ?? 'item';
    }

    /** @return array<int, string> */
    private function issuanceOptions(): array
    {
        return collect($this->issuances($this->detail($this->request())))->mapWithKeys(fn (array $i): array => [
            $i['event_id'] => $i['label'].': '.$i['quantity'].' issued '.$i['at'].' by '.$i['by'],
        ])->all();
    }

    private function issuanceQuantity(int $eventId): ?int
    {
        return collect($this->issuances($this->detail($this->request())))->firstWhere('event_id', $eventId)['quantity'] ?? null;
    }

    /** @return array<int, string> */
    private function candidateOptionsForIssuance(int $eventId): array
    {
        $detail = $this->detail($this->request());
        $lineId = collect($this->issuances($detail))->firstWhere('event_id', $eventId)['line_id'] ?? null;
        $line = collect($detail['lines'])->firstWhere('id', $lineId);

        return collect($line['supply_candidates'] ?? [])->mapWithKeys(fn (array $c): array => [$c['id'] => $c['name'].($c['location_code'] ? ' ('.$c['location_code'].')' : '')])->all();
    }
}
