<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use Closure;

/**
 * The request lifecycle (docs/ems/ems-inventory-contract.md section 5):
 * submitted -> reviewed -> prepared -> partially_issued -> issued -> closed, plus cancelled.
 *
 * Every transition is one database transaction with a row lock on the request and a client
 * idempotency key. The first staff action on a submitted request records "reviewed" first, in the
 * same transaction. Issuing writes a stock movement only when the line maps to exactly one chosen
 * supply room item with an explicit conversion; otherwise the issuance is recorded with
 * stock_effect = unmatched and reported in reconciliation. History is never edited: a correction is
 * a new event plus compensating movements.
 */
final class RequestWorkflowService
{
    public const CLOSE_REASONS = ['Issued in full', 'No action required', 'Not stocked', 'Duplicate of another request'];

    public const EFFECT_APPLIED = 'applied';

    public const EFFECT_UNMATCHED = 'unmatched';

    public const EFFECT_NONE = 'none';

    private const MAX_QTY = RequestFormDefinition::MAX_QUANTITY;

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly EmsNotifier $notifier,
        private readonly RequestPresenter $presenter,
    ) {}

    // ------------------------------------------------------------ staff actions

    /** @return array{result: array<string, mixed>, replayed: bool} */
    public function review(int $requestId, User $user, string $key): array
    {
        return $this->mutate($requestId, $user, 'review', $key, [], fn (): array => []);
    }

    /**
     * @param  array<string, mixed>  $input  {lines: [{line_id, prepared_qty}]}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function prepare(int $requestId, User $user, string $key, array $input): array
    {
        $data = EmsValidator::validate($input, [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.prepared_qty' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_QTY],
        ], ['lines.required' => 'Choose at least one line to prepare.', 'lines.*.prepared_qty.max' => 'A prepared quantity can be at most '.self::MAX_QTY.'.']);

        return $this->mutate($requestId, $user, 'prepare', $key, ['open'], function (InventoryRequest $request) use ($data): array {
            $events = [];
            foreach ($data['lines'] as $row) {
                $line = $this->line($request, (int) $row['line_id']);
                $quantity = isset($row['prepared_qty']) ? (int) $row['prepared_qty'] : null;
                $line->prepared_qty = $quantity;
                $this->saveLine($line);
                $events[] = ['action' => 'prepared', 'line' => $line, 'payload' => ['label' => $line->label, 'quantity' => $quantity]];
            }

            return $events;
        });
    }

    /**
     * @param  array<string, mixed>  $input  {issuances: [{line_id, quantity, supply_item_id?, reason?}]}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function issue(int $requestId, User $user, string $key, array $input): array
    {
        $data = EmsValidator::validate($input, [
            'issuances' => ['required', 'array', 'min:1', 'max:100'],
            'issuances.*.line_id' => ['required', 'integer'],
            'issuances.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QTY],
            'issuances.*.supply_item_id' => ['nullable', 'integer'],
            'issuances.*.reason' => ['nullable', 'string', 'max:500'],
        ], [
            'issuances.required' => 'Enter a quantity to issue.',
            'issuances.*.quantity.required' => 'Enter a quantity to issue.',
            'issuances.*.quantity.min' => 'The quantity to issue must be at least 1.',
            'issuances.*.quantity.max' => 'The quantity to issue can be at most '.self::MAX_QTY.'.',
            'issuances.*.quantity.integer' => 'The quantity to issue must be a whole number.',
        ]);

        return $this->mutate($requestId, $user, 'issue', $key, ['open'], function (InventoryRequest $request, EmsActor $actor) use ($data, $key): array {
            $events = [];
            foreach (array_values($data['issuances']) as $index => $row) {
                $events[] = $this->issueOne($request, $actor, $this->line($request, (int) $row['line_id']), $row, $key, $index);
            }

            return $events;
        });
    }

    /**
     * @param  array<string, mixed>  $input  {line_id, reason}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function markNotIssued(int $requestId, User $user, string $key, array $input): array
    {
        $data = EmsValidator::validate($input, [
            'line_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Give a reason this item is not being issued.', 'reason.min' => 'Give a reason this item is not being issued.']);

        return $this->mutate($requestId, $user, 'not-issued', $key, ['open'], function (InventoryRequest $request) use ($data): array {
            $line = $this->line($request, (int) $data['line_id']);
            if ($line->line_status === InventoryRequestLine::ISSUED) {
                throw new EmsException("\"{$line->label}\" is already fully issued, so it cannot be marked not issued. Use a correction if the issued quantity is wrong.", 'already_issued', 422);
            }
            $reason = trim($data['reason']);
            $line->not_issued_reason = $reason;
            $this->saveLine($line);

            return [['action' => 'not_issued', 'line' => $line, 'payload' => ['label' => $line->label, 'reason' => $reason]]];
        });
    }

    /**
     * @param  array<string, mixed>  $input  {reason}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function close(int $requestId, User $user, string $key, array $input): array
    {
        $data = EmsValidator::validate($input, ['reason' => ['required', 'string', 'min:3', 'max:255']], [
            'reason.required' => 'Give a reason for closing this request (for example "Issued in full" or "No action required").',
            'reason.min' => 'Give a reason for closing this request.',
        ]);

        return $this->mutate($requestId, $user, 'close', $key, ['open'], function (InventoryRequest $request, EmsActor $actor) use ($data): array {
            $request->update([
                'status' => InventoryRequest::CLOSED, 'closed_at' => now(), 'closed_by_user_id' => $actor->userId, 'close_reason' => trim($data['reason']),
            ]);

            return [['action' => 'closed', 'line' => null, 'payload' => ['reason' => trim($data['reason'])], 'to_status' => InventoryRequest::CLOSED]];
        });
    }

    /**
     * @param  array<string, mixed>  $input  {reason}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function reopen(int $requestId, User $user, string $key, array $input): array
    {
        $data = EmsValidator::validate($input, ['reason' => ['required', 'string', 'min:3', 'max:255']], [
            'reason.required' => 'Give a reason for reopening this request.', 'reason.min' => 'Give a reason for reopening this request.',
        ]);

        return $this->mutate($requestId, $user, 'reopen', $key, ['closed'], function (InventoryRequest $request) use ($data): array {
            $status = $this->derivedStatus($request);
            $request->update(['status' => $status, 'closed_at' => null, 'closed_by_user_id' => null, 'close_reason' => null]);

            return [['action' => 'reopened', 'line' => null, 'payload' => ['reason' => trim($data['reason'])], 'to_status' => $status]];
        });
    }

    /**
     * Correct an issuance with compensating rows (never an edit of history).
     *
     * @param  array<string, mixed>  $input  {event_id, new_quantity, supply_item_id?, reason}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function correct(int $requestId, User $user, string $key, array $input): array
    {
        $data = EmsValidator::validate($input, [
            'event_id' => ['required', 'integer'],
            'new_quantity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_QTY],
            'supply_item_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Give a reason for the correction.', 'reason.min' => 'Give a reason for the correction.', 'new_quantity.required' => 'Enter the correct quantity.']);

        return $this->mutate($requestId, $user, 'correct', $key, ['open', 'closed'], function (InventoryRequest $request, EmsActor $actor) use ($data, $key): array {
            return [$this->correctOne($request, $actor, $data, $key)];
        });
    }

    // ----------------------------------------------------------- member action

    /**
     * Only the requester, and only before anything was issued.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function cancelByMember(int $requestId, User $user, string $key, mixed $reason): array
    {
        $reason = trim((string) EmsValidator::validate(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:255']], ['reason.max' => 'The reason can be at most 255 characters.', 'reason.string' => 'Write the reason as plain text.'])['reason']);

        return $this->idempotency->run($user, 'ems.request.cancel.'.$requestId, $key, function () use ($requestId, $user, $reason): array {
            $request = $this->lock($requestId);
            if ((int) $request->submitted_by_user_id !== (int) $user->getKey()) {
                throw EmsException::notFound('request');
            }
            $request->load('lines');
            if (! $this->presenter->memberCanCancel($request)) {
                throw EmsException::conflict($request->status === InventoryRequest::CANCELLED
                    ? 'This request was already cancelled.'
                    : 'This request can no longer be cancelled because supplies were issued or it is closed. Send a message to the EMS supply room instead.', 'cannot_cancel');
            }

            $actor = EmsActor::member($user);
            $from = $request->status;
            $request->update([
                'status' => InventoryRequest::CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->userId,
                'cancel_reason' => $reason !== '' ? $reason : null, 'last_activity_at' => now(),
            ]);
            $this->writeEvent($request, $actor, 'cancelled', null, ['reason' => $reason !== '' ? $reason : null], $from, InventoryRequest::CANCELLED);

            return $this->presenter->detail($request->fresh(), RequestPresenter::AUDIENCE_MEMBER);
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  list<string>  $allowed  'open' = not closed/cancelled; 'closed'; empty = any not cancelled
     * @param  Closure(InventoryRequest, EmsActor): list<array{action: string, line: InventoryRequestLine|null, payload: array<string, mixed>, to_status?: string}>  $work
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    private function mutate(int $requestId, User $user, string $scope, string $key, array $allowed, Closure $work): array
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }

        return $this->idempotency->run($user, "ems.request.{$scope}.{$requestId}", $key, function () use ($requestId, $user, $allowed, $work): array {
            $request = $this->lock($requestId);
            $actor = EmsActor::staff($user, $this->access);
            $this->assertState($request, $allowed);

            $from = $request->status;
            if ($request->status === InventoryRequest::SUBMITTED) {
                $request->update(['status' => InventoryRequest::REVIEWED, 'reviewed_at' => now(), 'reviewed_by_user_id' => $actor->userId]);
                $this->writeEvent($request, $actor, 'reviewed', null, [], InventoryRequest::SUBMITTED, InventoryRequest::REVIEWED);
                $from = InventoryRequest::REVIEWED;
            }

            $specs = $work($request, $actor);
            $request->load('lines');

            // Close and reopen name their own target status; everything else follows the lines.
            $lastSpec = $specs === [] ? null : $specs[array_key_last($specs)];
            $to = $lastSpec['to_status'] ?? ($request->status === InventoryRequest::CLOSED ? InventoryRequest::CLOSED : $this->derivedStatus($request));
            if ($to !== $request->status) {
                $request->update(['status' => $to]);
            }
            foreach ($specs as $index => $spec) {
                $carriesChange = $index === array_key_last($specs) && $to !== $from;
                $this->writeEvent(
                    $request, $actor, $spec['action'], $spec['line'], $spec['payload'],
                    $carriesChange ? $from : null, $carriesChange ? $to : null,
                );
            }
            if ($specs === [] && $to !== $from) {
                $this->writeEvent($request, $actor, 'status_changed', null, [], $from, $to);
            }
            $request->update(['last_activity_at' => now()]);

            return $this->presenter->detail($request->fresh(), RequestPresenter::AUDIENCE_STAFF);
        });
    }

    /** Movement keys carry who and which request, so one person's key can never replay another's change. Hashed when long. */
    private function movementKey(string $kind, EmsActor $actor, InventoryRequest $request, string $key, string $suffix): string
    {
        $plain = "{$kind}:u{$actor->userId}:r{$request->getKey()}:{$key}:{$suffix}";

        return strlen($plain) <= 150 ? $plain : "{$kind}:u{$actor->userId}:r{$request->getKey()}:".hash('sha256', $plain);
    }

    private function lock(int $requestId): InventoryRequest
    {
        $request = InventoryRequest::query()->whereKey($requestId)->lockForUpdate()->first();
        if ($request === null) {
            throw EmsException::notFound('request');
        }

        return $request;
    }

    /** @param list<string> $allowed */
    private function assertState(InventoryRequest $request, array $allowed): void
    {
        if ($request->status === InventoryRequest::CANCELLED) {
            throw EmsException::conflict('The member cancelled this request, so it cannot be changed.', 'request_cancelled');
        }
        if (in_array('open', $allowed, true) && ! in_array('closed', $allowed, true) && $request->status === InventoryRequest::CLOSED) {
            throw EmsException::conflict('This request is closed. Reopen it first.', 'request_closed');
        }
        if ($allowed === ['closed'] && $request->status !== InventoryRequest::CLOSED) {
            throw EmsException::conflict('Only a closed request can be reopened.', 'request_not_closed');
        }
    }

    private function line(InventoryRequest $request, int $lineId): InventoryRequestLine
    {
        $line = InventoryRequestLine::query()->where('request_id', $request->getKey())->whereKey($lineId)->lockForUpdate()->first();
        if ($line === null) {
            throw new EmsException('That line is not part of this request.', 'unknown_line', 422);
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeEvent(InventoryRequest $request, EmsActor $actor, string $action, ?InventoryRequestLine $line, array $payload, ?string $from, ?string $to): InventoryRequestEvent
    {
        $event = InventoryRequestEvent::query()->create([
            'request_id' => $request->getKey(),
            'request_line_id' => $line?->getKey(),
            'actor_user_id' => $actor->userId, 'actor_name' => $actor->name, 'actor_rank' => $actor->rank,
            'actor_context' => $actor->context,
            'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'payload' => $payload === [] ? null : $payload,
        ]);
        if ($to !== null) {
            $this->notifier->statusChanged($request, $event);
        }

        return $event;
    }

    // ---------------------------------------------------------------- derivation

    public function derivedStatus(InventoryRequest $request): string
    {
        $lines = $request->relationLoaded('lines') ? $request->lines : $request->lines()->get();
        $lines = $lines->map(fn (InventoryRequestLine $line): InventoryRequestLine => $line);
        if ($lines->isEmpty()) {
            return InventoryRequest::REVIEWED;
        }
        $anyIssued = $lines->sum(fn (InventoryRequestLine $line): int => (int) $line->issued_qty) > 0;
        $terminal = $lines->every(fn (InventoryRequestLine $line): bool => in_array($line->line_status, [InventoryRequestLine::ISSUED, InventoryRequestLine::NOT_ISSUED], true));
        if ($anyIssued && $terminal) {
            return InventoryRequest::ISSUED;
        }
        if ($anyIssued) {
            return InventoryRequest::PARTIALLY_ISSUED;
        }
        if ($lines->contains(fn (InventoryRequestLine $line): bool => $line->line_status === InventoryRequestLine::PREPARED)) {
            return InventoryRequest::PREPARED;
        }

        return InventoryRequest::REVIEWED;
    }

    /**
     * Save a line's fulfilment columns with its status re-derived from them. issued_qty is the running
     * total of the issued / issue_corrected event deltas (never below zero).
     */
    private function saveLine(InventoryRequestLine $line): void
    {
        $line->issued_qty = max(0, (int) $line->issued_qty);
        $line->line_status = match (true) {
            $line->not_issued_reason !== null => InventoryRequestLine::NOT_ISSUED,
            $line->wantedQty() > 0 && $line->issued_qty >= $line->wantedQty() => InventoryRequestLine::ISSUED,
            $line->issued_qty > 0 => InventoryRequestLine::PARTIALLY_ISSUED,
            (int) $line->prepared_qty > 0 => InventoryRequestLine::PREPARED,
            default => InventoryRequestLine::OPEN,
        };
        $line->save();
    }

    // ------------------------------------------------------------------ issuing

    /**
     * @param  array<string, mixed>  $row
     * @return array{action: string, line: InventoryRequestLine, payload: array<string, mixed>}
     */
    private function issueOne(InventoryRequest $request, EmsActor $actor, InventoryRequestLine $line, array $row, string $key, int $index): array
    {
        if ($line->isYesNo() && $line->requested_flag === false) {
            throw new EmsException("\"{$line->label}\" was answered No, so there is nothing to issue.", 'nothing_requested', 422);
        }
        $quantity = (int) $row['quantity'];
        $wanted = $line->wantedQty();
        $over = $line->issued_qty + $quantity > $wanted;
        $reason = trim((string) ($row['reason'] ?? ''));
        if ($over && $reason === '') {
            throw new EmsException("Issuing {$quantity} of \"{$line->label}\" is more than was requested. Give a reason to issue extra.", 'over_issue_needs_reason', 422, ['reason' => ['Give a reason to issue more than was requested.']]);
        }

        $stock = $this->resolveStock($line, $quantity, isset($row['supply_item_id']) ? (int) $row['supply_item_id'] : null);
        $movementId = null;
        if ($stock['effect'] === self::EFFECT_APPLIED) {
            $this->ledger->lock();
            $movement = $this->ledger->record(
                $stock['item'], StockMovement::ISSUED, -$stock['base'], $actor,
                $this->movementKey('issue', $actor, $request, $key, $line->getKey().':'.$index),
                "Issued for {$request->number}", ['request_id' => $request->getKey(), 'request_line_id' => $line->getKey()],
            );
            $movementId = $movement->getKey();
        }

        $line->not_issued_reason = null;
        $line->issued_qty = (int) $line->issued_qty + $quantity;
        $this->saveLine($line);
        $payload = [
            'label' => $line->label, 'quantity' => $quantity, 'quantity_delta' => $quantity,
            'supply_item_id' => $stock['item']?->getKey(), 'supply_item_name' => $stock['item']?->name,
            'stock_effect' => $stock['effect'], 'stock_note' => $stock['note'],
            'quantity_base' => $stock['effect'] === self::EFFECT_APPLIED ? $stock['base'] : null,
            'movement_id' => $movementId, 'over_issue' => $over, 'reason' => $reason !== '' ? $reason : null,
        ];

        return ['action' => 'issued', 'line' => $line, 'payload' => $payload];
    }

    /**
     * Decide whether (and where) an issuance moves stock. Never guesses.
     *
     * @return array{item: SupplyItem|null, base: int|null, effect: string, note: string|null}
     */
    public function resolveStock(InventoryRequestLine $line, int $quantity, ?int $supplyItemId): array
    {
        $catalog = $line->catalogItem;
        if ($line->isYesNo() || $line->flag('controlled_substance') || ($catalog !== null && $catalog->controlled_substance)) {
            return ['item' => null, 'base' => null, 'effect' => self::EFFECT_NONE, 'note' => 'Controlled item: recorded on the request only. Narcotics are restocked through the department controlled-substance procedure.'];
        }

        if ($supplyItemId !== null) {
            $item = SupplyItem::query()->find($supplyItemId);
            if ($item === null || ! $item->active) {
                throw new EmsException('That supply room item could not be found.', 'unknown_supply_item', 422);
            }
            if ($item->controlled_substance) {
                throw new EmsException('Controlled substances are not tracked in the supply room.', 'controlled_not_tracked', 422);
            }
        } else {
            $candidates = $line->catalog_item_id === null ? collect() : SupplyItem::query()
                ->where('catalog_item_id', $line->catalog_item_id)->where('active', true)->where('controlled_substance', false)->get();
            if ($candidates->count() !== 1) {
                return ['item' => null, 'base' => null, 'effect' => self::EFFECT_UNMATCHED, 'note' => $candidates->isEmpty()
                    ? 'No supply room item is linked to this line, so stock was not changed.'
                    : 'Several supply room items match this line. Choose one to change stock.'];
            }
            $item = $candidates->first();
        }

        $base = $this->toBase($line->unit, $quantity, $item);
        if ($base === null) {
            return ['item' => $item, 'base' => null, 'effect' => self::EFFECT_UNMATCHED, 'note' => "The line counts in \"{$line->unit}\" but the supply room item has no stated size for it, so stock was not changed."];
        }

        return ['item' => $item, 'base' => $base, 'effect' => self::EFFECT_APPLIED, 'note' => null];
    }

    /** Whole base units for a quantity in the line's unit, or null when the factor is not explicit. */
    private function toBase(?string $unit, int $quantity, SupplyItem $item): ?int
    {
        if ($unit === null || $unit === '' || $unit === 'each' || $unit === $item->base_unit) {
            return $quantity;
        }
        if ($unit === $item->package_unit && $item->units_per_package !== null) {
            return $quantity * $item->units_per_package;
        }

        return null;
    }

    // --------------------------------------------------------------- corrections

    /**
     * @param  array<string, mixed>  $data
     * @return array{action: string, line: InventoryRequestLine, payload: array<string, mixed>}
     */
    private function correctOne(InventoryRequest $request, EmsActor $actor, array $data, string $key): array
    {
        $original = InventoryRequestEvent::query()->where('request_id', $request->getKey())->whereKey((int) $data['event_id'])->where('action', 'issued')->first();
        if ($original === null) {
            throw new EmsException('Choose an issuance from this request to correct.', 'unknown_issuance', 422);
        }
        $line = $this->line($request, (int) $original->request_line_id);
        $state = $this->issuanceState($original);
        $newQuantity = (int) $data['new_quantity'];
        $requestedItem = isset($data['supply_item_id']) ? (int) $data['supply_item_id'] : null;
        $itemChanged = $requestedItem !== null && $requestedItem !== $state['supply_item_id'];
        if ($newQuantity === $state['quantity'] && ! $itemChanged) {
            throw new EmsException('That is already the recorded quantity. Nothing to correct.', 'no_change', 422);
        }

        $reason = trim($data['reason']);
        $this->ledger->lock();
        $movementIds = [];
        $netBase = $state['net_base'];
        $effect = $state['effect'];
        $itemId = $state['supply_item_id'];
        $stockNote = $effect === self::EFFECT_UNMATCHED ? ($original->payload['stock_note'] ?? null) : null;
        $links = ['request_id' => $request->getKey(), 'request_line_id' => $line->getKey(), 'corrects_movement_id' => $state['movement_id']];

        if ($itemChanged || ($effect === self::EFFECT_UNMATCHED && $requestedItem !== null)) {
            // Wrong item: give the old item its units back, then take the right quantity from the new one.
            if ($effect === self::EFFECT_APPLIED && $netBase > 0) {
                $old = SupplyItem::query()->findOrFail($itemId);
                $movementIds[] = $this->ledger->record($old, StockMovement::ADJUSTED, $netBase, $actor, $this->movementKey('correct', $actor, $request, $key, $original->getKey().':return'), "Correction of {$request->number}: {$reason}", $links)->getKey();
            }
            $new = $this->resolveStock($line, $newQuantity, $requestedItem);
            $effect = $new['effect'];
            $stockNote = $new['note'];
            $itemId = $new['item']?->getKey();
            $netBase = 0;
            if ($newQuantity > 0 && $effect === self::EFFECT_APPLIED) {
                $movementIds[] = $this->ledger->record($new['item'], StockMovement::ISSUED, -$new['base'], $actor, $this->movementKey('correct', $actor, $request, $key, $original->getKey().':issue'), "Correction of {$request->number}: {$reason}", $links)->getKey();
                $netBase = $new['base'];
            }
        } elseif ($effect === self::EFFECT_APPLIED) {
            $item = SupplyItem::query()->findOrFail($itemId);
            $targetBase = $newQuantity === 0 ? 0 : ($this->toBase($line->unit, $newQuantity, $item) ?? throw new EmsException('That item has no stated size for this unit, so the stock change cannot be worked out. Choose the item again or adjust stock directly.', 'unknown_conversion', 422));
            $change = $netBase - $targetBase; // positive: units go back to stock
            if ($change !== 0) {
                $movementIds[] = $this->ledger->record($item, StockMovement::ADJUSTED, $change, $actor, $this->movementKey('correct', $actor, $request, $key, $original->getKey().':adjust'), "Correction of {$request->number}: {$reason}", $links)->getKey();
            }
            $netBase = $targetBase;
        }

        $line->issued_qty = (int) $line->issued_qty + ($newQuantity - $state['quantity']);
        $this->saveLine($line);

        return ['action' => 'issue_corrected', 'line' => $line, 'payload' => [
            'label' => $line->label, 'corrects_event_id' => $original->getKey(),
            'previous_quantity' => $state['quantity'], 'new_quantity' => $newQuantity, 'quantity' => $newQuantity,
            'quantity_delta' => $newQuantity - $state['quantity'],
            'supply_item_id' => $itemId, 'stock_effect' => $effect, 'stock_note' => $stockNote, 'net_base_after' => $netBase,
            'movement_ids' => $movementIds, 'reason' => $reason,
        ]];
    }

    /**
     * What an issuance stands at now: its quantity, item and net base units after later corrections.
     *
     * @return array{quantity: int, supply_item_id: int|null, effect: string, net_base: int, movement_id: int|null}
     */
    private function issuanceState(InventoryRequestEvent $original): array
    {
        $payload = (array) $original->payload;
        $state = [
            'quantity' => (int) ($payload['quantity'] ?? 0),
            'supply_item_id' => isset($payload['supply_item_id']) ? (int) $payload['supply_item_id'] : null,
            'effect' => (string) ($payload['stock_effect'] ?? self::EFFECT_NONE),
            'net_base' => (int) ($payload['quantity_base'] ?? 0),
            'movement_id' => isset($payload['movement_id']) ? (int) $payload['movement_id'] : null,
        ];
        $corrections = InventoryRequestEvent::query()->where('request_line_id', $original->request_line_id)
            ->where('action', 'issue_corrected')->orderBy('id')->get();
        foreach ($corrections as $correction) {
            $p = (array) $correction->payload;
            if ((int) ($p['corrects_event_id'] ?? 0) !== (int) $original->getKey()) {
                continue;
            }
            $state['quantity'] = (int) $p['new_quantity'];
            $state['supply_item_id'] = isset($p['supply_item_id']) ? (int) $p['supply_item_id'] : null;
            $state['effect'] = (string) ($p['stock_effect'] ?? $state['effect']);
            $state['net_base'] = (int) ($p['net_base_after'] ?? 0);
        }

        return $state;
    }

}
