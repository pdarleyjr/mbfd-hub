<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Identity\CityEmailVerificationService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use LogicException;
use Throwable;

/**
 * Emails an established account a single-use password link from the standard
 * password broker (hashed, expiring, deleted on use). The address is re-read
 * from the record at send time; the requester never supplies it.
 */
final class SendMemberPasswordLink implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 60;

    public int $uniqueFor = 172800;

    private ?CarbonImmutable $queuedAt = null;

    private ?int $lastOutboundEmailIdAtDispatch = null;

    public function __construct(
        public readonly int $userId,
        public readonly int $expectedSecurityVersion,
    ) {
        $this->onQueue('notifications');
        $this->queuedAt = CarbonImmutable::now();
        $this->lastOutboundEmailIdAtDispatch = (int) $this->deliveries()->max('id');
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->initializeDispatchState()->addDays(2);
    }

    public function middleware(): array
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($this->retryUntil())
            ? [] : [new RateLimited('member-onboarding-invitations')];
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CityEmailVerificationService $emails, CloudflareEmailDispatcher $dispatcher): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($this->retryUntil())) {
            return;
        }
        $challenge = DB::transaction(function () use ($emails): ?array {
            $user = User::query()->lockForUpdate()->find($this->userId);
            if (config('identity.credential_authority') !== 'local'
                || ! $user instanceof User || ! $user->isAuthenticationAllowed()
                || $user->security_version !== $this->expectedSecurityVersion) {
                return null;
            }
            $latest = $this->deliveries()->orderByDesc('id')->first();
            // Never replace a possibly delivered link when a worker retries, or
            // while an earlier provider submission still needs reconciliation.
            if ($latest !== null && (($latest->id > $this->lastOutboundEmailIdAtDispatch && $latest->status !== 'failed_pre_acceptance')
                || (in_array($latest->status, ['pending', 'reserved', 'submitted', 'acceptance_unknown', 'unknown'], true)
                    && ($latest->queued_at ?? $latest->created_at)->copy()->addMinutes((int) config('auth.passwords.users.expire', 60))->isFuture()))) {
                return null;
            }
            $recipient = $emails->recoveryAddress($user);
            // The broker's own throttle stops back-to-back links for one account.
            if ($recipient === null || $this->broker()->getRepository()->recentlyCreatedToken($user)) {
                return null;
            }

            return [
                'recipient' => $recipient,
                'employee_id' => (string) $user->employee_id,
                'reset_email' => $user->getEmailForPasswordReset(),
                'first_time' => $user->last_login_at === null,
                'token' => $this->broker()->createToken($user),
            ];
        });
        if ($challenge === null) {
            Log::info('member_password_link_skipped', ['user_id' => $this->userId]);

            return;
        }

        $url = route('password.reset.form', ['token' => $challenge['token'], 'employee_id' => $challenge['employee_id']]);
        $minutes = (int) config('auth.passwords.users.expire', 60);
        [$subject, $intro] = $challenge['first_time']
            ? ['Set up your MBFD Hub password', 'Use this private link to choose your MBFD Hub password and sign in for the first time.']
            : ['MBFD Hub password reset', 'A password reset was requested for your MBFD Hub account.'];
        try {
            $delivery = $dispatcher->send(
                to: [$challenge['recipient']],
                subject: $subject,
                text: "{$intro}\n\nChoose your password: {$url}\n\nThis link works once and expires in {$minutes} minutes. Your Employee ID is your username.\n\nIf you did not ask for this, ignore this message; your account has not changed.",
                html: null,
                sourceType: 'password_reset',
                sourceId: (string) $this->userId,
            );
            if ($this->deliveryFailed($delivery)) {
                $this->discardChallenge($challenge);
            }
        } catch (EmailBudgetExhausted $exception) {
            // The dispatcher proves this failure happened before submission.
            // Start the token's lifetime again only when sending can resume.
            if ($this->discardChallenge($challenge) && $exception->retryAfterSeconds !== null) {
                $this->release($exception->retryAfterSeconds);
            }
        } catch (Throwable $exception) {
            $delivery = $this->deliveries()->where('id', '>', $this->lastOutboundEmailIdAtDispatch)->orderByDesc('id')->first();
            if ($delivery === null || $this->deliveryFailed($delivery)
                || ($delivery->submitted_at === null && $delivery->accepted_at === null)) {
                $this->discardChallenge($challenge);
            }
            // A lost provider response can still result in delivery. Preserve
            // that link and its ledger evidence; do not automatically resend.
            Log::warning('member_password_link_delivery_failed', [
                'user_id' => $this->userId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function initializeDispatchState(): CarbonImmutable
    {
        // Jobs already queued before these fields existed retain their original
        // envelope timestamp, so retries cannot extend their lifetime or resend.
        $queuedAt = $this->queuedAt ??= CarbonImmutable::createFromTimestampUTC(
            (int) ($this->job?->payload()['createdAt'] ?? CarbonImmutable::now()->getTimestamp()),
        );
        $this->lastOutboundEmailIdAtDispatch ??= (int) $this->deliveries()->where('created_at', '<', $queuedAt)->max('id');

        return $queuedAt;
    }

    /** @return Builder<OutboundEmail> */
    private function deliveries(): Builder
    {
        return OutboundEmail::query()->where('source_type', 'password_reset')->where('source_id', (string) $this->userId);
    }

    private function deliveryFailed(OutboundEmail $delivery): bool
    {
        return in_array($delivery->status, ['failed', 'failed_pre_acceptance', 'blocked', 'bounced', 'rejected', 'complained', 'accepted_with_delivery_issues'], true);
    }

    /** @param array{recipient:string,employee_id:string,reset_email:string,first_time:bool,token:string} $challenge */
    private function discardChallenge(array $challenge): bool
    {
        return DB::transaction(function () use ($challenge): bool {
            $user = User::query()->lockForUpdate()->find($this->userId);
            if (! $user instanceof User || $user->security_version !== $this->expectedSecurityVersion
                || $user->employee_id !== $challenge['employee_id'] || $user->getEmailForPasswordReset() !== $challenge['reset_email']
                || ! $this->broker()->tokenExists($user, $challenge['token'])) {
                return false;
            }
            $this->broker()->deleteToken($user);

            return true;
        });
    }

    private function broker(): PasswordBroker
    {
        $broker = Password::broker();
        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('The configured password broker does not support token persistence.');
        }

        return $broker;
    }
}
