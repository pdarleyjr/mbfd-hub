<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Station;
use App\Support\PersonnelRequests\PersonnelRequestParticipantAccess;
use App\Support\PersonnelRequests\UniformOrderAmendment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PersonnelRequestSubmissionService
{
    public function __construct(
        private readonly PersonnelCatalog $catalog,
        private readonly OfficerAuthorizationService $officers,
        private readonly SignatureImageService $signatures,
        private readonly PersonnelRequestNotifier $notifier,
        private readonly UniformEntitlementService $entitlements,
        private readonly UniformAllowanceService $allowances,
    ) {}

    public function submitUniform(Employee $employee, array $items, string $idempotencyKey, array $metadata = []): PersonnelRequest
    {
        if ($existing = $this->existingSubmission($idempotencyKey, $employee, PersonnelRequestType::Uniform)) {
            return $existing;
        }

        $normalized = $this->validateUniformItems($items);
        $note = $this->validatedNote($metadata['member_note'] ?? null);
        $justification = $this->validatedJustification($metadata['allowance_justification'] ?? null);
        $context = $this->entitlements->forEmployee($employee);
        $summary = $this->entitlements->summarize($context, $normalized);
        $snapshot = $this->entitlements->snapshot($context, $summary, $note);
        // Runs after the member row is locked so concurrent submissions see each other's usage.
        $allowanceGuard = fn (): array => $this->allowanceAttributes($employee, $context, $normalized, $justification);

        try {
            return $this->persist(PersonnelRequestType::Uniform, $employee, $employee, null, $normalized, $idempotencyKey, metadata: $snapshot, guard: $allowanceGuard);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            return $this->existingSubmission($idempotencyKey, $employee, PersonnelRequestType::Uniform) ?? throw $exception;
        }
    }

    /**
     * The member changes their own uniform order before anything is ordered, received or issued.
     * The same checks as a new order run again, counting this order's current lines out of the
     * annual allowance and jacket limit. Status is left alone. The lines before and after, and on
     * the first change the original submission, are kept in the history.
     *
     * @param  array<string, mixed>  $metadata  member_note and allowance_justification, as on submit
     */
    public function amendUniform(Employee $employee, PersonnelRequest $request, array $items, string $amendKey, array $metadata = []): PersonnelRequest
    {
        $this->assertActionKey($amendKey);
        $normalized = $this->validateUniformItems($items);
        if (collect($normalized)->pluck('item_code')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'List each item once, with the total quantity you need.']);
        }
        $note = $this->validatedNote($metadata['member_note'] ?? null);
        $justification = $this->validatedJustification($metadata['allowance_justification'] ?? null);
        $fingerprint = hash('sha256', json_encode([$employee->id, $request->id, $normalized, $note, $justification], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($employee, $request, $normalized, $note, $justification, $amendKey, $fingerprint): PersonnelRequest {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked = PersonnelRequest::query()->lockForUpdate()->find($request->id)
                ?? throw ValidationException::withMessages(['items' => 'This order is no longer available to edit.']);
            abort_unless(PersonnelRequestParticipantAccess::isParticipant($employee, $locked), 403);
            $previous = $locked->updates()->where('metadata->idempotency_key', $amendKey)->first();
            if ($previous !== null) {
                if ($previous->event !== UniformOrderAmendment::EVENT || data_get($previous->metadata, 'fingerprint') !== $fingerprint) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This change was already saved with different details. Reload the page and try again.']);
                }

                return $locked->load('items', 'updates');
            }
            $current = $locked->items()->orderBy('id')->lockForUpdate()->get();
            if ($reason = UniformOrderAmendment::blockReason($employee, $locked, $current)) {
                throw ValidationException::withMessages(['items' => $reason]);
            }
            if (collect($normalized)->contains('item_code', 'jacket')) {
                $eligibility = $this->entitlements->jacketEligibility($employee, $locked->id);
                if (! $eligibility['can_order']) {
                    throw ValidationException::withMessages(['items.jacket.quantity' => $eligibility['reason']]);
                }
            }
            $context = $this->entitlements->forEmployee($employee);
            $summary = $this->entitlements->summarize($context, $normalized);
            $allowance = $this->allowanceAttributes($employee, $context, $normalized, $justification, $locked->id);

            $before = UniformOrderAmendment::snapshot($current);
            $after = UniformOrderAmendment::snapshot($normalized);
            $noteBefore = data_get($locked->metadata, 'member_note');
            $changes = UniformOrderAmendment::describeChanges($before, $after, $noteBefore, $note);
            if ($changes === []) {
                throw ValidationException::withMessages(['items' => 'Nothing was changed. Change an item, size or quantity, then save.']);
            }

            $this->replaceItems($locked, $current, $normalized);
            $locked->forceFill([
                'metadata' => $this->amendedMetadata($locked, $before, $note, $summary, $allowance),
                'over_allowance' => $allowance['over_allowance'],
                'allowance_justification' => $allowance['allowance_justification'],
            ])->save();
            $locked->updates()->create([
                'event' => UniformOrderAmendment::EVENT,
                'status' => $locked->status,
                'employee_visible_note' => UniformOrderAmendment::MEMBER_NOTE,
                'changed_by_employee_id' => $employee->id,
                'metadata' => [
                    'idempotency_key' => $amendKey,
                    'fingerprint' => $fingerprint,
                    'changes' => $changes,
                    'before' => ['items' => $before, 'member_note' => $noteBefore],
                    'after' => ['items' => $after, 'member_note' => $note],
                ],
            ]);
            DB::afterCommit(fn () => $this->notifier->memberAmended($locked, $changes));

            return $locked->fresh(['items', 'updates']);
        });
    }

    /**
     * Keep each line's row (and its item history) when the same product stays on the order;
     * add new products and remove the ones the member took off.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\PersonnelRequestItem>  $current
     * @param  list<array<string, mixed>>  $normalized
     */
    private function replaceItems(PersonnelRequest $request, \Illuminate\Support\Collection $current, array $normalized): void
    {
        $existing = $current->keyBy('item_code');
        foreach ($normalized as $line) {
            $row = $existing->pull($line['item_code']);
            if ($row === null) {
                $request->items()->create($line);

                continue;
            }
            $row->fill($line);
            if ($row->isDirty()) {
                $row->save();
            }
        }
        foreach ($existing as $removed) {
            $removed->delete();
        }
    }

    /**
     * The bid-assignment snapshot stays as submitted. The original lines and notes are copied once,
     * on the first change; the current note, advisories and allowance flags follow the new lines.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  array<string, mixed>  $summary
     * @param  array{over_allowance: bool, allowance_justification: string|null, metadata: array<string, mixed>}  $allowance
     * @return array<string, mixed>
     */
    private function amendedMetadata(PersonnelRequest $request, array $before, ?string $note, array $summary, array $allowance): array
    {
        $metadata = $request->metadata ?? [];
        $metadata['original_submission'] ??= [
            'items' => $before,
            'member_note' => $metadata['member_note'] ?? null,
            'warnings_at_submission' => $metadata['warnings_at_submission'] ?? null,
            'calculated_swap_credits' => $metadata['calculated_swap_credits'] ?? null,
            'over_allowance' => (bool) $request->over_allowance,
            'allowance_justification' => $request->allowance_justification,
            'allowance_at_submission' => $metadata['allowance_at_submission'] ?? null,
        ];
        $metadata['member_note'] = $note;
        $metadata['warnings_at_submission'] = $summary['warnings'];
        $metadata['calculated_swap_credits'] = $summary['swaps'];
        unset($metadata['allowance_at_submission']);

        return array_merge($metadata, $allowance['metadata']);
    }

    public function submitEquipment(
        Employee $officer,
        Employee $beneficiary,
        Station $station,
        array $items,
        string $signatureDataUrl,
        string $idempotencyKey,
    ): PersonnelRequest {
        if ($existing = $this->existingSubmission($idempotencyKey, $officer, PersonnelRequestType::Equipment)) {
            return $existing;
        }

        if (! $this->officers->isAuthorized($officer)) {
            throw ValidationException::withMessages(['officer' => 'Your rank is not authorized to submit personnel PPE requests.']);
        }

        $normalized = $this->validateEquipmentItems($items);
        $signature = $this->signatures->store($signatureDataUrl);

        try {
            return $this->persist(
                PersonnelRequestType::Equipment,
                $beneficiary,
                $officer,
                $station,
                $normalized,
                $idempotencyKey,
                $signature,
            );
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Storage::disk($signature['disk'])->delete($signature['path']);
            if ($exception instanceof \Illuminate\Database\UniqueConstraintViolationException) {
                return $this->existingSubmission($idempotencyKey, $officer, PersonnelRequestType::Equipment) ?? throw $exception;
            }
            throw $exception;
        }
    }

    private function persist(
        PersonnelRequestType $type,
        Employee $beneficiary,
        Employee $requester,
        ?Station $station,
        array $items,
        string $idempotencyKey,
        ?array $signature = null,
        array $metadata = [],
        ?\Closure $guard = null,
    ): PersonnelRequest {
        $this->assertActionKey($idempotencyKey);

        $request = DB::transaction(function () use ($type, $beneficiary, $requester, $station, $items, $idempotencyKey, $signature, $metadata, $guard): PersonnelRequest {
            $extra = [];
            if ($type === PersonnelRequestType::Uniform) {
                Employee::query()->whereKey($beneficiary->id)->lockForUpdate()->firstOrFail();
                if ($existing = $this->existingSubmission($idempotencyKey, $requester, $type)) {
                    return $existing;
                }
                if (collect($items)->contains('item_code', 'jacket')) {
                    $eligibility = $this->entitlements->jacketEligibility($beneficiary);
                    if (! $eligibility['can_order']) {
                        throw ValidationException::withMessages(['items.jacket.quantity' => $eligibility['reason']]);
                    }
                }
                $extra = $guard ? $guard() : [];
            }
            $publicId = (string) Str::ulid();
            $request = PersonnelRequest::query()->create([
                'public_id' => $publicId,
                'request_number' => 'PR-'.now()->format('ymd').'-'.str($publicId)->substr(-6)->upper(),
                'type' => $type,
                'beneficiary_employee_id' => $beneficiary->id,
                'requester_employee_id' => $requester->id,
                'originating_station_id' => $station?->id,
                'beneficiary_name' => $beneficiary->name,
                'beneficiary_rank' => $beneficiary->rank,
                'beneficiary_employee_number' => $beneficiary->employee_id,
                'requester_name' => $requester->name,
                'requester_rank' => $requester->rank,
                'requester_employee_number' => $requester->employee_id,
                'status' => PersonnelRequestStatus::Pending,
                'officer_signature_disk' => $signature['disk'] ?? null,
                'officer_signature_path' => $signature['path'] ?? null,
                'officer_signature_mime' => $signature['mime'] ?? null,
                'officer_signature_sha256' => $signature['sha256'] ?? null,
                'signed_at' => $signature ? now() : null,
                'idempotency_key' => $idempotencyKey,
                'metadata' => array_merge($metadata, $extra['metadata'] ?? []) ?: null,
                'over_allowance' => $extra['over_allowance'] ?? false,
                'allowance_justification' => $extra['allowance_justification'] ?? null,
            ]);

            foreach ($items as $item) {
                $request->items()->create($item);
            }
            $request->updates()->create([
                'event' => 'submitted',
                'status' => PersonnelRequestStatus::Pending,
                'changed_by_employee_id' => $requester->id,
                'employee_visible_note' => 'Request submitted.',
            ]);

            DB::afterCommit(fn () => $this->notifier->created($request));

            return $request;
        });

        return $request->load('items', 'updates');
    }

    private function assertActionKey(string $key): void
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid submission key is required.']);
        }
    }

    private function validatedNote(mixed $note): ?string
    {
        $noteMax = (int) config('uniform_orders.note_max', 4000);
        if ($note !== null && (! is_string($note) || mb_strlen($note) > $noteMax)) {
            throw ValidationException::withMessages(['member_note' => 'Notes must be text of no more than '.number_format($noteMax).' characters.']);
        }

        return filled($note) ? trim($note) : null;
    }

    private function validatedJustification(mixed $value): ?string
    {
        $max = (int) config('uniform_orders.allowance_justification_max', 1000);
        if ($value !== null && (! is_string($value) || mb_strlen(trim($value)) > $max)) {
            throw ValidationException::withMessages(['allowance_justification' => 'Keep your reason to '.number_format($max).' characters or fewer.']);
        }

        return filled($value) ? trim($value) : null;
    }

    /**
     * Soft annual-allowance enforcement: over the remaining allowance requires a
     * reason and flags the request for Support Services; otherwise nothing changes.
     *
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $items
     * @return array{over_allowance: bool, allowance_justification: string|null, metadata: array<string, mixed>}
     */
    private function allowanceAttributes(Employee $employee, array $context, array $items, ?string $justification, ?int $excludeRequestId = null): array
    {
        $assessment = $this->allowances->assess($context, $this->allowances->usedByGroup($employee, null, $excludeRequestId), $items);
        if (! $assessment['is_over']) {
            return ['over_allowance' => false, 'allowance_justification' => null, 'metadata' => []];
        }
        if ($justification === null) {
            throw ValidationException::withMessages([
                'allowance_justification' => 'Your order is over your remaining annual allowance. Add a short reason so Support Services can review it.',
            ]);
        }

        return [
            'over_allowance' => true,
            'allowance_justification' => $justification,
            'metadata' => ['allowance_at_submission' => [
                'period' => $assessment['period']['label'],
                'over' => $this->allowances->overageMessages($assessment['over']),
            ]],
        ];
    }

    private function validateUniformItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one uniform item.']);
        }
        $this->entitlements->validateJacketQuantity($items);

        $structured = array_filter($items, fn ($item): bool => is_array($item)
            && (array_key_exists('metadata', $item) || array_key_exists('attributes', $item)));
        $structuredByKey = $structured === [] ? [] : array_combine(array_keys($structured), $this->entitlements->normalizeItems($structured));
        $normalized = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(["items.{$index}" => 'Select a valid uniform item.']);
            }
            if (array_key_exists($index, $structuredByKey)) {
                $normalized[] = $structuredByKey[$index];

                continue;
            }
            $code = is_string($item['item_code'] ?? null) ? $item['item_code'] : '';
            $catalogItem = $this->catalog->uniform($code);
            if ($catalogItem === null) {
                throw ValidationException::withMessages(["items.{$index}.item_code" => 'This item is not available through uniform self-service.']);
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 10) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be between 1 and 10.']);
            }
            $size = trim((string) ($item['size'] ?? ''));
            if ($size === '' || mb_strlen($size) > 30) {
                throw ValidationException::withMessages(["items.{$index}.size" => 'A valid size is required.']);
            }
            $normalized[] = [
                'item_code' => $code,
                'item_name' => $catalogItem['label'],
                'category' => 'uniform',
                'size' => $size,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    private function validateEquipmentItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one equipment item.']);
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            $code = is_string($item['item_code'] ?? null) ? $item['item_code'] : '';
            $label = $this->catalog->equipmentLabel($code);
            if ($label === null) {
                throw ValidationException::withMessages(["items.{$index}.item_code" => 'Select an approved personnel equipment item.']);
            }
            $reason = is_string($item['reason'] ?? null) ? $item['reason'] : '';
            if (! array_key_exists($reason, $this->catalog->equipmentReasons())) {
                throw ValidationException::withMessages(["items.{$index}.reason" => 'Select Lost, Damaged, or Stolen.']);
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 10) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be between 1 and 10.']);
            }
            $other = trim((string) ($item['other_description'] ?? ''));
            if ($code === 'other' && ($other === '' || mb_strlen($other) > 255)) {
                throw ValidationException::withMessages(["items.{$index}.other_description" => 'Describe the personnel equipment being replaced.']);
            }
            $normalized[] = [
                'item_code' => $code,
                'item_name' => $code === 'other' ? $other : $label,
                'category' => 'equipment',
                'quantity' => $quantity,
                'reason' => $reason,
                'other_description' => $code === 'other' ? $other : null,
            ];
        }

        return $normalized;
    }

    private function existingSubmission(string $idempotencyKey, Employee $requester, PersonnelRequestType $type): ?PersonnelRequest
    {
        $existing = PersonnelRequest::withTrashed()->where('idempotency_key', $idempotencyKey)->first();
        if (! $existing) {
            return null;
        }
        if ($existing->requester_employee_id !== $requester->id || $existing->type !== $type) {
            throw ValidationException::withMessages(['idempotency_key' => 'This submission key cannot be reused.']);
        }
        if ($existing->trashed()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This order is in Trash. Ask Support Services to restore it, or start a new order.']);
        }

        return $existing->load('items');
    }
}
