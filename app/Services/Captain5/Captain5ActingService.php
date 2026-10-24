<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\Command300Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Acting as Captain 5 today?" and "Acting as 300 today?" (docs/captain5-ems-operations-center.md
 * sections 11 and 12): one engine for both shift roles.
 *
 * A member asks to be shown as the shift's acting Captain 5 or acting 300 for 12 hours, 24 hours or
 * until a custom time, or asks to end that role's current acting display. A Captain 5 administrator
 * approves or rejects. Approval only changes the name shown for that role (and, for Captain 5, who
 * the shift's Captain 5 notifications go to); it never grants or limits any action, and the display
 * ends on its own at the chosen time. The two roles never affect each other.
 *
 * Who may ask: Captain 5 = anyone who can use the Captain 5 page (Captain5Access); 300 = anyone
 * Command300Access allows (Captains, Division Chiefs, the 300 seat, administrators and explicit 300
 * grants). Who approves: ActingApprovers (Captain 5 = Captain 5 administrators; 300 = any
 * administrator); nobody approves their own request except a super admin.
 */
final class Captain5ActingService
{
    /** Shortest and longest custom acting period. */
    private const CUSTOM_MIN_MINUTES = 15;

    private const CUSTOM_MAX_HOURS = 72;

    public function __construct(
        private readonly Captain5Access $access,
        private readonly Command300Access $command300,
        private readonly DutyCalendar $calendar,
        private readonly Captain5Notifier $notifier,
        private readonly Captain5IssuePresenter $presenter,
        private readonly ActingApprovers $approvers,
    ) {}

    /** May this member ask to be shown as acting in this role? */
    public function canRequest(?User $user, string $role): bool
    {
        return match ($role) {
            Captain5ActingRequest::ROLE_CAPTAIN_5 => $this->access->decide($user)->canViewData,
            Captain5ActingRequest::ROLE_COMMAND_300 => $this->command300->allows($user),
            default => false,
        };
    }

    /**
     * @param  array{role?: mixed, kind?: mixed, duration?: mixed, ends_at?: mixed, client_request_id?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function request(User $requester, array $data): Captain5ActingRequest
    {
        $role = (string) ($data['role'] ?? Captain5ActingRequest::ROLE_CAPTAIN_5);
        if (! in_array($role, Captain5ActingRequest::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Choose Captain 5 or 300.']);
        }
        $label = Captain5ActingRequest::roleLabel($role);
        if (! $this->canRequest($requester, $role)) {
            throw new AuthorizationException($role === Captain5ActingRequest::ROLE_COMMAND_300
                ? 'Only members who can use the 300 page can ask to act as 300.'
                : 'Only members who can use the Captain 5 page can ask to act as Captain 5.');
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
            'role' => $role,
            'requested_by_user_id' => $requester->getKey(),
            'shift_letter' => $this->calendar->shiftFor($this->calendar->dutyDate($now)),
            'status' => Captain5ActingRequest::PENDING,
            'client_request_id' => $valid['client_request_id'] ?? null,
        ];
        if ($valid['kind'] === Captain5ActingRequest::KIND_ACT) {
            $employee = $this->employeeOf($requester, $label);
            $attributes['employee_profile_id'] = $employee->getKey();
            $attributes['duration'] = $valid['duration'];
            $attributes['ends_at'] = $this->endsAt((string) $valid['duration'], $valid['ends_at'] ?? null, $now);
        } elseif (Captain5ActingRequest::query()->showingAt($now, $role)->doesntExist()) {
            throw ValidationException::withMessages(['kind' => "No acting {$label} is shown right now, so there is nothing to end."]);
        }

        try {
            $request = DB::transaction(function () use ($requester, $attributes, $role): Captain5ActingRequest {
                // One open request per member and role: a new one replaces their earlier pending request.
                Captain5ActingRequest::query()->pending()->forRole($role)->where('requested_by_user_id', $requester->getKey())
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

    /**
     * @param  string|null  $role  when given (from the page's route), the request must be of this role
     *
     * @throws AuthorizationException|ValidationException
     */
    public function approve(User $admin, int $requestId, ?string $note = null, ?string $role = null): Captain5ActingRequest
    {
        $this->authorizeApprover($admin, $role);

        $request = DB::transaction(function () use ($admin, $requestId, $note, $role): Captain5ActingRequest {
            $request = $this->locked($requestId, $role);
            $this->authorizeApprover($admin, $request->role);
            $this->assertDecidable($admin, $request);
            $label = Captain5ActingRequest::roleLabel($request->role);
            $now = CarbonImmutable::now();
            $showing = Captain5ActingRequest::query()->showingAt($now, $request->role)->lockForUpdate()->get();

            if ($request->kind === Captain5ActingRequest::KIND_ACT) {
                if ($request->ends_at === null || $request->ends_at->lessThanOrEqualTo($now)) {
                    throw ValidationException::withMessages(['request' => 'This request has already passed its end time. Ask the member to request again.']);
                }
                $request->forceFill(['starts_at' => $now]);
            } elseif ($showing->isEmpty()) {
                throw ValidationException::withMessages(['request' => "No acting {$label} is shown any more, so there is nothing to end."]);
            }

            // Approving takes over (act) or removes (end) whoever is shown now in the same role.
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
    public function reject(User $admin, int $requestId, string $note, ?string $role = null): Captain5ActingRequest
    {
        $this->authorizeApprover($admin, $role);
        $note = $this->note($note) ?? throw ValidationException::withMessages(['note' => 'Say why the request is not approved.']);

        $request = DB::transaction(function () use ($admin, $requestId, $note, $role): Captain5ActingRequest {
            $request = $this->locked($requestId, $role);
            $this->authorizeApprover($admin, $request->role);
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
    public function withdraw(User $requester, int $requestId, ?string $role = null): Captain5ActingRequest
    {
        return DB::transaction(function () use ($requester, $requestId, $role): Captain5ActingRequest {
            $request = $this->locked($requestId, $role);
            if ((int) $request->requested_by_user_id !== (int) $requester->getKey()) {
                throw new AuthorizationException('Only the person who asked can withdraw this request.');
            }
            if ($request->status === Captain5ActingRequest::PENDING) {
                $request->forceFill(['status' => Captain5ActingRequest::WITHDRAWN, 'decided_at' => now()])->save();
            }

            return $request;
        }, 3);
    }

    /**
     * The acting block a page shows for one role: the viewer's own pending request, the pending queue
     * (approvers only) and the end times the 12 / 24 hour choices would have now.
     *
     * @return array{role: string, can_request: bool, can_approve: bool, mine: array<string, mixed>|null, pending: list<array<string, mixed>>, choices: array{'12h': string|null, '24h': string|null}}
     */
    public function state(User $viewer, string $role): array
    {
        $pending = Captain5ActingRequest::query()->pending()->forRole($role)
            ->with(['requester:id,name,display_name', 'employee:id,name,display_name,rank'])
            ->orderBy('id')->limit(50)->get();
        $canApprove = $this->approvers->can($viewer, $role);
        $mine = $pending->first(fn (Captain5ActingRequest $r): bool => (int) $r->requested_by_user_id === (int) $viewer->getKey());

        return [
            'role' => $role,
            'can_request' => $this->canRequest($viewer, $role),
            'can_approve' => $canApprove,
            'mine' => $mine instanceof Captain5ActingRequest ? $this->present($mine, $viewer) : null,
            'pending' => $canApprove ? $pending->map(fn (Captain5ActingRequest $r): array => $this->present($r, $viewer))->values()->all() : [],
            'choices' => [
                '12h' => $this->presenter->iso($this->calendar->actingEnd('12h')),
                '24h' => $this->presenter->iso($this->calendar->actingEnd('24h')),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Captain5ActingRequest $request, User $viewer): array
    {
        $requester = $request->requester;

        return [
            'id' => (int) $request->getKey(),
            'role' => $request->role,
            'role_label' => Captain5ActingRequest::roleLabel($request->role),
            'kind' => $request->kind,
            'requested_by' => $requester instanceof User ? $this->presenter->name($requester) : 'A member',
            'name' => $request->employee !== null ? trim((string) ($request->employee->display_name ?: $request->employee->name)) : null,
            'rank' => $request->employee?->rank,
            'duration' => $request->duration,
            'shift' => $request->shift_letter,
            'ends_at' => $this->presenter->iso($request->ends_at),
            'requested_at' => $this->presenter->iso($request->created_at),
            'own' => (int) $request->requested_by_user_id === (int) $viewer->getKey(),
        ];
    }

    /** Lock one request; a role from the page's route must match, so one page never decides the other's requests. */
    private function locked(int $requestId, ?string $role): Captain5ActingRequest
    {
        $query = Captain5ActingRequest::query()->lockForUpdate();
        if ($role !== null) {
            $query->forRole($role);
        }
        $request = $query->find($requestId);

        return $request instanceof Captain5ActingRequest ? $request : throw (new ModelNotFoundException)->setModel(Captain5ActingRequest::class, [$requestId]);
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

    private function employeeOf(User $user, string $label): Employee
    {
        $employee = $user->employee_profile_id !== null ? Employee::query()->find($user->employee_profile_id) : null;
        if (! $employee instanceof Employee || $employee->roster_status === 'departed') {
            throw ValidationException::withMessages(['kind' => "Your account is not linked to a current personnel profile, so your name cannot be shown as {$label}."]);
        }

        return $employee;
    }

    /** With no role yet (an admin queue decision before the row is read), approving some role is enough to proceed. */
    private function authorizeApprover(User $admin, ?string $role): void
    {
        $allowed = $role === null ? $this->approvers->canApproveAny($admin) : $this->approvers->can($admin, $role);
        if (! $allowed) {
            throw new AuthorizationException($role === Captain5ActingRequest::ROLE_COMMAND_300
                ? 'Only administrators approve acting 300 requests.'
                : 'Only Captain 5 administrators approve acting Captain 5 requests.');
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
