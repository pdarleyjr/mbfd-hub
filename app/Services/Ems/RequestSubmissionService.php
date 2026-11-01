<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Jobs\Ems\CreateMedicalInventoryRequestCopy;
use App\Models\Employee;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Submits a Medical Inventory Request.
 *
 * Rules (docs/ems/ems-inventory-contract.md section 1 and the "as built" notes):
 *  - every typed field is optional; the unit (or Detail / Special Event) is a pick, and is required
 *    because Captain 5 has to know where the supplies go;
 *  - at least one requested item OR a note is required, so an empty submission is refused;
 *  - quantities are whole numbers 1..999; blank (or 0) is dropped, never stored as 0;
 *  - identity and time are set by the server, never taken from the form;
 *  - the same client_submission_id returns the same request (a double tap or a retry never duplicates).
 */
final class RequestSubmissionService
{
    public function __construct(
        private readonly RequestFormDefinition $forms,
        private readonly EmsUnitDirectory $units,
        private readonly EmsNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{request: InventoryRequest, replayed: bool}
     */
    public function submit(User $user, Employee $employee, array $input): array
    {
        $data = EmsValidator::validate($input, [
            'client_submission_id' => ['required', 'uuid'],
            'revises' => ['nullable', 'uuid'],
            'target' => ['required', 'array'],
            'target.kind' => ['required', 'in:'.implode(',', InventoryRequest::TARGET_KINDS)],
            'target.apparatus_id' => ['nullable', 'integer'],
            'target.label_seen' => ['nullable', 'string', 'max:160'],
            'target.event_assignment' => ['nullable', 'string', 'max:'.RequestFormDefinition::EVENT_MAX],
            'member_note' => ['nullable', 'string', 'max:'.RequestFormDefinition::NOTE_MAX],
            'lines' => ['nullable', 'array', 'max:'.RequestFormDefinition::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.form' => ['required', 'in:'.implode(',', RequestFormDefinition::BAGS)],
            'lines.*.item_key' => ['required', 'string', 'max:160'],
            'lines.*.quantity' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('Quantities must be whole numbers from 1 to '.RequestFormDefinition::MAX_QUANTITY.'.');
                }
            }],
            'lines.*.flag' => ['nullable', 'boolean'],
        ], [
            'client_submission_id.required' => 'This form is missing its submission id. Reload the page and try again.',
            'client_submission_id.uuid' => 'This form is missing its submission id. Reload the page and try again.',
            'target.required' => 'Choose a unit, or Detail / Special Event.',
            'target.kind.required' => 'Choose a unit, or Detail / Special Event.',
            'target.kind.in' => 'Choose a unit, or Detail / Special Event.',
            'target.event_assignment.max' => 'The event or assignment can be at most '.RequestFormDefinition::EVENT_MAX.' characters.',
            'member_note.max' => 'The note can be at most '.RequestFormDefinition::NOTE_MAX.' characters.',
            'lines.max' => 'That is more items than the form has. Reload the page and try again.',
        ]);

        $existing = InventoryRequest::query()->where('client_submission_id', $data['client_submission_id'])->first();
        if ($existing !== null) {
            return $this->replay($existing, $user);
        }

        $target = $this->resolveTarget((array) $data['target']);
        $forms = $this->forms->activeForms();
        if ($forms === []) {
            throw new EmsException('The medical inventory forms are not available yet. Contact the EMS office.', 'forms_unavailable', 503);
        }
        $lines = $this->resolveLines((array) ($data['lines'] ?? []), $forms);
        $note = trim((string) ($data['member_note'] ?? ''));
        if ($lines === [] && $note === '') {
            throw new EmsException('Add at least one item to request, or write a note, before you send.', 'empty_request', 422, ['lines' => ['Add at least one item to request, or write a note, before you send.']]);
        }

        try {
            $request = DB::transaction(fn (): InventoryRequest => $this->create($user, $employee, $data, $target, $lines, $forms, $note));
        } catch (UniqueConstraintViolationException $exception) {
            // A parallel retry of the same submission won the race; return what it saved.
            $winner = InventoryRequest::query()->where('client_submission_id', $data['client_submission_id'])->first();
            if ($winner === null) {
                throw $exception;
            }

            return $this->replay($winner, $user);
        } catch (EmsException $exception) {
            // Two sends of one correction: the second waited on the row lock and found it already corrected, by itself.
            $winner = $exception->errorCode === 'cannot_revise' ? InventoryRequest::query()->where('client_submission_id', $data['client_submission_id'])->first() : null;
            if ($winner === null) {
                throw $exception;
            }

            return $this->replay($winner, $user);
        }

