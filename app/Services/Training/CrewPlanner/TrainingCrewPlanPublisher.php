<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\CommandCommunicationPost;
use App\Models\Training\TrainingCrewPlanPublication;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationService;
use App\Services\DepartmentUpdates\CommandDepartmentUpdatePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends a plan through the Hub's own message feeds; it is not another messenger. Having Crew Planner access
 * grants nothing here: each destination's existing rule decides, on the server, for every send. Each version
 * of a plan goes to each destination once, so double taps and retries cannot repeat it, and a failure in one
 * destination leaves the others alone.
 */
final class TrainingCrewPlanPublisher
{
    public const COMMAND_300 = 'command_300';

    public const CAPTAIN_5 = 'captain_5';

    public const DEPARTMENT = 'department';

    public const DESTINATIONS = [self::COMMAND_300, self::CAPTAIN_5, self::DEPARTMENT];

    private const COMMAND_AUDIENCE = [self::COMMAND_300 => CommandCommunicationPost::AUDIENCE_COMMAND_300, self::CAPTAIN_5 => CommandCommunicationPost::AUDIENCE_CAPTAIN_5];

    private const COMMAND_CONTEXT = [self::COMMAND_300 => CommandCommunicationPost::CONTEXT_COMMAND, self::CAPTAIN_5 => CommandCommunicationPost::CONTEXT_CAPTAIN5];

    public function __construct(
        private readonly TrainingCrewPlanComposer $composer,
        private readonly TrainingCrewPlanExporter $exporter,
        private readonly TrainingCrewPlanNotice $notice,
        private readonly CommandCommunicationService $communications,
        private readonly CommandDepartmentUpdatePublisher $departmentUpdates,
    ) {}

    public function allows(User $actor, string $destination): bool
    {
        return match ($destination) {
            self::COMMAND_300 => $this->communications->access()->canRead($actor, CommandCommunicationPost::CHANNEL_COMMAND_300),
            self::CAPTAIN_5 => $this->communications->access()->canRead($actor, CommandCommunicationPost::CHANNEL_CAPTAIN_5),
            self::DEPARTMENT => $this->departmentUpdates->canPost($actor),
            default => false,
        };
    }

