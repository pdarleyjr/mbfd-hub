<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Jobs\SendMemberPasswordLink;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one place that decides which sign-in email an Employee ID should get.
 *
 * A member who has never set a password gets the existing setup invitation; a
 * member with an established account gets the existing password reset link.
 * Both go only to the address already on record, never to one typed by the
 * requester, and the requester is never told which (if either) was sent.
 * Nothing here grants a role or permission: it only proves mailbox control,
 * which is the same assurance normal password reset already relies on.
 */
final class MemberAccessEmail
{
    public const SETUP_READY = 'setup_ready';

    public const RESET_READY = 'reset_ready';

    /** Per account: three self-service sends a day bounds what a stranger can trigger. */
    private const ACCOUNT_LIMIT = 3;

    /** Across all accounts: leaves the shared email budget for administrators. */
    private const GLOBAL_LIMIT = 60;

    private const MESSAGES = [
        self::SETUP_READY => 'Has not finished setup. A setup link can be emailed to :email.',
        self::RESET_READY => 'Can sign in. A password reset link can be emailed to :email.',
        'recently_sent' => 'A setup link was sent recently and is still valid. Ask the member to check Inbox and Junk.',
        'delivery_pending' => 'The last account email is still being confirmed by the mail service. Check Inbox and Junk before requesting another link.',
        'no_email_on_record' => 'Cannot send: there is no deliverable email on record. Add the member\'s City email first.',
        'email_not_approved' => 'Cannot send: confirm the City email against the approved employee directory before sending an account link.',
        'email_conflict' => 'Cannot send: another account uses the same City email. Resolve the duplicate first.',
        'setup_not_available' => 'Cannot send: this account is waiting for an administrator to finish provisioning it.',
        'no_login_account' => 'Cannot send: no login account is linked to this personnel record.',
        'identity_conflict' => 'Cannot send: this Employee ID is linked to more than one record. Resolve the duplicate first.',
        'not_on_active_roster' => 'Cannot send: this person is not on the active roster.',
        'account_disabled' => 'Cannot send: the login account is disabled. Enable it first.',
        'external_identity' => 'Uses MBFD Identity. Send recovery from "Send MBFD Identity activation / recovery".',
    ];

