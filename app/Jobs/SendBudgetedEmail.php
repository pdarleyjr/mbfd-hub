<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one finished email and keeps trying through a temporary block, so a short
 * pause in sending can neither lose the message nor slow the request that caused it.
 * The recipient is fixed by the caller: an account-security notice must reach the
 * address that was on record when the event happened, whatever is changed afterwards.
 *
 * Not for messages holding a one-time link: those are rebuilt on each try instead
 * (see SendMemberPasswordLink), and this job's payload is stored unencrypted.
 */
final class SendBudgetedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A recipient deleted while the email waited has nobody left to tell. */
    public bool $deleteWhenMissingModels = true;

    /** Outlasts about 6 hours of the 5-minute pause after failed usage polls. */
    public int $tries = 72;

    public int $backoff = 60;

    public int $timeout = 60;

    private readonly int $lastOutboundEmailIdAtDispatch;

    /**
     * A notification's recipient is checked again when the email is due: a preference
     * switched off or a role revoked while it waited stops it.
     *
     * @param  list<string>  $to
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly ?int $actorId = null,
        public readonly ?Notification $notification = null,
        public readonly ?Model $notifiable = null,
    ) {
        $this->onQueue('notifications');
        $this->lastOutboundEmailIdAtDispatch = (int) OutboundEmail::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->max('id');
    }

    /** Waiting here adds nothing to the Sent list, unlike a refusal from the dispatcher. */
    public function middleware(): array
    {
        return [new RateLimited('budgeted-email')];
    }

    public function handle(CloudflareEmailDispatcher $email): void
    {
        if ($this->mayHaveReachedProvider() || ! $this->stillWanted()) {
            return;
        }
        try {
            $email->send(
                to: $this->to,
                subject: $this->subject,
                text: $this->text,
                html: $this->html,
                sourceType: $this->sourceType,
                sourceId: $this->sourceId,
                actor: $this->actorId === null ? null : User::query()->find($this->actorId),
            );
        } catch (EmailBudgetExhausted $exception) {
            // No retry time means waiting will not clear the block (the safety ceiling).
            if ($exception->retryAfterSeconds === null) {
                $this->fail($exception);

                return;
            }
            Log::info('budgeted_email_deferred', $this->logContext() + ['retry_after_seconds' => $exception->retryAfterSeconds]);
            $this->release($exception->retryAfterSeconds);
        } catch (Throwable $exception) {
            Log::warning('budgeted_email_failed', $this->logContext() + ['exception_class' => $exception::class]);
            if ($this->mayHaveReachedProvider()) {
                $this->fail('The email may be undelivered and cannot safely be sent again.');

                return;
            }
            $this->release($this->backoff);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('budgeted_email_abandoned', $this->logContext() + [
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }

    private function stillWanted(): bool
    {
        return $this->notification === null || $this->notifiable === null
            || ! method_exists($this->notification, 'shouldSend')
            || $this->notification->shouldSend($this->notifiable, BudgetedMailChannel::class);
    }

    /**
     * Every status except this one may have reached the provider, including a lost
     * response. Only a refusal before acceptance can safely be sent again. Compared
     * per recipient: two changes of one member's address notify two different people.
     *
     * @phpstan-impure
     */
    private function mayHaveReachedProvider(): bool
    {
        $wanted = array_map(static fn (string $address): string => strtolower(trim($address)), $this->to);

        return OutboundEmail::query()->where('source_type', $this->sourceType)->where('source_id', $this->sourceId)
            ->where('id', '>', $this->lastOutboundEmailIdAtDispatch)
            ->where('status', '!=', 'failed_pre_acceptance')
            ->get(['to_recipients'])
            ->contains(static fn (OutboundEmail $delivery): bool => array_intersect($wanted, (array) $delivery->to_recipients) !== []);
    }

    /** @return array{source_type:string,source_id:string} */
    private function logContext(): array
    {
        return ['source_type' => $this->sourceType, 'source_id' => $this->sourceId];
    }
}
