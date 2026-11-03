<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Jobs\AiAutomation\PreviewAiBacklog;
use App\Jobs\AiAutomation\ProcessAiBacklogBatch;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\User;
use App\Services\AiAutomation\Contracts\DomainAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Older submissions that never got a response. Preview is read-only. Processing is explicit (an admin
 * button), throttled in small batches, template-only, one reply per parent submission, and it stops the
 * moment the master switch goes OFF. Turning the switch back ON never resumes it by itself.
 */
final class BacklogService
{
    private const PREVIEW_PAGE = 200;

    public function __construct(
        private readonly AiAutomationSwitch $switch,
        private readonly DomainRegistry $domains,
        private readonly ResponseComposer $composer,
        private readonly IntakeProcessor $processor,
    ) {}

    public function latest(): ?AiBacklogRun
    {
        return AiBacklogRun::query()->latest('id')->first();
    }

    /** Synchronous preview (tests and the console). The admin button uses requestPreview(). */
    public function preview(?User $actor): AiBacklogRun
    {
        return $this->fillPreview($this->newPreviewRun($actor));
    }

    /** Queues the read-only scan so a large backlog never runs inside a web request. */
    public function requestPreview(?User $actor): AiBacklogRun
    {
        $run = $this->newPreviewRun($actor);
        PreviewAiBacklog::dispatchFor($run);

        return $run;
    }

    public function fillPreview(AiBacklogRun $run): AiBacklogRun
    {
        if ($run->state !== AiBacklogRun::STATE_PREVIEWING) {
            return $run;
        }

        $now = CarbonImmutable::now();
        $summary = [
            'generated_at' => $now->toIso8601String(),
            'candidates' => 0,
            'would_send' => 0,
            'excluded' => 0,
            'already_in_ledger' => 0,
            'oldest' => null,
            'estimated_model_cost_usd' => 0,
            'capped' => false,
            'by_domain' => [],
            'decisions' => [],
            'risk' => [],
            'excluded_reasons' => [],
            'replies' => [],
        ];
        $cap = max(1, (int) config('ai_automation.backlog.preview_cap_per_type', 2000));

        foreach ($this->pairs() as [$adapter, $sourceType]) {
            $afterId = 0;
            $seen = 0;
            do {
                $candidates = $adapter->backlogCandidates($sourceType, $afterId, self::PREVIEW_PAGE);
                foreach ($candidates as $candidate) {
                    $afterId = $candidate->sourceId;
                    $seen++;
                    $this->tallyPreview($summary, $adapter, $candidate, $now);
                }
            } while ($candidates !== [] && $seen < $cap);
            $summary['capped'] = $summary['capped'] || $seen >= $cap;
        }

        arsort($summary['replies']);
        $summary['replies'] = array_slice($summary['replies'], 0, 12, true);

        $run->forceFill([
            'state' => AiBacklogRun::STATE_PREVIEWED,
            'preview' => $summary,
            'previewed_at' => $now,
        ])->save();

        return $run;
    }

    private function newPreviewRun(?User $actor): AiBacklogRun
    {
        // A new preview replaces any earlier preview that was never started.
        AiBacklogRun::query()
            ->whereIn('state', [AiBacklogRun::STATE_PREVIEWING, AiBacklogRun::STATE_PREVIEWED])
            ->update(['state' => AiBacklogRun::STATE_CANCELLED, 'finished_at' => now(), 'updated_at' => now()]);

        return AiBacklogRun::query()->create([
            'state' => AiBacklogRun::STATE_PREVIEWING,
            'cursor' => ['index' => 0, 'after_id' => 0],
            'created_by_user_id' => $actor?->getKey(),
        ]);
    }

    /** True when the item is older than the optional backlog age limit. */
    private function tooOld(?\DateTimeInterface $submittedAt): bool
    {
        $days = config('ai_automation.backlog.max_age_days');
        if ($days === null || $submittedAt === null) {
            return false;
        }

        return CarbonImmutable::instance($submittedAt)->lt(CarbonImmutable::now()->subDays((int) $days));
    }

