<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

use App\Models\Employee;
use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Models\User;
use App\Services\Ems\EmsActor;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The narcotics check lifecycle (docs/narcotics-check.md section 3):
 *
 *      (member sends)  ->  pending  --approve-->  approved   (archived_at set)
 *                                   --reject--->  rejected   (archived_at set)
 *      rejected  --member resubmits-->  a NEW pending row (resubmission_of_id); the rejected row is untouched.
 *
 * Every transition is one database transaction that locks the row AND updates it conditionally
 * (`WHERE status = 'pending'`), so two Captain 5 users acting at once produce exactly one decision, one
 * audit row and one member notification; the loser gets the current state back, not an error, when it
 * asked for the same outcome, and a plain conflict when it asked for the opposite one.
 */
final class NarcoticsCheckService
{
    public function __construct(
        private readonly NarcoticsImageProcessor $images,
        private readonly NarcoticsImageStore $store,
        private readonly NarcoticsNotifier $notifier,
    ) {}

    // ------------------------------------------------------------------ member

    /**
     * @param  array<string, mixed>  $input  {client_submission_id, note?, resubmission_of?}
     * @return array{check: NarcoticsCheck, replayed: bool}
     */
    public function submit(User $user, Employee $employee, UploadedFile $photo, array $input): array
    {
        $note = config('narcotics_checks.note_max_length');
        $data = EmsValidator::validate($input, [
            'client_submission_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:'.$note],
            'resubmission_of' => ['nullable', 'uuid'],
        ], [
            'client_submission_id.required' => 'Reload the page and try again.',
            'client_submission_id.uuid' => 'Reload the page and try again.',
            'note.max' => "The note can be at most {$note} characters.",
        ]);
        $clientId = Str::lower((string) $data['client_submission_id']);

        $existing = NarcoticsCheck::query()->where('client_submission_id', $clientId)->first();
        if ($existing !== null) {
            return $this->replay($existing, $user);
        }

        $previous = $this->resubmissionTarget($user, $data['resubmission_of'] ?? null);
        $this->guardPendingCap($user);

        $processed = $this->images->process((string) $photo->getRealPath());
        $stored = $this->store->put($processed);

