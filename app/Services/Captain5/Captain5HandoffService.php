<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5HandoffNote;
use App\Models\User;
use App\Services\Security\Captain5Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Captain 5 shift handoff notes: written by anyone who may act, acknowledged once by the next officer. */
final class Captain5HandoffService
{
    public function __construct(private readonly Captain5Access $access) {}

    /**
     * @param  array{body?: mixed, client_request_id?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function write(User $author, array $data): Captain5HandoffNote
    {
        $decision = $this->access->decide($author);
        if (! $decision->canAct) {
            throw new AuthorizationException('Only Captain 5 officers can write a handoff note.');
        }
        $valid = Validator::make($data, [
            'body' => ['required', 'string', 'max:4000'],
            'client_request_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/D'],
        ])->validate();
        $body = trim((string) $valid['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write the handoff before saving it.']);
        }

        $existing = $this->existing($author, $valid['client_request_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Captain5HandoffNote::query()->create([
                'duty_date' => $decision->onDuty->dutyDate->format('Y-m-d'),
                'author_user_id' => $author->getKey(),
                'author_authority' => $decision->authority(),
                'body' => $body,
                'client_request_id' => $valid['client_request_id'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($author, $valid['client_request_id'] ?? null) ?? throw $exception;
        }
    }

    /** @throws AuthorizationException */
    public function acknowledge(User $actor, int $noteId): Captain5HandoffNote
    {
        if (! $this->access->decide($actor)->canAct) {
            throw new AuthorizationException('Only Captain 5 officers can acknowledge a handoff.');
        }

        return DB::transaction(function () use ($actor, $noteId): Captain5HandoffNote {
            $note = Captain5HandoffNote::query()->lockForUpdate()->findOrFail($noteId);
            if ($note->acknowledged_at !== null) {
                return $note; // first acknowledgement stands
            }
            if ((int) $note->author_user_id === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['note' => 'The next Captain 5 acknowledges your handoff, not you.']);
            }
            $note->forceFill(['acknowledged_by_user_id' => $actor->getKey(), 'acknowledged_at' => now()])->save();

            return $note;
        }, 3);
    }

    private function existing(User $author, ?string $clientRequestId): ?Captain5HandoffNote
    {
        if ($clientRequestId === null || $clientRequestId === '') {
            return null;
        }
        $note = Captain5HandoffNote::query()->where('client_request_id', $clientRequestId)->first();
        if ($note !== null && (int) $note->author_user_id !== (int) $author->getKey()) {
            throw new AuthorizationException('That request id belongs to another person.');
        }

        return $note;
    }
}
