<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

use App\Jobs\Ems\SendKioskWithdrawalCopies;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\KioskDevice;
use App\Models\Ems\KioskWithdrawal;
use App\Models\Ems\KioskWithdrawalLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use App\Services\Ems\EmsActor;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use App\Services\Ems\StockLedger;
use App\Support\HubTimezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Takes supplies straight off the master inventory for the member at the supply room screen, and undoes it within
 * a minute (docs/ems/supply-room-kiosk.md sections 4 and 5). One withdrawal is one deduction path: issued movements
 * keyed `kiosk:w{id}:l{line}`, linked to the withdrawal; station inventory forms never touch them.
 */
final class KioskWithdrawals
{
    public const MAX_LINES = 40;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly KioskSearchLearning $learning,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function take(KioskDevice $device, array $input): array
    {
        $data = $this->validate($input);
        $lines = array_values($data['lines'] ?? []);
        $note = trim((string) ($data['note'] ?? ''));
        if ($lines === [] && $note === '') {
            throw new EmsException('Add at least one item, or tell Captain 5 what you needed.', 'nothing_to_take', 422);
        }
        $employeeNumber = trim((string) $data['employee_id']);
        $unit = ['apparatus_id' => $data['unit']['apparatus_id'] ?? null, 'label' => trim((string) ($data['unit']['label'] ?? ''))];
        $hash = hash('sha256', json_encode([$employeeNumber, $unit, array_map(fn (array $l): array => [(int) $l['supply_item_id'], (int) $l['quantity'], (string) $l['unit']], $lines), $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($device, $data, $lines, $note, $employeeNumber, $unit, $hash): array {
            // The stock lock serialises every stock write, so a retried withdrawal always finds the first one here.
            $this->ledger->lock();
            $existing = KioskWithdrawal::query()->where('uuid', $data['client_uuid'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash) || (int) $existing->device_id !== (int) $device->id) {
                    throw EmsException::conflict('That withdrawal was already sent with different items. Start a new one.', 'idempotency_mismatch');
                }

                return ['result' => $this->present($existing), 'replayed' => true];
            }
            if (DB::table('ems_kiosk_cancelled_withdrawals')->where('uuid', $data['client_uuid'])->exists()) {
                throw EmsException::conflict('This withdrawal was undone on the screen before it reached the Hub. Nothing was taken.', 'withdrawal_undone');
            }

            $employee = $this->employee($employeeNumber);
            $user = User::query()->where('employee_profile_id', $employee->id)->where('account_status', 'active')->orderBy('id')->first();
            $apparatus = $unit['apparatus_id'] !== null ? Apparatus::query()->find((int) $unit['apparatus_id']) : null;
            $items = $this->items($lines);
            $name = KioskCatalog::displayName($employee);

            $withdrawal = KioskWithdrawal::query()->create([
                'uuid' => $data['client_uuid'], 'payload_hash' => $hash, 'device_id' => $device->id,
                'employee_profile_id' => $employee->id, 'employee_number' => $employeeNumber, 'employee_name' => $name,
                'user_id' => $user?->id, 'apparatus_id' => $apparatus?->id,
                'unit_label' => $apparatus !== null ? trim((string) $apparatus->designation) : ($unit['label'] !== '' ? mb_substr($unit['label'], 0, 20) : null),
                'note' => $note !== '' ? $note : null, 'status' => KioskWithdrawal::TAKEN,
                'copy' => $user !== null || filled($employee->city_email) ? 'sent' : 'none', 'taken_at' => now(),
            ]);

            $this->record($withdrawal, $lines, $items, new EmsActor($user?->id, $name, $employee->rank, InventoryRequestEvent::CONTEXT_MEMBER));
            $this->learning->record(array_values(array_filter((array) ($data['searches'] ?? []), fn (array $s): bool => $items->has((int) $s['supply_item_id']))));
            $id = $withdrawal->id;
            DB::afterCommit(fn () => SendKioskWithdrawalCopies::afterUndoWindow($id));

            return ['result' => $this->present($withdrawal->fresh() ?? $withdrawal), 'replayed' => false];
        });
    }

    /** @return array<string, mixed> */
    public function undo(KioskDevice $device, string $uuid): array
    {
        $result = DB::transaction(function () use ($device, $uuid): ?array {
            $this->ledger->lock();
            $withdrawal = KioskWithdrawal::query()->where('uuid', $uuid)->where('device_id', $device->id)->lockForUpdate()->first();
            if ($withdrawal === null) {
                // Not here (yet): remember the undo, so a late copy of the same withdrawal is refused, not taken.
                if (! KioskWithdrawal::query()->where('uuid', $uuid)->exists()) {
                    DB::table('ems_kiosk_cancelled_withdrawals')->insertOrIgnore(['uuid' => $uuid, 'device_id' => $device->id, 'created_at' => now()]);
                }

                return null; // committed, then answered as not found below
            }
            if ($withdrawal->status === KioskWithdrawal::UNDONE) {
                return $this->present($withdrawal);
            }
            if ($withdrawal->taken_at->lt(now()->subSeconds(KioskWithdrawal::UNDO_SECONDS))) {
                throw EmsException::conflict('Undo is only open for one minute. Put the items back and tell Captain 5, who can correct the count in the Master Supply Room Inventory.', 'undo_window_closed');
            }
            $actor = new EmsActor($withdrawal->user_id, $withdrawal->employee_name, null, InventoryRequestEvent::CONTEXT_MEMBER);
            foreach ($withdrawal->lines as $line) {
                if ($line->stock_movement_id === null) {
                    continue;
                }
                $movement = $this->ledger->record($line->item()->firstOrFail(), StockMovement::ADJUSTED, $line->quantity_base, $actor,
                    "kiosk:w{$withdrawal->id}:l{$line->id}:undo", 'Undone at the supply room screen ('.$withdrawal->number().')',
                    ['corrects_movement_id' => $line->stock_movement_id, 'kiosk_withdrawal_id' => $withdrawal->id]);
                $line->forceFill(['undo_movement_id' => $movement->id])->save();
            }
            $withdrawal->forceFill(['status' => KioskWithdrawal::UNDONE, 'undone_at' => now()])->save();

            return $this->present($withdrawal);
        });

        return $result ?? throw EmsException::notFound('withdrawal');
    }

    /** @return array<string, mixed> */
    public function present(KioskWithdrawal $withdrawal): array
    {
        return [
            'uuid' => $withdrawal->uuid,
            'number' => $withdrawal->number(),
            'status' => $withdrawal->status,
            'employee_name' => $withdrawal->employee_name,
            'unit_label' => $withdrawal->unit_label,
            'taken_at' => $withdrawal->taken_at->copy()->setTimezone(HubTimezone::NAME)->toIso8601String(),
            'undo_until' => $withdrawal->taken_at->copy()->addSeconds(KioskWithdrawal::UNDO_SECONDS)->setTimezone(HubTimezone::NAME)->toIso8601String(),
            'lines' => $withdrawal->lines()->get()->map(fn (KioskWithdrawalLine $line): array => [
                'name' => $line->item_name, 'quantity' => $line->quantity, 'unit_label' => $line->quantity === 1 || $line->unit_key === 'each' ? $line->unit_label : $this->plural($line),
            ])->all(),
            'note' => $withdrawal->note,
            'copy' => $withdrawal->copy,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, SupplyItem>  $items
     */
    private function record(KioskWithdrawal $withdrawal, array $lines, Collection $items, EmsActor $actor): void
    {
        $remaining = collect($this->ledger->current($items->values()))->map(fn (array $c): ?int => $c['quantity'])->all();
        foreach ($lines as $index => $input) {
            $item = $items->get((int) $input['supply_item_id']);
            $unit = collect(KioskCatalog::unitsFor($item))->firstWhere('key', (string) $input['unit'])
                ?? throw new EmsException("\"{$item->name}\" is not taken by the {$input['unit']}. Pick one of the choices shown.", 'unit_not_offered', 422, ["lines.{$index}.unit" => ['Pick one of the choices shown.']]);
            $base = (int) $input['quantity'] * $unit['per'];
            StockLedger::assertWithinLimit($base);
            $before = $remaining[$item->id] ?? null;
            $remaining[$item->id] = $before === null ? null : $before - $base;

            $line = KioskWithdrawalLine::query()->create([
                'withdrawal_id' => $withdrawal->id, 'supply_item_id' => $item->id, 'item_name' => $item->name,
                'quantity' => (int) $input['quantity'], 'unit_key' => $unit['key'], 'unit_label' => $unit['label'], 'per' => $unit['per'],
                'quantity_base' => $base, 'shelf_showed_empty' => $before !== null && $before - $base < 0,
            ]);
            $movement = $this->ledger->record($item, StockMovement::ISSUED, -$base, $actor, "kiosk:w{$withdrawal->id}:l{$line->id}",
                'Taken at the supply room screen ('.$withdrawal->number().')', ['kiosk_withdrawal_id' => $withdrawal->id]);
            $line->forceFill(['stock_movement_id' => $movement->id])->save();
        }
    }

    private function employee(string $number): Employee
    {
        $matches = $number === '' ? collect() : KioskCatalog::activeEmployees()->where('employee_id', $number)->limit(2)->get();
        if ($matches->count() !== 1) {
            throw new EmsException('That employee ID was not found. Check the number and try again.', 'employee_not_found', 422, ['employee_id' => ['That employee ID was not found.']]);
        }

        return $matches->first();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, SupplyItem>
     */
    private function items(array $lines): Collection
    {
        $ids = array_values(array_unique(array_map(fn (array $line): int => (int) $line['supply_item_id'], $lines)));
        $items = SupplyItem::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($lines as $index => $line) {
            $item = $items->get((int) $line['supply_item_id']);
            if ($item === null || ! $item->active || $item->controlled_substance) {
                throw new EmsException('One of those items is no longer on the supply room list. Remove it and try again.', 'item_unavailable', 422, ["lines.{$index}.supply_item_id" => ['This item is no longer on the list.']]);
            }
        }

        return $items;
    }

    private function plural(KioskWithdrawalLine $line): string
    {
        $unit = collect(KioskCatalog::unitsFor($line->item()->firstOrFail()))->firstWhere('key', $line->unit_key);

        return $unit['plural'] ?? $line->unit_label;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        return EmsValidator::validate($input, [
            'client_uuid' => ['required', 'uuid'],
            'employee_id' => ['required', 'string', 'max:32'],
            'unit' => ['nullable', 'array'],
            'unit.apparatus_id' => ['nullable', 'integer', 'min:1'],
            'unit.label' => ['nullable', 'string', 'max:20'],
            'lines' => ['present', 'array', 'max:'.self::MAX_LINES],
            'lines.*.supply_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.unit' => ['required', 'string', 'max:24'],
            'note' => ['nullable', 'string', 'max:500'],
            'searches' => ['nullable', 'array', 'max:80'],
            'searches.*.term' => ['required', 'string', 'max:80'],
            'searches.*.supply_item_id' => ['required', 'integer', 'min:1'],
        ], [
            'client_uuid.required' => 'This screen sent an incomplete withdrawal. Reload the screen and try again.',
            'client_uuid.uuid' => 'This screen sent an incomplete withdrawal. Reload the screen and try again.',
            'employee_id.required' => 'Enter your employee ID.',
            'lines.max' => 'That is more than '.self::MAX_LINES.' items. Take them in two withdrawals.',
            'lines.*.quantity.min' => 'Each item needs a quantity of at least 1.',
            'lines.*.quantity.max' => 'That quantity is too large. Check the number.',
            'note.max' => 'Keep the note under 500 characters.',
        ]);
    }
}
