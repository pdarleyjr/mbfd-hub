<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;
use App\Enums\HubSupportTicketStatus;
use App\Models\HubSupportTicket;
use App\Models\HubSupportTicketUpdate;
use App\Models\User;
use App\Notifications\HubSupportMemberNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class HubSupportTicketWorkflowService
{
    /** @param array<string, mixed> $data */
    public function transition(HubSupportTicket $ticket, User $actor, HubSupportTicketStatus $target, array $data = []): HubSupportTicket
    {
        abort_unless($actor->can('update', $ticket), 403);
        $validated = validator($data, [
            'public_response' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:10000'],
            'resolution_summary' => ['nullable', 'string', 'max:10000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'category' => ['nullable', Rule::enum(HubSupportTicketCategory::class)],
            'impact' => ['nullable', Rule::enum(HubSupportTicketImpact::class)],
        ])->validate();

        return DB::transaction(function () use ($ticket, $actor, $target, $validated): HubSupportTicket {
            $locked = HubSupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($actor->can('update', $locked), 403);
            $current = $locked->status;
            if ($current !== $target && ! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => 'This status change is not available.']);
            }

            $public = trim((string) ($validated['public_response'] ?? ''));
            $internal = trim((string) ($validated['internal_note'] ?? ''));
            $resolution = trim((string) ($validated['resolution_summary'] ?? ''));
            if ($target === HubSupportTicketStatus::WaitingForReporter && $public === '') {
                throw ValidationException::withMessages(['public_response' => 'Ask the member a question before requesting information.']);
            }
            if ($target === HubSupportTicketStatus::Resolved && $resolution === '') {
                throw ValidationException::withMessages(['resolution_summary' => 'Explain how the issue was fixed.']);
            }
            if ($target === $current && $public === '' && $internal === '' && ! array_key_exists('assigned_to_user_id', $validated)
                && ! array_key_exists('category', $validated) && ! array_key_exists('impact', $validated)) {
                throw ValidationException::withMessages(['status' => 'Add a response, note, assignment, or classification change.']);
            }

            $changes = ['status' => $target];
            if (array_key_exists('assigned_to_user_id', $validated) && $validated['assigned_to_user_id'] !== null) {
                $assignee = User::query()->findOrFail($validated['assigned_to_user_id']);
                if (! $assignee->can('update', $locked)) {
                    throw ValidationException::withMessages(['assigned_to_user_id' => 'The assignee must be authorized to manage issue reports.']);
                }
            }
            foreach (['assigned_to_user_id', 'category', 'impact'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = $validated[$field];
                }
            }
            if ($resolution !== '') {
                $changes['resolution_summary'] = $resolution;
            }
            if ($target !== $current) {
                $timestamp = match ($target) {
                    HubSupportTicketStatus::Acknowledged => 'acknowledged_at',
                    HubSupportTicketStatus::InProgress => 'started_at',
                    HubSupportTicketStatus::Resolved => 'resolved_at',
                    HubSupportTicketStatus::Closed => 'closed_at',
                    default => null,
                };
                if ($timestamp !== null && $locked->{$timestamp} === null) {
                    $changes[$timestamp] = now();
                }
                if ($target === HubSupportTicketStatus::InProgress && in_array($current, [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::Closed], true)) {
                    $changes['resolved_at'] = null;
                    $changes['closed_at'] = null;
                    $changes['resolution_summary'] = null;
                }
            }
            $locked->update($changes);
            $locked->updates()->create([
                'previous_status' => $current,
                'status' => $target,
                'public_response' => $public !== '' ? $public : null,
                'internal_note' => $internal !== '' ? $internal : null,
                'changed_by_user_id' => $actor->id,
                'metadata' => ['event' => $target === $current ? 'updated' : 'status_transition'],
            ]);

            if ($target === HubSupportTicketStatus::WaitingForReporter || $target === HubSupportTicketStatus::Resolved) {
                DB::afterCommit(function () use ($locked, $target, $public, $resolution): void {
                    try {
                        $locked->reporter->notify(new HubSupportMemberNotification(
                            $locked->id,
                            $target->memberLabel(),
                            $public !== '' ? $public : $resolution,
                        ));
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $locked->fresh(['updates', 'attachments']) ?? $locked;
        }, 3);
    }

    public function reply(HubSupportTicket $ticket, User $reporter, string $response): HubSupportTicketUpdate
    {
        $validated = validator(['response' => $response], ['response' => ['required', 'string', 'min:1', 'max:5000']])->validate();
        if ($ticket->reported_by_user_id !== $reporter->id) {
            abort(403);
        }

        return DB::transaction(function () use ($ticket, $reporter, $validated): HubSupportTicketUpdate {
            $locked = HubSupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->reported_by_user_id !== $reporter->id || $locked->status === HubSupportTicketStatus::Closed) {
                abort(403);
            }
            $previous = $locked->status;
            if ($previous === HubSupportTicketStatus::WaitingForReporter) {
                $locked->update(['status' => HubSupportTicketStatus::Acknowledged, 'acknowledged_at' => $locked->acknowledged_at ?? now()]);
            }
            if ($previous === HubSupportTicketStatus::Resolved) {
                $locked->update([
                    'status' => HubSupportTicketStatus::InProgress,
                    'started_at' => $locked->started_at ?? now(),
                    'resolved_at' => null,
                    'closed_at' => null,
                    'resolution_summary' => null,
                ]);
            }

            $update = $locked->updates()->create([
                'previous_status' => $previous,
                'status' => $locked->status,
                'public_response' => trim($validated['response']),
                'changed_by_user_id' => $reporter->id,
                'metadata' => ['event' => 'reporter_reply'],
            ]);

            DB::afterCommit(function () use ($locked): void {
                try {
                    app(HubSupportTicketSideEffectService::class)->reporterReplied($locked);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $update;
        }, 3);
    }
}
