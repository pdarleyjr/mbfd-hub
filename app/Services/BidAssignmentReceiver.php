<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\EmployeeProfileEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class BidAssignmentReceiver
{
    /**
     * @param  array<string, mixed>  $payload
     * @return bool True for a new revision; false for an identical retry.
     */
    public function receive(string $employeeId, array $payload): bool
    {
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($employeeId, $payload, $hash): bool {
            $employees = Employee::query()->where('employee_id', $employeeId)->get();
            if ($employees->count() !== 1) {
                $this->reject('employee_id', 'Employee identity does not resolve uniquely.');
            }
            $employee = $employees->firstOrFail();
            $users = User::query()->where('employee_profile_id', $employee->getKey())->lockForUpdate()->get();
            // Match the existing identity writers' User -> Employee lock order.
            // This parent lock serializes simultaneous first assignment writes.
            $employees = Employee::query()->where('employee_id', $employeeId)->lockForUpdate()->get();
            if ($employees->count() !== 1 || $employees->first()?->getKey() !== $employee->getKey()) {
                $this->reject('employee_id', 'Employee identity changed during publication.');
            }
            $employee = $employees->firstOrFail();
            if ($users->count() !== 1 || $users->first()?->employee_id !== $employee->employee_id) {
                $this->reject('employee_id', 'Canonical account linkage does not resolve uniquely.');
            }

            $recorded = EmployeeBidAssignment::query()->where('idempotency_key', $payload['idempotency_key'])->first();
            if ($recorded !== null) {
                if ($recorded->employee_profile_id !== $employee->getKey() || ! hash_equals($recorded->payload_hash, $hash)) {
                    $this->reject('idempotency_key', 'Idempotency key was already used for a different payload.');
                }

                return false;
            }

            $current = EmployeeBidAssignment::query()->where('employee_profile_id', $employee->getKey())
                ->where('bid_year', $payload['bid_year'])->whereNull('superseded_at')->first();
            $version = $payload['payload_version'] ?? 1;
            if ($current !== null) {
                if ($current->bid_session_id !== $payload['bid_session_id']) {
                    $this->reject('bid_session_id', 'A different session cannot replace this year\'s assignment.');
                }
                if ($version !== 2 || ($current->source_sequence !== null && $payload['source_sequence'] <= $current->source_sequence)) {
                    $this->reject('source_sequence', 'A correction must advance the canonical source sequence.');
                }
                $current->update(['superseded_at' => now()]);
            }

            $attributes = $payload;
            unset($attributes['employee_id']);
            $assignment = EmployeeBidAssignment::query()->create($attributes + [
                'employee_profile_id' => $employee->getKey(),
                'payload_hash' => $hash,
                'payload_version' => $version,
                // V1 explicitly lacks specialty labels; do not invent them.
                'term_label' => $payload['bid_year'].'–'.($payload['bid_year'] + 1),
                'bid_selection_label' => $payload['unit_label'],
                'assignment_source' => 'bid_award',
            ]);
            EmployeeProfileEvent::query()->create([
                'employee_id' => $employee->getKey(),
                'actor_user_id' => null, // Authenticated dedicated machine writer.
                'target_user_id' => $users->firstOrFail()->getKey(),
                'action' => 'bid_assignment.published',
                'result' => 'accepted',
                'metadata' => [
                    'assignment_id' => $assignment->getKey(),
                    'bid_year' => $payload['bid_year'],
                    'source_sequence' => $payload['source_sequence'] ?? null,
                    'payload_hash' => $hash,
                    'supersedes_assignment_id' => $current?->getKey(),
                ],
            ]);
            Log::info('bid.assignment.accepted', [
                'employee_profile_id' => $employee->getKey(),
                'bid_year' => $payload['bid_year'],
                'source_sequence' => $payload['source_sequence'] ?? null,
                'payload_hash' => $hash,
                'supersedes_assignment_id' => $current?->getKey(),
            ]);

            return true;
        }, 3);
    }

    private function reject(string $field, string $message): never
    {
        // Never return 409 for a conflict: the Bid consumer treats it as synced.
        throw ValidationException::withMessages([$field => $message]);
    }
}
