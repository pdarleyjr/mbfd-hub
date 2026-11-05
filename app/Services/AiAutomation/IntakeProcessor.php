<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Models\AiAutomation\AiIntakeEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one ledger row into at most one member-visible AI Response. The master switch is checked when the
 * row is claimed, again after any model call, and finally under a row lock inside the transaction that
 * writes the reply, so nothing can post after OFF.
 */
final class IntakeProcessor
{
    public function __construct(
        private readonly AiAutomationSwitch $switch,
        private readonly DomainRegistry $domains,
        private readonly ResponseComposer $composer,
        private readonly AiReplyNotifier $notifier,
    ) {}

    /** @return string the row's state afterwards */
    public function process(int $intakeId): string
    {
        $intake = $this->claim($intakeId);
        if ($intake === null) {
            return (string) AiIntakeEvent::query()->whereKey($intakeId)->value('state');
        }

        try {
            return $this->run($intake);
        } catch (Throwable $exception) {
            return $this->fail($intake, $exception);
        }
    }

    private function run(AiIntakeEvent $intake): string
    {
        $epoch = (int) $intake->epoch;
        if (! $this->switch->allows($epoch)) {
            return $this->finish($intake, AiIntakeEvent::STATE_CANCELLED, ['last_error' => 'switched_off']);
        }

        $adapter = $this->domains->forSourceType((string) $intake->source_type);
        if ($adapter === null || ! $this->domains->domainEnabled($adapter->domain())) {
            return $this->finish($intake, AiIntakeEvent::STATE_SKIPPED, ['reason_codes' => ['domain_disabled']]);
        }

        $facts = $adapter->facts($intake);
        if (! $facts->eligible()) {
            return $this->finish($intake, AiIntakeEvent::STATE_SKIPPED, [
                'reason_codes' => [$facts->ineligibleReason ?? 'already_answered'],
            ]);
        }

        // A chatty conversation is the team's to answer, so a second automated reply soon after the first is
        // skipped. A new report that is high priority or a hazard is never hidden by that (finding F).
        if ($this->recentReplyInThread($intake, $facts->threadKey)) {
            $preview = $this->composer->compose($facts, CarbonImmutable::now(), false);
            if ($preview->riskTier === 'routine') {
                return $this->finish($intake, AiIntakeEvent::STATE_SKIPPED, [
                    'thread_key' => $facts->threadKey,
                    'reason_codes' => ['recent_ai_reply_in_thread'],
                ]);
            }
        }

        $allowModel = $intake->origin === AiIntakeEvent::ORIGIN_LIVE;
        $reply = $this->composer->compose($facts, CarbonImmutable::now(), $allowModel);

        // A model call can take seconds; the switch may have gone OFF meanwhile.
        if (! $this->switch->allows($epoch)) {
            return $this->finish($intake, AiIntakeEvent::STATE_CANCELLED, [
                'last_error' => 'switched_off',
                'model_call_id' => $reply->modelCallId,
            ]);
        }

        $state = DB::transaction(function () use ($intake, $adapter, $epoch, $reply): string {
            if (! $this->switch->allowsUnderLock($epoch)) {
                return $this->finish($intake, AiIntakeEvent::STATE_CANCELLED, [
                    'last_error' => 'switched_off',
                    'model_call_id' => $reply->modelCallId,
                ]);
            }

            $fresh = $adapter->facts($intake);
            if (! $fresh->eligible()) {
                return $this->finish($intake, AiIntakeEvent::STATE_SKIPPED, [
                    'reason_codes' => [$fresh->ineligibleReason ?? 'already_answered'],
                    'model_call_id' => $reply->modelCallId,
                ]);
            }

            $record = $adapter->writeReply($intake, $fresh, $reply->text);

            return $this->finish($intake, AiIntakeEvent::STATE_SENT, [
                'thread_key' => $fresh->threadKey,
                'decision' => $reply->decision,
                'risk_tier' => $reply->riskTier,
                'needs_human' => $reply->needsHuman,
                'template_key' => $reply->templateKey,
                'template_version' => $reply->templateVersion,
                'reply_text' => $reply->text,
                'reason_codes' => $reply->reasonCodes,
                'knowledge_refs' => $reply->knowledgeRefs,
                'model_call_id' => $reply->modelCallId,
                'reply_record_type' => $record->type,
                'reply_record_id' => $record->id,
            ]);
        });

        if ($state === AiIntakeEvent::STATE_SENT && $intake->origin === AiIntakeEvent::ORIGIN_LIVE) {
            // In-app only, after commit. Historical backlog replies are visible in the thread without a ping.
            $this->notifier->notify($intake->refresh(), $adapter, $facts);
        }

        return $state;
    }

    /** At most one routine automated reply per conversation within the cooldown window (default two hours). */
    private function recentReplyInThread(AiIntakeEvent $intake, ?string $threadKey): bool
    {
        if ($threadKey === null || $threadKey === '') {
            return false;
        }

        return AiIntakeEvent::query()
            ->whereKeyNot($intake->getKey())
            ->where('thread_key', $threadKey)
            ->where('state', AiIntakeEvent::STATE_SENT)
            ->where('processed_at', '>=', now()->subMinutes((int) config('ai_automation.processing.thread_cooldown_minutes', 120)))
            ->exists();
    }

    private function claim(int $intakeId): ?AiIntakeEvent
    {
        $lease = (int) config('ai_automation.processing.lease_seconds', 120);
        $now = now();

        $claimed = AiIntakeEvent::query()
            ->whereKey($intakeId)
            ->where(function ($query) use ($now): void {
                $query->whereIn('state', [AiIntakeEvent::STATE_QUEUED, AiIntakeEvent::STATE_RETRY])
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('state', AiIntakeEvent::STATE_CLAIMED)->where('lease_expires_at', '<', $now);
                    });
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->update([
                'state' => AiIntakeEvent::STATE_CLAIMED,
                'lease_expires_at' => $now->copy()->addSeconds($lease),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? AiIntakeEvent::query()->find($intakeId) : null;
    }

    /** @param  array<string, mixed>  $attributes */
    private function finish(AiIntakeEvent $intake, string $state, array $attributes = []): string
    {
        $intake->forceFill([
            ...$attributes,
            'state' => $state,
            'lease_expires_at' => null,
            'next_attempt_at' => null,
            'processed_at' => now(),
        ])->save();

        return $state;
    }

    private function fail(AiIntakeEvent $intake, Throwable $exception): string
    {
        Log::warning('AI automation intake failed.', [
            'intake_id' => $intake->getKey(),
            'source_type' => $intake->source_type,
            'error' => $exception::class,
        ]);

        $intake->refresh();
        if (! in_array($intake->state, AiIntakeEvent::OPEN_STATES, true)) {
            return (string) $intake->state; // OFF already cancelled it
        }

        $max = (int) config('ai_automation.processing.max_attempts', 3);
        $exhausted = (int) $intake->attempts >= $max;

        $intake->forceFill([
            'state' => $exhausted ? AiIntakeEvent::STATE_FAILED : AiIntakeEvent::STATE_RETRY,
            'last_error' => mb_substr(class_basename($exception), 0, 160),
            'lease_expires_at' => null,
            'next_attempt_at' => $exhausted ? null : now()->addSeconds((int) config('ai_automation.processing.retry_delay_seconds', 60)),
            'processed_at' => $exhausted ? now() : null,
        ])->save();

        return (string) $intake->state;
    }
}
