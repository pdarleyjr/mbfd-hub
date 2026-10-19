<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Identity\CityEmailVerificationService;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $userId,
        public readonly int $expectedSecurityVersion,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CityEmailVerificationService $emails, CloudflareEmailDispatcher $dispatcher): void
    {
        $challenge = DB::transaction(function () use ($emails): ?array {
            $user = User::query()->lockForUpdate()->find($this->userId);
            if (! $user instanceof User || ! $user->isAuthenticationAllowed()
                || $user->security_version !== $this->expectedSecurityVersion) {
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
            $dispatcher->send(
                to: [$challenge['recipient']],
                subject: $subject,
                text: "{$intro}\n\nChoose your password: {$url}\n\nThis link works once and expires in {$minutes} minutes. Your Employee ID is your username.\n\nIf you did not ask for this, ignore this message; your account has not changed.",
                html: null,
                sourceType: 'password_reset',
                sourceId: (string) $this->userId,
            );
        } catch (Throwable $exception) {
            // An unsent link must not stay usable.
            $user = User::query()->find($this->userId);
            if ($user instanceof User) {
                $this->broker()->deleteToken($user);
            }
            Log::warning('member_password_link_delivery_failed', [
                'user_id' => $this->userId,
                'exception_class' => $exception::class,
            ]);
        }
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