        try {
            $check = DB::transaction(function () use ($user, $employee, $data, $clientId, $previous, $processed, $stored): NarcoticsCheck {
                $actor = EmsActor::member($user);
                $check = NarcoticsCheck::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'status' => NarcoticsCheck::PENDING,
                    'submitted_by_user_id' => $user->getKey(),
                    'submitted_by_employee_id' => $employee->getKey(),
                    'submitter_name' => $actor->name,
                    'submitter_rank' => $actor->rank,
                    'submitted_at' => now(),
                    'member_note' => $this->clean($data['note'] ?? null),
                    'client_submission_id' => $clientId,
                    'resubmission_of_id' => $previous?->getKey(),
                    'image_disk' => $stored['disk'],
                    'image_path' => $stored['image_path'],
                    'preview_path' => $stored['preview_path'],
                    'thumb_path' => $stored['thumb_path'],
                    'image_mime' => $processed->mime,
                    'image_width' => $processed->width,
                    'image_height' => $processed->height,
                    'image_bytes' => $processed->bytes(),
                    'image_sha256' => $processed->sha256(),
                ]);
                $this->event($check, $actor, NarcoticsCheckEvent::SUBMITTED, null, NarcoticsCheck::PENDING, [
                    'resubmission_of' => $previous?->uuid,
                    'image' => ['mime' => $processed->mime, 'width' => $processed->width, 'height' => $processed->height, 'bytes' => $processed->bytes(), 'sha256' => $processed->sha256()],
                ]);
                $this->notifier->checkSubmitted($check);

                return $check;
            });
        } catch (UniqueConstraintViolationException) {
            // A double tap or a retry raced us: the first request won. Keep its row, drop our files.
            $this->store->discard($stored);
            $winner = NarcoticsCheck::query()->where('client_submission_id', $clientId)->first();
            if ($winner !== null) {
                return $this->replay($winner, $user);
            }

            throw EmsException::conflict('That rejected check was already sent again.', 'already_resubmitted');
        } catch (Throwable $exception) {
            $this->store->discard($stored);

            throw $exception;
        }

        return ['check' => $check, 'replayed' => false];
    }

    // ------------------------------------------------------------------- staff

    /** @return array{check: NarcoticsCheck, changed: bool} */
    public function approve(string $uuid, User $actor): array
    {
        return $this->decide($uuid, $actor, NarcoticsCheck::APPROVED, null);
    }

    /** @return array{check: NarcoticsCheck, changed: bool} */
    public function reject(string $uuid, User $actor, ?string $explanation): array
    {
        $max = (int) config('narcotics_checks.rejection_note_max_length');
        $data = EmsValidator::validate(['explanation' => $explanation], ['explanation' => ['nullable', 'string', 'max:'.$max]], [
            'explanation.max' => "The explanation can be at most {$max} characters.",
        ]);

        return $this->decide($uuid, $actor, NarcoticsCheck::REJECTED, $this->clean($data['explanation'] ?? null));
    }

    /** Records that a reviewer opened the check (once per reviewer), for the audit trail. */
    public function recordView(NarcoticsCheck $check, User $reviewer): void
    {
        $exists = NarcoticsCheckEvent::query()
            ->where('narcotics_check_id', $check->getKey())
            ->where('action', NarcoticsCheckEvent::VIEWED)
            ->where('actor_user_id', $reviewer->getKey())
            ->exists();
        if (! $exists) {
            $this->event($check, EmsActor::forUser($reviewer, NarcoticsCheckEvent::CONTEXT_CAPTAIN5), NarcoticsCheckEvent::VIEWED, $check->status, $check->status, []);
        }
    }

    /** Photo files past the retention window are removed; the record, decision and audit rows stay. */
    public function purgeExpiredImages(int $retentionDays, bool $apply): int
    {
        $count = 0;
        NarcoticsCheck::query()
            ->whereNotNull('decided_at')
            ->whereNull('images_purged_at')
            ->where('decided_at', '<', now()->subDays($retentionDays))
            ->orderBy('id')
            ->chunkById(100, function ($checks) use ($apply, &$count): void {
                foreach ($checks as $check) {
                    $count++;
                    if (! $apply) {
                        continue;
                    }
                    DB::transaction(function () use ($check): void {
                        $this->store->purge($check);
                        $check->forceFill(['images_purged_at' => now()])->save();
                        $this->event($check, EmsActor::system(), NarcoticsCheckEvent::IMAGES_PURGED, $check->status, $check->status, ['reason' => 'retention']);
                    });
                }
            });

        return $count;
    }

    // ----------------------------------------------------------------- private

    /** @return array{check: NarcoticsCheck, changed: bool} */
    private function decide(string $uuid, User $actor, string $outcome, ?string $note): array
    {
        return DB::transaction(function () use ($uuid, $actor, $outcome, $note): array {
            $check = NarcoticsCheck::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if ($check === null) {
                throw EmsException::notFound('narcotics check');
            }
            if ($check->status !== NarcoticsCheck::PENDING) {
                return $this->alreadyDecided($check, $outcome);
            }
            // A Captain 5 holder files checks like any member but never decides their own: another
            // reviewer (or an administrator) does, so the record stays independent of the submitter.
            if ($check->submitted_by_user_id !== null
                && (int) $check->submitted_by_user_id === (int) $actor->getKey()
                && ! $actor->hasRole('super_admin')) {
                throw EmsException::forbidden('Another Captain 5 reviewer needs to review your own narcotics check.');
            }

            $who = EmsActor::forUser($actor, NarcoticsCheckEvent::CONTEXT_CAPTAIN5);
            $at = now();
            // The conditional update is the guard on databases where lockForUpdate() is a no-op.
            $updated = NarcoticsCheck::query()->whereKey($check->getKey())->where('status', NarcoticsCheck::PENDING)->update([
                'status' => $outcome,
                'decided_at' => $at,
                'decided_by_user_id' => $actor->getKey(),
                'decided_by_name' => $who->name,
                'decided_by_rank' => $who->rank,
                'decision_note' => $outcome === NarcoticsCheck::REJECTED ? $note : null,
                'archived_at' => $at,
                'updated_at' => $at,
            ]);
            $check->refresh();
            if ($updated !== 1) {
                return $this->alreadyDecided($check, $outcome);
            }

            $event = $this->event(
                $check,
                $who,
                $outcome === NarcoticsCheck::APPROVED ? NarcoticsCheckEvent::APPROVED : NarcoticsCheckEvent::REJECTED,
                NarcoticsCheck::PENDING,
                $outcome,
                $outcome === NarcoticsCheck::REJECTED ? ['explanation' => $note] : [],
            );
            $this->notifier->checkDecided($check, $event);

            return ['check' => $check, 'changed' => true];
        });
    }

    /** @return array{check: NarcoticsCheck, changed: bool} */
    private function alreadyDecided(NarcoticsCheck $check, string $outcome): array
    {
        if ($check->status === $outcome) {
            return ['check' => $check, 'changed' => false]; // same answer twice: nothing more to do
        }

        $verb = $check->status === NarcoticsCheck::APPROVED ? 'approved' : 'rejected';
        $by = $check->decided_by_name !== null ? " by {$check->decided_by_name}" : '';

        throw EmsException::conflict("This check was already {$verb}{$by}. Nothing was changed.", 'already_decided');
    }

    /** @return array{check: NarcoticsCheck, replayed: bool} */
    private function replay(NarcoticsCheck $existing, User $user): array
    {
        if ($existing->submitted_by_user_id === null || (int) $existing->submitted_by_user_id !== (int) $user->getKey()) {
            // Someone else's submission id: never reveal it exists.
            throw EmsException::conflict('Reload the page and try again.', 'submission_id_conflict');
        }

        return ['check' => $existing, 'replayed' => true];
    }

    private function resubmissionTarget(User $user, mixed $uuid): ?NarcoticsCheck
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $previous = NarcoticsCheck::query()
            ->where('uuid', Str::lower((string) $uuid))
            ->where('submitted_by_user_id', $user->getKey())
            ->first();
        if ($previous === null || $previous->status !== NarcoticsCheck::REJECTED) {
            throw new EmsException('Only a rejected narcotics check can be sent again.', 'cannot_resubmit', 422);
        }
        if (NarcoticsCheck::query()->where('resubmission_of_id', $previous->getKey())->exists()) {
            throw EmsException::conflict('That rejected check was already sent again.', 'already_resubmitted');
        }

        return $previous;
    }

    private function guardPendingCap(User $user): void
    {
        $max = (int) config('narcotics_checks.max_pending_per_member');
        if ($max > 0 && NarcoticsCheck::query()->where('submitted_by_user_id', $user->getKey())->where('status', NarcoticsCheck::PENDING)->count() >= $max) {
            throw new EmsException("You already have {$max} narcotics checks waiting for Captain 5. Wait for a decision before sending another.", 'too_many_pending', 429);
        }
    }

    /** @param array<string, mixed> $payload */
    private function event(NarcoticsCheck $check, EmsActor $actor, string $action, ?string $from, ?string $to, array $payload): NarcoticsCheckEvent
    {
        return NarcoticsCheckEvent::query()->create([
            'narcotics_check_id' => $check->getKey(),
            'actor_user_id' => $actor->userId,
            'actor_name' => $actor->name,
            'actor_rank' => $actor->rank,
            'actor_context' => $actor->context,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }

    private function clean(mixed $text): ?string
    {
        $value = trim((string) $text);

        return $value === '' ? null : $value;
    }
}