        return ['request' => $request, 'replayed' => false];
    }

    /** @return array{request: InventoryRequest, replayed: bool} */
    private function replay(InventoryRequest $existing, User $user): array
    {
        if ((int) $existing->submitted_by_user_id !== (int) $user->getKey()) {
            throw EmsException::conflict('That submission id was already used. Reload the page and try again.', 'duplicate_submission');
        }

        return ['request' => $existing, 'replayed' => true];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array{kind: string, apparatus_id: int|null, label: string, event_assignment: string|null}
     */
    private function resolveTarget(array $target): array
    {
        $event = trim((string) ($target['event_assignment'] ?? ''));
        if ($target['kind'] === InventoryRequest::TARGET_DETAIL) {
            return [
                'kind' => InventoryRequest::TARGET_DETAIL, 'apparatus_id' => null,
                'label' => EmsUnitDirectory::DETAIL_LABEL, 'event_assignment' => $event !== '' ? $event : null,
            ];
        }

        $unit = $this->units->find(isset($target['apparatus_id']) ? (int) $target['apparatus_id'] : null);
        if ($unit === null) {
            throw new EmsException('Pick a unit from the list, or choose Detail / Special Event.', 'invalid_unit', 422, ['target.apparatus_id' => ['Pick a unit from the list, or choose Detail / Special Event.']]);
        }

        // The page also says which unit name it showed. A page that sends none (an older page, a queued request) is trusted as before;
        // one that sends a name which is not this unit's was out of date or wrong, so nothing is saved.
        $seen = trim((string) ($target['label_seen'] ?? ''));
        if ($seen !== '' && mb_strtolower($seen) !== mb_strtolower(trim((string) $unit['label']))) {
            throw new EmsException('The unit you picked does not match what was sent. Please choose the unit again and send the request again.', 'unit_mismatch', 422, ['target.apparatus_id' => ['Choose the unit again.']]);
        }

        return ['kind' => $unit['kind'], 'apparatus_id' => $unit['id'], 'label' => $unit['label'], 'event_assignment' => $event !== '' ? $event : null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $submitted
     * @param  array<string, SourceForm>  $forms
     * @return list<array{bag: string, item: SourceFormItem, qty: int|null, flag: bool|null}>
     */
    private function resolveLines(array $submitted, array $forms): array
    {
        $items = [];
        foreach ($forms as $key => $form) {
            $items[$key] = $form->items()->get()->keyBy('item_key');
        }
        $order = array_flip(array_keys($forms));

        $lines = [];
        $seen = [];
        foreach ($submitted as $row) {
            $bag = (string) $row['form'];
            $item = ($items[$bag] ?? collect())->get((string) $row['item_key']);
            if (! $item instanceof SourceFormItem) {
                throw new EmsException('One of the items is no longer on the form. Reload the page and try again.', 'unknown_item', 422, ['lines' => ['Unknown item '.$row['item_key']]]);
            }
            $identity = $bag.'|'.$item->item_key;
            if (isset($seen[$identity])) {
                throw new EmsException("\"{$item->label}\" is on the request twice. Keep one line for it.", 'duplicate_item', 422, ['lines' => [$item->label]]);
            }
            $seen[$identity] = true;

            if ($item->response_type === SourceFormItem::YES_NO) {
                if ($this->hasValue($row['quantity'] ?? null)) {
                    throw new EmsException("\"{$item->label}\" is a Yes or No answer, not a quantity.", 'invalid_answer', 422, ['lines' => [$item->label]]);
                }
                $flag = array_key_exists('flag', $row) ? $row['flag'] : null;
                if ($flag === null) {
                    continue; // blank stays blank
                }
                $lines[] = ['bag' => $bag, 'item' => $item, 'qty' => null, 'flag' => (bool) $flag, 'bag_order' => $order[$bag]];

                continue;
            }

            if (array_key_exists('flag', $row) && $row['flag'] !== null) {
                throw new EmsException("\"{$item->label}\" takes a quantity, not Yes or No.", 'invalid_answer', 422, ['lines' => [$item->label]]);
            }
            $quantity = $this->quantity($row['quantity'] ?? null, $item->label);
            if ($quantity === null) {
                continue; // blank (or 0) is dropped, never stored as 0
            }
            $lines[] = ['bag' => $bag, 'item' => $item, 'qty' => $quantity, 'flag' => null, 'bag_order' => $order[$bag]];
        }

        usort($lines, fn (array $a, array $b): int => [$a['bag_order'], $a['item']->sort, $a['item']->id] <=> [$b['bag_order'], $b['item']->sort, $b['item']->id]);

        return $lines;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function quantity(mixed $value, string $label): ?int
    {
        if (! $this->hasValue($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (! is_numeric($text) || (float) $text !== floor((float) $text)) {
            throw new EmsException("The quantity for \"{$label}\" must be a whole number from 1 to ".RequestFormDefinition::MAX_QUANTITY.'.', 'invalid_quantity', 422, ['lines' => [$label]]);
        }
        $number = (int) $text;
        if ($number === 0) {
            return null;
        }
        if ($number < 0 || $number > RequestFormDefinition::MAX_QUANTITY) {
            throw new EmsException("The quantity for \"{$label}\" must be a whole number from 1 to ".RequestFormDefinition::MAX_QUANTITY.'.', 'invalid_quantity', 422, ['lines' => [$label]]);
        }

        return $number;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{kind: string, apparatus_id: int|null, label: string, event_assignment: string|null}  $target
     * @param  list<array{bag: string, item: SourceFormItem, qty: int|null, flag: bool|null}>  $lines
     * @param  array<string, SourceForm>  $forms
     */
    private function create(User $user, Employee $employee, array $data, array $target, array $lines, array $forms, string $note): InventoryRequest
    {
        $now = now();
        $original = isset($data['revises']) ? $this->lockRevisable((string) $data['revises'], $user) : null;
        $number = $original !== null ? $this->revisionNumber($original) : $this->nextNumber();
        $actor = EmsActor::member($user);
        $versions = [];
        foreach ($forms as $key => $form) {
            $versions[$key] = ['version_label' => $form->version_label, 'sha256' => $form->sha256, 'title' => $form->title];
        }

        $snapshotLines = array_map(fn (array $line): array => $this->lineAttributes($line), $lines);
        $snapshot = [
            'schema' => 'mbfd.ems.mir_snapshot.v1',
            'number' => $number,
            'submitted_at' => $now->copy()->utc()->toIso8601String(),
            'submitter' => ['user_id' => $user->getKey(), 'employee_id' => $employee->getKey(), 'name' => $actor->name, 'rank' => $actor->rank],
            'target' => ['kind' => $target['kind'], 'apparatus_id' => $target['apparatus_id'], 'label' => $target['label'], 'event_assignment' => $target['event_assignment']],
            'member_note' => $note !== '' ? $note : null,
            'forms' => $versions,
            'lines' => $snapshotLines,
            'revises' => $original?->number,
        ];
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $request = InventoryRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'number' => $number,
            'status' => InventoryRequest::SUBMITTED,
            'submitted_by_user_id' => $user->getKey(),
            'submitted_by_employee_id' => $employee->getKey(),
            'submitter_name' => $actor->name,
            'submitter_rank' => $actor->rank,
            'submitted_at' => $now,
            'target_kind' => $target['kind'],
            'apparatus_id' => $target['apparatus_id'],
            'target_label' => $target['label'],
            'event_assignment' => $target['event_assignment'],
            'member_note' => $note !== '' ? $note : null,
            'client_submission_id' => $data['client_submission_id'],
            'source_form_versions' => $versions,
            'snapshot' => $snapshot,
            'snapshot_sha256' => hash('sha256', $encoded),
            'last_activity_at' => $now,
            'revision_of_request_id' => $original?->getKey(),
            'revision_number' => $original !== null ? $original->revision_number + 1 : 1,
        ]);

        foreach ($snapshotLines as $index => $line) {
            InventoryRequestLine::query()->create([
                'request_id' => $request->getKey(),
                'source_form_item_id' => $line['source_form_item_id'],
                'catalog_item_id' => $line['catalog_item_id'],
                'bag_key' => $line['bag_key'],
                'item_key' => $line['item_key'],
                'label' => $line['label'],
                'section_label' => $line['section_label'],
                'variant_label' => $line['variant_label'],
                'min_text' => $line['min_text'],
                'unit' => $line['unit'],
                'response_type' => $line['response_type'],
                'requested_qty' => $line['requested_qty'],
                'requested_flag' => $line['requested_flag'],
                'line_status' => $line['requested_flag'] === false ? InventoryRequestLine::NOT_ISSUED : InventoryRequestLine::OPEN,
                'not_issued_reason' => $line['requested_flag'] === false ? 'Answered No, so nothing to issue.' : null,
                'flags' => $line['flags'],
                'sort' => $index + 1,
            ]);
        }

        InventoryRequestEvent::query()->create([
            'request_id' => $request->getKey(),
            'actor_user_id' => $actor->userId, 'actor_name' => $actor->name, 'actor_rank' => $actor->rank,
            'actor_context' => InventoryRequestEvent::CONTEXT_MEMBER,
            'action' => 'submitted', 'from_status' => null, 'to_status' => InventoryRequest::SUBMITTED,
            'payload' => ['line_count' => count($snapshotLines), 'has_note' => $note !== '', 'snapshot_sha256' => $request->snapshot_sha256, 'revises' => $original?->number],
        ]);

        if ($original !== null) {
            $original->update(['status' => InventoryRequest::SUPERSEDED, 'last_activity_at' => $now]);
            InventoryRequestEvent::query()->create([
                'request_id' => $original->getKey(),
                'actor_user_id' => $actor->userId, 'actor_name' => $actor->name, 'actor_rank' => $actor->rank,
                'actor_context' => InventoryRequestEvent::CONTEXT_MEMBER,
                'action' => 'revised', 'from_status' => InventoryRequest::RETURNED, 'to_status' => InventoryRequest::SUPERSEDED,
                'payload' => ['replaced_by' => $request->uuid, 'replaced_by_number' => $number],
            ]);
        }

        $this->notifier->requestSubmitted($request);
        $this->queueFilesCopy($request);

        return $request;
    }

    /**
     * The values copied at submission time so later renames never change history.
     *
     * @param  array{bag: string, item: SourceFormItem, qty: int|null, flag: bool|null}  $line
     * @return array<string, mixed>
     */
    private function lineAttributes(array $line): array
    {
        /** @var SourceFormItem $item */
        $item = $line['item'];

        return [
            'source_form_item_id' => $item->getKey(),
            'catalog_item_id' => $item->catalog_item_id,
            'catalog_key' => $item->catalogItem?->key,
            'bag_key' => $line['bag'],
            'item_key' => $item->item_key,
            'label' => $item->label,
            'section_label' => $item->section_label,
            'variant_label' => $item->variant_label,
            'min_text' => $item->min_text,
            'unit' => $item->min_unit,
            'response_type' => $item->response_type,
            'requested_qty' => $line['qty'],
            'requested_flag' => $line['flag'],
            'flags' => $item->flags ?? [],
        ];
    }

    /** Only the member who sent it, and only while it is waiting for them. The row lock makes a double send one revision. */
    private function lockRevisable(string $uuid, User $user): InventoryRequest
    {
        $original = InventoryRequest::query()->where('uuid', $uuid)->lockForUpdate()->first();
        if ($original === null || (int) $original->submitted_by_user_id !== (int) $user->getKey() || $original->status !== InventoryRequest::RETURNED) {
            throw EmsException::conflict('This form was already corrected or cancelled, so it cannot be corrected again. Your entries are still here.', 'cannot_revise');
        }

        return $original;
    }

    /** MIR-000004, then MIR-000004-R2, MIR-000004-R3: the corrected form keeps its family name. */
    private function revisionNumber(InventoryRequest $original): string
    {
        return preg_replace('/-R\d+$/', '', $original->number).'-R'.($original->revision_number + 1);
    }

    /** MIR numbers come from one counter row: the UPDATE locks it until commit, so concurrent submissions never share a number. */
    private function nextNumber(): string
    {
        DB::table('ems_counters')->insertOrIgnore(['name' => 'mir', 'value' => 0]);
        DB::table('ems_counters')->where('name', 'mir')->increment('value');
        $value = (int) DB::table('ems_counters')->where('name', 'mir')->value('value');

        return sprintf('MIR-%06d', $value);
    }

    private function queueFilesCopy(InventoryRequest $request): void
    {
        DB::afterCommit(function () use ($request): void {
            try {
                CreateMedicalInventoryRequestCopy::dispatch((int) $request->getKey());
            } catch (Throwable $exception) {
                // The request is saved; the copy is regenerated on demand and by the retry job.
                Log::error('EMS Files copy could not be queued', ['request_id' => $request->getKey(), 'exception' => $exception->getMessage()]);
            }
        });
    }
}
