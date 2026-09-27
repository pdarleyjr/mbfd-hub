<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\MemberOnboardingInvitation;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

final class IssueMemberOnboardingInvitation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SPACING_SECONDS = 16;

    public int $tries = 0;

    public int $timeout = 60;

    public int $uniqueFor = 172800;

    private readonly CarbonImmutable $queuedAt;

    private readonly int $lastOutboundEmailIdAtDispatch;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $initiatorId,
        public readonly string $expectedBindingHash,
    ) {
        $this->onQueue('notifications');
        $this->queuedAt = CarbonImmutable::now();
        $invitationId = MemberOnboardingInvitation::query()->where('user_id', $userId)->value('id');
        $this->lastOutboundEmailIdAtDispatch = $invitationId === null ? 0 : (int) OutboundEmail::query()
            ->where('source_type', 'member_onboarding_invitation')
            ->where('source_id', (string) $invitationId)
            ->max('id');
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->queuedAt->addDays(2);
    }

    public function middleware(): array
    {
        return [new RateLimited('member-onboarding-invitations')];
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(MemberOnboardingInvitationService $invitations): void
    {
        $initiator = $this->initiatorId === null ? null : User::query()->find($this->initiatorId);
        $user = User::query()->with('employeeProfile')->find($this->userId);
        $employee = $user?->employeeProfile;
        if ($employee === null || ($this->initiatorId !== null
            && (! $initiator?->isAuthenticationAllowed() || ! $initiator->hasRole('super_admin')))) {
            return;
        }
        $invitationId = MemberOnboardingInvitation::query()->where('user_id', $this->userId)->value('id');
        $newDelivery = $invitationId === null ? null : OutboundEmail::query()
            ->where('source_type', 'member_onboarding_invitation')
            ->where('source_id', (string) $invitationId)
            ->where('id', '>', $this->lastOutboundEmailIdAtDispatch)
            ->where('status', '!=', 'failed_pre_acceptance')
            ->orderByDesc('id')->first();
        if ($newDelivery !== null) {
            if ($newDelivery->submitted_at !== null) {
                MemberOnboardingInvitation::query()->whereKey($invitationId)
                    ->where('delivery_status', 'pending')->whereNull('outbound_email_id')
                    ->update(['delivery_status' => 'queued', 'sent_at' => $newDelivery->submitted_at, 'outbound_email_id' => $newDelivery->id]);
            }

            return;
        }
        $bindingHash = self::bindingHash($user->id, $employee->employee_id, (string) $employee->city_email, $user->security_version);
        if (! hash_equals($this->expectedBindingHash, $bindingHash)
            || $invitations->assess($employee)['status'] !== 'ready') {
            return;
        }

        try {
            $invitations->issue($user, CarbonImmutable::now(), $initiator);
        } catch (EmailBudgetExhausted $exception) {
            if ($exception->retryAfterSeconds !== null) {
                $this->release($exception->retryAfterSeconds);
            }
        }
    }

    public static function bindingHash(int $userId, string $employeeId, string $email, int $securityVersion): string
    {
        return hash('sha256', implode("\0", [$userId, $employeeId, strtolower(trim($email)), $securityVersion]));
    }
}