    /** Starts a previewed run or resumes a paused one. Refuses while the master switch is OFF. */
    public function start(AiBacklogRun $run): bool
    {
        if (! $this->switch->isOn() || ! in_array($run->state, [AiBacklogRun::STATE_PREVIEWED, AiBacklogRun::STATE_PAUSED], true)) {
            return false;
        }

        // Only one run at a time.
        AiBacklogRun::query()
            ->whereKeyNot($run->getKey())
            ->whereIn('state', [AiBacklogRun::STATE_RUNNING, AiBacklogRun::STATE_PAUSED, AiBacklogRun::STATE_PREVIEWED, AiBacklogRun::STATE_PREVIEWING])
            ->update(['state' => AiBacklogRun::STATE_CANCELLED, 'finished_at' => now(), 'updated_at' => now()]);

        $epoch = $this->switch->epoch();
        $run->forceFill([
            'state' => AiBacklogRun::STATE_RUNNING,
            'epoch' => $epoch,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        ProcessAiBacklogBatch::dispatchFor($run, $epoch);

        return true;
    }

    /** Pausing is also what the master OFF does to a running backlog. */
    public function pause(AiBacklogRun $run): void
    {
        if ($run->state === AiBacklogRun::STATE_RUNNING) {
            $run->forceFill(['state' => AiBacklogRun::STATE_PAUSED])->save();
        }
    }

    /** @return bool whether another batch should follow */
    public function processBatch(int $runId, int $epoch): bool
    {
        $run = AiBacklogRun::query()->find($runId);
        if ($run === null || $run->state !== AiBacklogRun::STATE_RUNNING) {
            return false;
        }
        if (! $this->switch->allows($epoch) || (int) $run->epoch !== $epoch) {
            $this->pause($run);

            return false;
        }

        $pairs = $this->pairs();
        $cursor = $run->cursor ?? ['index' => 0, 'after_id' => 0];
        $index = (int) ($cursor['index'] ?? 0);
        $afterId = (int) ($cursor['after_id'] ?? 0);
        $budget = max(1, (int) config('ai_automation.backlog.batch_size', 10));
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        while ($budget > 0 && $index < count($pairs)) {
            [$adapter, $sourceType] = $pairs[$index];
            $candidates = $adapter->backlogCandidates($sourceType, $afterId, $budget);
            if ($candidates === []) {
                $index++;
                $afterId = 0;

                continue;
            }

            foreach ($candidates as $candidate) {
                if (! $this->switch->allows($epoch)) {
                    break 2;
                }
                $afterId = $candidate->sourceId;
                $budget--;
                $state = $this->processCandidate($run, $adapter, $candidate, $epoch);
                match ($state) {
                    AiIntakeEvent::STATE_SENT => $counts['sent']++,
                    AiIntakeEvent::STATE_FAILED => $counts['failed']++,
                    default => $counts['skipped']++,
                };
            }
        }

        $done = $index >= count($pairs);
        DB::table('ai_backlog_runs')->where('id', $run->getKey())->update([
            'cursor' => json_encode(['index' => $index, 'after_id' => $afterId]),
            'sent_count' => DB::raw('sent_count + '.$counts['sent']),
            'skipped_count' => DB::raw('skipped_count + '.$counts['skipped']),
            'failed_count' => DB::raw('failed_count + '.$counts['failed']),
            'state' => $done ? AiBacklogRun::STATE_COMPLETED : DB::raw('state'),
            'finished_at' => $done ? now() : null,
            'updated_at' => now(),
        ]);

        return ! $done && $this->switch->allows($epoch);
    }

    private function processCandidate(AiBacklogRun $run, DomainAdapter $adapter, BacklogCandidate $candidate, int $epoch): string
    {
        if ($this->tooOld($candidate->submittedAt)) {
            return AiIntakeEvent::STATE_SKIPPED;
        }

        $key = [
            'source_type' => $candidate->sourceType,
            'source_id' => $candidate->sourceId,
            'event_key' => $candidate->eventKey,
        ];

        $inserted = AiIntakeEvent::query()->insertOrIgnore([
            ...$key,
            'domain' => $adapter->domain(),
            'origin' => AiIntakeEvent::ORIGIN_BACKLOG,
            'backlog_run_id' => $run->getKey(),
            'epoch' => $epoch,
            'state' => AiIntakeEvent::STATE_QUEUED,
            'needs_human' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $intake = AiIntakeEvent::query()->where($key)->first();
        if ($intake === null) {
            return AiIntakeEvent::STATE_SKIPPED;
        }

        if ($inserted === 0) {
            // Work an earlier OFF stopped may be picked up again, but only by this explicit backlog run.
            $revived = AiIntakeEvent::query()
                ->whereKey($intake->getKey())
                ->where('state', AiIntakeEvent::STATE_CANCELLED)
                ->update([
                    'state' => AiIntakeEvent::STATE_QUEUED,
                    'epoch' => $epoch,
                    'backlog_run_id' => $run->getKey(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);
            if ($revived === 0) {
                return AiIntakeEvent::STATE_SKIPPED; // already answered, skipped or in flight
            }
        }

        return $this->processor->process((int) $intake->getKey());
    }

    /** @param  array<string, mixed>  $summary */
    private function tallyPreview(array &$summary, DomainAdapter $adapter, BacklogCandidate $candidate, CarbonImmutable $now): void
    {
        $domain = $adapter->domain();
        $summary['candidates']++;
        $summary['by_domain'][$domain] ??= ['candidates' => 0, 'would_send' => 0, 'excluded' => 0];
        $summary['by_domain'][$domain]['candidates']++;

        $ledgerState = AiIntakeEvent::query()
            ->where('source_type', $candidate->sourceType)
            ->where('source_id', $candidate->sourceId)
            ->where('event_key', $candidate->eventKey)
            ->value('state');
        if ($ledgerState !== null && $ledgerState !== AiIntakeEvent::STATE_CANCELLED) {
            $summary['already_in_ledger']++;

            return;
        }

        $probe = new AiIntakeEvent([
            'domain' => $domain,
            'source_type' => $candidate->sourceType,
            'source_id' => $candidate->sourceId,
            'event_key' => $candidate->eventKey,
            'origin' => AiIntakeEvent::ORIGIN_BACKLOG,
            'epoch' => 0,
            'state' => AiIntakeEvent::STATE_QUEUED,
        ]);
        $facts = $adapter->facts($probe);
        $tooOld = $this->tooOld($facts->submittedAt ?? $candidate->submittedAt);
        if ($tooOld || ! $facts->eligible()) {
            $reason = $tooOld ? 'older_than_backlog_limit' : ($facts->ineligibleReason ?? 'already_answered');
            $summary['excluded']++;
            $summary['by_domain'][$domain]['excluded']++;
            $summary['excluded_reasons'][$reason] = ($summary['excluded_reasons'][$reason] ?? 0) + 1;

            return;
        }

        $reply = $this->composer->compose($facts, $now, false);
        $summary['would_send']++;
        $summary['by_domain'][$domain]['would_send']++;
        $summary['decisions'][$reply->decision] = ($summary['decisions'][$reply->decision] ?? 0) + 1;
        $summary['risk'][$reply->riskTier] = ($summary['risk'][$reply->riskTier] ?? 0) + 1;
        $summary['replies'][$reply->text] = ($summary['replies'][$reply->text] ?? 0) + 1;

        $submitted = $facts->submittedAt ?? $candidate->submittedAt;
        if ($submitted !== null) {
            $date = CarbonImmutable::instance($submitted)->toDateString();
            if ($summary['oldest'] === null || $date < $summary['oldest']) {
                $summary['oldest'] = $date;
            }
        }
    }

    /** @return list<array{0: DomainAdapter, 1: string}> */
    private function pairs(): array
    {
        $pairs = [];
        foreach ($this->domains->all() as $adapter) {
            if (! $this->domains->domainEnabled($adapter->domain())) {
                continue;
            }
            foreach ($adapter->backlogSourceTypes() as $sourceType) {
                $pairs[] = [$adapter, $sourceType];
            }
        }

        return $pairs;
    }
}