    /**
     * @param  list<string>  $destinations
     * @return array<string, TrainingCrewPlanPublication>
     *
     * @throws ValidationException when the plan itself cannot go out; nothing has been sent
     */
    public function publish(User $actor, array $draft, array $destinations, bool $reviewConfirmed): array
    {
        $destinations = array_values(array_intersect(self::DESTINATIONS, $destinations));
        if ($destinations === []) {
            throw ValidationException::withMessages(['publishTargets' => 'Choose where to send this plan.']);
        }
        $projection = $this->composer->projection($actor, $draft, $reviewConfirmed);
        $fingerprint = $this->fingerprint($draft, $projection);

        $lock = Cache::lock('training-crew-plan-publish:'.$fingerprint, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['publishTargets' => 'This plan is already being sent. Wait a moment, then check its status below.']);
        }
        try {
            return $this->send($actor, $draft, $projection, $fingerprint, $destinations);
        } finally {
            $lock->release();
        }
    }

    /** @return Collection<int, TrainingCrewPlanPublication> */
    public function history(User $actor, string $draftId): Collection
    {
        return TrainingCrewPlanPublication::query()->where('draft_id', $draftId)->where('author_user_id', $actor->getKey())
            ->orderByDesc('id')->limit(30)->get();
    }

    /** @return array<string, TrainingCrewPlanPublication> */
    private function send(User $actor, array $draft, array $projection, string $fingerprint, array $destinations): array
    {
        $report = $this->exporter->report($projection);
        $updated = TrainingCrewPlanPublication::query()->where('draft_id', $draft['id'])->where('status', TrainingCrewPlanPublication::STATUS_SENT)
            ->where('fingerprint', '!=', $fingerprint)->exists();
        $notice = $this->notice->compose($report, $updated);
        $rows = [];
        foreach ($destinations as $destination) {
            $rows[$destination] = TrainingCrewPlanPublication::query()->firstOrCreate(
                ['fingerprint' => $fingerprint, 'destination' => $destination],
                ['draft_id' => $draft['id'], 'author_user_id' => $actor->getKey(), 'status' => TrainingCrewPlanPublication::STATUS_SENDING,
                    'class_title' => mb_substr($projection['course_name'], 0, 200), 'class_date' => $projection['schedule_date'], 'member_count' => $report['total']],
            );
        }
        $pending = array_filter($rows, static fn (TrainingCrewPlanPublication $row): bool => $row->status !== TrainingCrewPlanPublication::STATUS_SENT);

        $command = array_values(array_intersect([self::COMMAND_300, self::CAPTAIN_5], array_keys($pending)));
        if ($command !== []) {
            $this->sendCommand($actor, $projection, $notice, $fingerprint, $command, $pending);
        }
        if (isset($pending[self::DEPARTMENT])) {
            $this->attempt($pending[self::DEPARTMENT], function () use ($actor, $projection, $notice, $fingerprint, $pending): void {
                if (! $this->departmentUpdates->canPost($actor)) {
                    throw new AuthorizationException;
                }
                $update = $this->departmentUpdates->publish($actor, CommandDepartmentUpdatePublisher::ORIGIN_TRAINING, [
                    'title' => $notice['subject'], 'body' => $notice['body'], 'category' => 'training',
                    'expires_at' => $this->expiry($projection['schedule_date']),
                    'client_request_id' => 'crewplan-'.substr($fingerprint, 0, 40),
                ]);
                $pending[self::DEPARTMENT]->department_update_id = $update->getKey();
            });
        }

        return array_map(static fn (TrainingCrewPlanPublication $row): TrainingCrewPlanPublication => $row->refresh(), $rows);
    }

    /**
     * 300 and Captain 5 share one post when the author may write to both as an administrator, which is what
     * the "both" audience is for. Anyone else writes to each channel they belong to separately.
     *
     * @param  list<string>  $destinations
     * @param  array<string, TrainingCrewPlanPublication>  $rows
     */
    private function sendCommand(User $actor, array $projection, array $notice, string $fingerprint, array $destinations, array $rows): void
    {
        $access = $this->communications->access();
        $administrator = $access->isAdministrator($actor);
        $allowed = array_values(array_filter($destinations, fn (string $destination): bool => $this->allows($actor, $destination)));
        foreach (array_diff($destinations, $allowed) as $denied) {
            $this->attempt($rows[$denied], static fn () => throw new AuthorizationException);
        }
        if ($allowed === []) {
            return;
        }
        $file = $this->rosterFile($projection);
        try {
            if ($administrator && count($allowed) === 2) {
                $this->post($actor, CommandCommunicationPost::CONTEXT_ADMINISTRATION, CommandCommunicationPost::AUDIENCE_BOTH, $notice, $fingerprint, $file, [$rows[self::COMMAND_300], $rows[self::CAPTAIN_5]]);

                return;
            }
            foreach ($allowed as $destination) {
                $context = $administrator ? CommandCommunicationPost::CONTEXT_ADMINISTRATION : self::COMMAND_CONTEXT[$destination];
                $this->post($actor, $context, $administrator ? self::COMMAND_AUDIENCE[$destination] : null, $notice, $fingerprint.$destination, $file, [$rows[$destination]]);
            }
        } finally {
            if ($file !== null && is_file($file->getPathname())) {
                @unlink($file->getPathname());
            }
        }
    }

    /** @param list<TrainingCrewPlanPublication> $rows */
    private function post(User $actor, string $context, ?string $audience, array $notice, string $seed, ?UploadedFile $file, array $rows): void
    {
        $attempt = function () use ($actor, $context, $audience, $notice, $seed, $file, $rows): void {
            $post = $this->communications->post($actor, $context, $notice['subject'], $notice['body'], null,
                'crewplan-'.substr(hash('sha256', $seed), 0, 40), $file === null ? [] : [$file], $audience);
            foreach ($rows as $row) {
                $row->command_post_id = $post->getKey();
            }
        };
        foreach ($rows as $index => $row) {
            // One post, one outcome: the first row carries the send, and the others follow its result.
            if ($index === 0) {
                $this->attempt($row, $attempt);
            } else {
                $row->forceFill(['status' => $rows[0]->status, 'error_code' => $rows[0]->error_code, 'attempts' => $row->attempts + 1,
                    'command_post_id' => $rows[0]->command_post_id, 'sent_at' => $rows[0]->sent_at])->save();
            }
        }
    }

    private function attempt(TrainingCrewPlanPublication $row, callable $send): void
    {
        try {
            $send();
            $row->forceFill(['status' => TrainingCrewPlanPublication::STATUS_SENT, 'error_code' => null, 'attempts' => $row->attempts + 1, 'sent_at' => now()])->save();
        } catch (Throwable $failure) {
            $code = $failure instanceof AuthorizationException ? 'not_permitted' : ($failure instanceof ValidationException ? 'rejected' : 'send_failed');
            $row->forceFill(['status' => TrainingCrewPlanPublication::STATUS_FAILED, 'error_code' => $code, 'attempts' => $row->attempts + 1])->save();
            // Class name and code only: a validation or query message could carry the member list.
            Log::warning('training_crew_plan.publish_failed', ['publication_id' => $row->getKey(), 'destination' => $row->destination, 'error' => $code, 'exception' => $failure::class]);
        }
    }

    /** The printable roster, attached to private command cards only. It has employee IDs, so never to a department-wide update. */
    private function rosterFile(array $projection): ?UploadedFile
    {
        try {
            $path = tempnam(sys_get_temp_dir(), 'crewplan');
            if ($path === false) {
                return null;
            }
            file_put_contents($path, $this->exporter->pdf($projection));

            return new UploadedFile($path, 'MBFD-Training-Class-Roster-'.$projection['schedule_date'].'.pdf', 'application/pdf', null, true);
        } catch (Throwable $failure) {
            Log::warning('training_crew_plan.attachment_failed', ['exception' => $failure::class]);

            return null; // The message itself still has everything people need.
        }
    }

    /** A class notice leaves the home page the day after the class. */
    private function expiry(string $date): ?string
    {
        $expires = CarbonImmutable::parse($date, \App\Support\HubTimezone::NAME)->addDay()->endOfDay();

        return $expires->isFuture() && $expires->lt(now()->addDays(119)) ? $expires->format('Y-m-d H:i:s') : null;
    }

    /** Identifies this exact plan. Any change to the class, date, roster version, sessions or members makes a new version. */
    private function fingerprint(array $draft, array $projection): string
    {
        $stable = array_diff_key($projection, array_flip(['generated_at', 'roster_as_of']));
        ksort($stable['rows']);

        return hash('sha256', $draft['id'].json_encode($stable, JSON_THROW_ON_ERROR));
    }
}
