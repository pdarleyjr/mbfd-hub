<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the real owner an account was activated, so a claim made by someone else
 * is noticed. Queued so a temporary email block can neither lose this notice nor
 * slow the activation. The address is the one held at activation: whoever claimed
 * the account must not be able to redirect the notice by changing it afterwards.
 */
final class SendMemberActivationNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Outlasts about 6 hours of the 5-minute pause after a failed usage poll. */
    public int $tries = 72;

    public int $backoff = 60;

    public int $timeout = 60;

    private readonly int $lastOutboundEmailIdAtDispatch;

    public function __construct(
        public readonly int $userId,
        public readonly string $address,
        public readonly CarbonImmutable $activatedAt,
    ) {
        $this->onQueue('notifications');
        $this->lastOutboundEmailIdAtDispatch = (int) OutboundEmail::query()->where('source_type', 'member_self_service_activation')
            ->where('source_id', (string) $userId)->max('id');
    }

    public function handle(CloudflareEmailDispatcher $email): void
    {
        if ($this->mayHaveReachedProvider()) {
            return;
        }
        $when = $this->activatedAt->setTimezone(HubTimezone::NAME)->format('F j, Y \a\t g:i A T');
        try {
            $email->send(
                to: [$this->address],
                subject: 'Your MBFD Hub account was activated',
                text: "Your MBFD Hub account was activated and a password was set on {$when}.\n\nIf this was you, no action is needed.\n\nIf this was NOT you, contact MBFD Hub support right away so the account can be secured.",
                html: null,
                sourceType: 'member_self_service_activation',
                sourceId: (string) $this->userId,
                actor: User::query()->find($this->userId),
            );
        } catch (EmailBudgetExhausted $exception) {
            // No retry time means the block will not clear by waiting (the safety ceiling).
            if ($exception->retryAfterSeconds === null) {
                $this->fail($exception);

                return;
            }
            Log::info('member_self_service_activation_notice_deferred', [
                'user_id' => $this->userId,
                'retry_after_seconds' => $exception->retryAfterSeconds,
            ]);
            $this->release($exception->retryAfterSeconds);
        } catch (Throwable $exception) {
            Log::warning('member_self_service_activation_notice_failed', [
                'user_id' => $this->userId,
                'exception_class' => $exception::class,
            ]);
            if ($this->mayHaveReachedProvider()) {
                $this->fail('The activation notice may be undelivered and cannot safely be sent again.');

                return;
            }
            $this->release($this->backoff);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('member_self_service_activation_notice_abandoned', [
            'user_id' => $this->userId,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }

    /**
     * Every status except this one may have reached the provider, including a lost
     * response. Only a refusal before submission can safely be sent again.
     *
     * @phpstan-impure
     */
    private function mayHaveReachedProvider(): bool
    {
        return OutboundEmail::query()->where('source_type', 'member_self_service_activation')->where('source_id', (string) $this->userId)
            ->where('id', '>', $this->lastOutboundEmailIdAtDispatch)
            ->where('status', '!=', 'failed_pre_acceptance')->exists();
    }
}
