<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Station;
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
    ) {}

    public function submitUniform(Employee $employee, array $items, string $idempotencyKey): PersonnelRequest
    {
        if ($existing = $this->existingSubmission($idempotencyKey, $employee, PersonnelRequestType::Uniform)) {
            return $existing;
        }

        $normalized = $this->validateUniformItems($items);

        try {
            return $this->persist(PersonnelRequestType::Uniform, $employee, $employee, null, $normalized, $idempotencyKey);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            return $this->existingSubmission($idempotencyKey, $employee, PersonnelRequestType::Uniform) ?? throw $exception;
        }
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
    ): PersonnelRequest {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid submission key is required.']);
        }

        $request = DB::transaction(function () use ($type, $beneficiary, $requester, $station, $items, $idempotencyKey, $signature): PersonnelRequest {
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

    private function validateUniformItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one uniform item.']);
        }

        $normalized = [];
        foreach ($items as $index => $item) {
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
        $existing = PersonnelRequest::query()->where('idempotency_key', $idempotencyKey)->first();
        if (! $existing) {
            return null;
        }
        if ($existing->requester_employee_id !== $requester->id || $existing->type !== $type) {
            throw ValidationException::withMessages(['idempotency_key' => 'This submission key cannot be reused.']);
        }

        return $existing->load('items');
    }
}