    public function __construct(
        private readonly MemberOnboardingInvitationService $invitations,
        private readonly CityEmailVerificationService $emails,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    /**
     * Why this person can or cannot receive a sign-in email, in plain words.
     *
     * @return array{status:string,message:string,can_send:bool,user:User|null,recipient:string|null}
     */
    public function diagnose(Employee $employee): array
    {
        [$status, $user, $recipient] = $this->evaluate($employee);

        return [
            'status' => $status,
            'message' => str_replace(':email', (string) $recipient, self::MESSAGES[$status]),
            'can_send' => in_array($status, [self::SETUP_READY, self::RESET_READY], true),
            'user' => $user,
            'recipient' => $recipient,
        ];
    }

    /**
     * Queue the right email for an Employee ID, if any. The returned status is
     * for logs, audits and administrators only; never show it to a requester.
     */
    public function request(string $employeeId, ?User $administrator = null): string
    {
        $employeeId = EmployeeIdentifier::normalize($employeeId);
        $employees = Employee::query()->where('employee_id', $employeeId)->limit(2)->get();
        if ($employees->count() !== 1 || $employees->first()->employee_id !== $employeeId) {
            $this->log($employeeId, $employees->isEmpty() ? 'unknown_employee_id' : 'identity_conflict', null);

            return $employees->isEmpty() ? 'unknown_employee_id' : 'identity_conflict';
        }
        $employee = $employees->first();
        [$status, $user] = $this->evaluate($employee);
        if (in_array($status, [self::SETUP_READY, self::RESET_READY], true) && $user instanceof User) {
            $status = $this->dispatch($status, $user, $employee, $administrator);
        }
        $this->log($employeeId, $status, $user);
        if ($user instanceof User) {
            $this->audit->record($administrator ?? $user, $user, 'member_access_email_requested',
                in_array($status, ['setup_queued', 'reset_queued'], true) ? 'allowed' : 'denied',
                $status,
                ['employee_profile_id' => $employee->id, 'origin' => $administrator === null ? 'self_service' : 'administrator'],
            );
        }

        return $status;
    }

    /** @return array{0:string,1:User|null,2:string|null} */
    private function evaluate(Employee $employee): array
    {
        if (Employee::query()->whereKeyNot($employee->id)->where('employee_id', $employee->employee_id)->exists()) {
            return ['identity_conflict', null, null];
        }
        if ($employee->roster_status !== 'active') {
            return ['not_on_active_roster', null, null];
        }
        $linked = User::query()->where('employee_profile_id', $employee->id)->limit(2)->get();
        if ($linked->isEmpty()) {
            return ['no_login_account', null, null];
        }
        $user = $linked->first();
        if ($linked->count() > 1 || $user->employee_id !== $employee->employee_id
            || User::query()->whereKeyNot($user->id)->where('employee_id', $employee->employee_id)->exists()) {
            return ['identity_conflict', null, null];
        }

        return match ($user->getRawOriginal('account_status')) {
            AccountStatus::PendingActivation->value => $this->setupState($employee, $user),
            AccountStatus::Active->value => $this->resetState($user),
            default => ['account_disabled', $user, null],
        };
    }

    /** @return array{0:string,1:User,2:string|null} */
    private function setupState(Employee $employee, User $user): array
    {
        $email = strtolower(trim((string) $employee->city_email));
        $status = match ($this->invitations->assess($employee)['status']) {
            'ready' => self::SETUP_READY,
            'already_invited' => 'recently_sent',
            'delivery_reconciliation_required' => 'delivery_pending',
            'missing_authoritative_city_email' => 'no_email_on_record',
            'unapproved_roster_binding' => 'email_not_approved',
            'email_conflict' => 'email_conflict',
            'not_pending_onboarding' => 'setup_not_available',
            default => 'identity_conflict',
        };

        return [$status, $user, $email === '' ? null : $email];
    }

    /** @return array{0:string,1:User,2:string|null} */
    private function resetState(User $user): array
    {
        if (config('identity.credential_authority') !== 'local') {
            return ['external_identity', $user, null];
        }
        if (! $user->isAuthenticationAllowed()) {
            return ['identity_conflict', $user, null];
        }
        $recipient = $this->emails->recoveryAddress($user);
        if ($recipient === null && $this->emails->connectedEmail($user) !== null) {
            return ['email_not_approved', $user, null];
        }

        if ($recipient !== null) {
            $latest = OutboundEmail::query()->where('source_type', 'password_reset')
                ->where('source_id', (string) $user->id)->orderByDesc('id')->first();
            if ($latest !== null
                && in_array($latest->status, ['pending', 'reserved', 'submitted', 'acceptance_unknown', 'unknown'], true)
                && ($latest->queued_at ?? $latest->created_at)?->addMinutes((int) config('auth.passwords.users.expire', 60))->isFuture()) {
                return ['delivery_pending', $user, $recipient];
            }
        }

        return $recipient === null ? ['no_email_on_record', $user, null] : [self::RESET_READY, $user, $recipient];
    }

    private function dispatch(string $status, User $user, Employee $employee, ?User $administrator): string
    {
        // Sending happens on the queue so every request takes the same time. The
        // invitation pipeline was built for a real queue and never runs inline.
        if ($status === self::SETUP_READY && config('queue.default') === 'sync') {
            return 'queue_unavailable';
        }
        $accountKey = 'member-access-email:account:'.$user->id;
        $globalKey = 'member-access-email:global';
        if ($administrator === null
            && (RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_LIMIT) || RateLimiter::tooManyAttempts($globalKey, self::GLOBAL_LIMIT))) {
            return 'throttled';
        }
        if ($administrator === null) {
            RateLimiter::hit($accountKey, 86400);
            RateLimiter::hit($globalKey, 3600);
        }

        if ($status === self::SETUP_READY) {
            IssueMemberOnboardingInvitation::dispatch($user->id, null, IssueMemberOnboardingInvitation::bindingHash(
                $user->id, $employee->employee_id, (string) $employee->city_email, $user->security_version,
            ));

            return 'setup_queued';
        }
        SendMemberPasswordLink::dispatch($user->id, $user->security_version);

        return 'reset_queued';
    }

    private function log(string $employeeId, string $status, ?User $user): void
    {
        Log::info('member_access_email_requested', [
            'status' => $status,
            'user_id' => $user?->id,
            'employee_id_fingerprint' => hash_hmac('sha256', strtolower($employeeId), (string) config('app.key')),
        ]);
    }
}
