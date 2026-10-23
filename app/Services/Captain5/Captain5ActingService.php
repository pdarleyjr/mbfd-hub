<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Acting as Captain 5 today?" (docs/captain5-ems-operations-center.md section 11).
 *
 * A member who can use the Captain 5 page asks to be shown as the shift's acting Captain 5 for
 * 12 hours, 24 hours or until a custom time, or asks to end the current acting display. A Captain 5
 * administrator approves or rejects. Approval only changes the name shown at the top of the page
 * (and who the shift's Captain 5 notifications go to); it never grants or limits any action, and the
 * display ends on its own at the chosen time.
 */
final class Captain5ActingService
{
    /** Shortest and longest custom acting period. */
    private const CUSTOM_MIN_MINUTES = 15;

    private const CUSTOM_MAX_HOURS = 72;

    public function __construct(
        private readonly Captain5Access $access,
        private readonly DutyCalendar $calendar,
        private readonly Captain5Notifier $notifier,
    ) {}

    /**
     * @param  array{kind?: mixed, duration?: mixed, ends_at?: mixed, client_request_id?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function request(User $requester, array $data): Captain5ActingRequest
    {
        if (! $this->access->decide($requester)->canViewData) {
            throw new AuthorizationException('Only members who can use the Captain 5 page can ask to act as Captain 5.');
        }
        $valid = Validator::make($data, [
            'kind' => ['required', Rule::in([Captain5ActingRequest::KIND_ACT, Captain5ActingRequest::KIND_END])],
            'duration' => ['required_if:kind,act', 'nullable', Rule::in(Captain5ActingRequest::DURATIONS)],
            'ends_at' => ['required_if:duration,custom', 'nullable', 'date'],
            'client_request_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/D'],
        ])->validate();

        $existing = $this->existing($requester, $valid['client_request_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        $now = CarbonImmutable::now();
        $attributes = [
            'kind' => $valid['kind'],
            'requested_by_user_id' => $requester->getKey(),
            'shift_letter' => $this->calendar->shiftFor($this->calendar->dutyDate($now)),
            'status' => Captain5ActingRequest::PENDING,
            'client_request_id' => $valid['client_request_id'] ?? null,
        ];
        if ($valid['kind'] === Captain5ActingRequest::KIND_ACT) {
            $employee = $this->employeeOf($requester);
            $attributes['employee_profile_id'] = $employee->getKey();
            $attributes['duration'] = $valid['duration'];
            $attributes['ends_at'] = $this->endsAt((string) $valid['duration'], $valid['ends_at'] ?? null, $now);
        } elseif (Captain5ActingRequest::query()->showingAt($now)->doesntExist()) {
            throw ValidationException::withMessages(['kind' => 'No acting Captain 5 is shown right now, so there is nothing to end.']);
        }

        try {
            $request = DB::transaction(function () use ($requester, $attributes): Captain5ActingRequest {
                // One open request per member: a new one replaces their earlier pending request.
                Captain5ActingRequest::query()->pending()->where('requested_by_user_id', $requester->getKey())
                    ->lockForUpdate()->get()
                    ->each(fn (Captain5ActingRequest $old) => $old->forceFill(['status' => Captain5ActingRequest::WITHDRAWN, 'decided_at' => now()])->save());

                return Captain5ActingRequest::query()->create($attributes);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($requester, $attributes['client_request_id']) ?? throw $exception;
        }

        $this->notifier->actingRequested($request);

        return $request;
    }

    /** @throws AuthorizationException|ValidationException */
    public function approve(User $admin, int $requestId, ?string $note = null): Captain5ActingRequest
    {
        $this->authorizeApprover($admin);

        $request = DB::transaction(function () use ($admin, $requestId, $note): Captain5ActingRequest {
            $request = Captain5ActingRequest::query()->lockForUpdate()->findOrFail($requestId);
            $this->assertDecidable($admin, $request);
            $now = CarbonImmutable::now();
            $showing = Captain5ActingRequest::query()->showingAt($now)->lockForUpdate()->get();

            if ($request->kind === Captain5ActingRequest::KIND_ACT) {
                if ($request->ends_at === null || $request->ends_at->lessThanOrEqualTo($now)) {
                    throw ValidationException::withMessages(['request' => 'This request has already passed its end time. Ask the member to request again.']);
                }
                $request->forceFill(['starts_at' => $now]);
            } elseif ($showing->isEmpty()) {
                throw ValidationException::withMessages(['request' => 'No acting Captain 5 is shown any more, so there is nothing to end.']);
            }

            // Approving takes over (act) or removes (end) whoever is shown now.
            $showing->reject(fn (Captain5ActingRequest $current): bool => $current->is($request))
                ->each(fn (Captain5ActingRequest $current) => $current->forceFill(['ended_at' => $now, 'ended_by_request_id' => $request->getKey()])->save());

            $request->forceFill([
                'status' => Captain5ActingRequest::APPROVED,
                'decided_by_user_id' => $admin->getKey(),
                'decided_at' => $now,
                'decision_note' => $this->note($note),
            ])->save();

            return $request;
        }, 3);

        $this->access->flush();
        $this->notifier->actingDecided($request);

        return $request;
    }

    /** @throws AuthorizationException|ValidationException */
    public function reject(User $admin, int $requestId, string $note): Captain5ActingRequest
    {
        $this->authorizeApprover($admin);
        $note = $this->note($note) ?? throw ValidationException::withMessages(['note' => 'Say why the request is not approved.']);

        $request = DB::transaction(function () use ($admin, $requestId, $note): Captain5ActingRequest {
            $request = Captain5ActingRequest::query()->lockForUpdate()->findOrFail($requestId);
            $this->assertDecidable($admin, $request);
            $request->forceFill([
                'status' => Captain5ActingRequest::REJECTED,
                'decided_by_user_id' => $admin->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $request;
        }, 3);

        $this->notifier->actingDecided($request);

        return $request;
    }

    /** The requester takes back a request that is still pending. */
    public function withdraw(User $requester, int $requestId): Captain5ActingRequest
    {
        return DB::transaction(function () use ($requester, $requestId): Captain5ActingRequest {
            $request = Captain5ActingRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ((int) $request->requested_by_user_id !== (int) $requester->getKey()) {
                throw new AuthorizationException('Only the person who asked can withdraw this request.');
            }
            if ($request->status === Captain5ActingRequest::PENDING) {
                $request->forceFill(['status' => Captain5ActingRequest::WITHDRAWN, 'decided_at' => now()])->save();
            }

            return $request;
        }, 3);
    }

    private function endsAt(string $duration, mixed $customEndsAt, CarbonImmutable $now): CarbonImmutable
    {
        if ($duration !== 'custom') {
            return $this->calendar->actingEnd($duration, $now);
        }
        $ends = CarbonImmutable::parse((string) $customEndsAt, $this->calendar->timezone())->utc();
        if ($ends->lessThan($now->addMinutes(self::CUSTOM_MIN_MINUTES)) || $ends->greaterThan($now->addHours(self::CUSTOM_MAX_HOURS))) {
            throw ValidationException::withMessages(['ends_at' => 'Choose an end time between 15 minutes and 72 hours from now.']);
        }

        return $ends;
    }

    private function employeeOf(User $user): Employee
    {
        $employee = $user->employee_profile_id !== null ? Employee::query()->find($user->employee_profile_id) : null;
        if (! $employee instanceof Employee || $employee->roster_status === 'departed') {
            throw ValidationException::withMessages(['kind' => 'Your account is not linked to a current personnel profile, so your name cannot be shown as Captain 5.']);
        }

        return $employee;
    }

    private function authorizeApprover(User $admin): void
    {
        if (! $this->access->canConfigure($admin)) {
            throw new AuthorizationException('Only Captain 5 administrators approve acting Captain 5 requests.');
        }
    }

    private function assertDecidable(User $admin, Captain5ActingRequest $request): void
    {
        if ($request->status !== Captain5ActingRequest::PENDING) {
            throw ValidationException::withMessages(['request' => 'This request was already decided or withdrawn.']);
        }
        if ((int) $request->requested_by_user_id === (int) $admin->getKey() && ! $admin->hasRole('super_admin')) {
            throw new AuthorizationException('Another administrator approves your own request.');
        }
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Keep the note under 500 characters.']);
        }

        return $note === '' ? null : $note;
    }

    private function existing(User $requester, ?string $clientRequestId): ?Captain5ActingRequest
    {
        if ($clientRequestId === null || $clientRequestId === '') {
            return null;
        }
        $request = Captain5ActingRequest::query()->where('client_request_id', $clientRequestId)->first();
        if ($request !== null && (int) $request->requested_by_user_id !== (int) $requester->getKey()) {
            throw new AuthorizationException('That request id belongs to another person.');
        }

        return $request;
    }
}
