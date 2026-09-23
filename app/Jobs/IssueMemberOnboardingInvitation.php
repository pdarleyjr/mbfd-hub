<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IssueMemberOnboardingInvitation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $userId,
        public readonly int $initiatorId,
        public readonly string $expectedBindingHash,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(MemberOnboardingInvitationService $invitations): void
    {
        $initiator = User::query()->find($this->initiatorId);
        $user = User::query()->with('employeeProfile')->find($this->userId);
        $employee = $user?->employeeProfile;
        if (! $initiator?->isAuthenticationAllowed() || ! $initiator->hasRole('super_admin') || $employee === null) {
            return;
        }
        $bindingHash = self::bindingHash($user->id, $employee->employee_id, (string) $employee->city_email, $user->security_version);
        if (! hash_equals($this->expectedBindingHash, $bindingHash)
            || $invitations->assess($employee)['status'] !== 'ready') {
            return;
        }

        $invitations->issue($user, CarbonImmutable::now(), $initiator);
    }

    public static function bindingHash(int $userId, string $employeeId, string $email, int $securityVersion): string
    {
        return hash('sha256', implode("\0", [$userId, $employeeId, strtolower(trim($email)), $securityVersion]));
    }
}
