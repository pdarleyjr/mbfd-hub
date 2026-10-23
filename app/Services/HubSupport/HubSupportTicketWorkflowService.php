<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;
use App\Enums\HubSupportTicketStatus;
use App\Models\HubSupportTicket;
use App\Models\HubSupportTicketUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'category' => ['sometimes', Rule::enum(HubSupportTicketCategory::class)],
            'impact' => ['sometimes', Rule::enum(HubSupportTicketImpact::class)],
            'client_action_id' => ['nullable', 'uuid'],
            'expected_update_id' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($ticket, $actor, $target, $validated): HubSupportTicket {
            $locked = HubSupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($actor->can('update', $locked), 403);
            $hash = $this->actionHash('admin.'.$target->value, $validated);
            if ($this->replay($locked, $actor, $validated, $hash, 'public_response') !== null) {
                return $locked->load(['updates', 'attachments']);
            }
            $this->editable($locked, $validated, 'public_response');
            $current = $locked->status;
            if ($current !== $target && ! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => 'This status change is not available.']);
            }

            $public = trim((string) ($validated['public_response'] ?? ''));
            $internal = trim((string) ($validated['internal_note'] ?? ''));
            $resolution = trim((string) ($validated['resolution_summary'] ?? ''));
            if ($target !== $current && $target === HubSupportTicketStatus::WaitingForReporter && $public === '') {
                throw ValidationException::withMessages(['public_response' => 'Ask the member a question before requesting information.']);
            }
            if ($target !== $current && $target === HubSupportTicketStatus::Resolved && $resolution === '') {
                throw ValidationException::withMessages(['resolution_summary' => 'Explain how the issue was fixed.']);
            }
            if ($resolution !== '' && $target !== HubSupportTicketStatus::Resolved) {
                throw ValidationException::withMessages(['resolution_summary' => 'A resolution can only be recorded when the issue is fixed.']);
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
            $metadata = ['event' => $target === $current ? 'updated' : 'status_transition'];
            if ($target === HubSupportTicketStatus::Resolved && $resolution !== '') {
                $metadata['resolution_summary'] = $resolution;
            }
            if ($target === HubSupportTicketStatus::InProgress && in_array($current, [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::Closed], true)) {
                $previousResolution = trim((string) $locked->resolution_summary);
                if ($previousResolution !== '') {
                    $metadata['previous_resolution_summary'] = $previousResolution;
                }
            }
            $metadata['classification_before'] = ['assigned_to_user_id' => $locked->assigned_to_user_id, 'category' => $locked->category->value, 'impact' => $locked->impact->value];
            $locked->update($changes);
            $metadata['classification_after'] = ['assigned_to_user_id' => $locked->assigned_to_user_id, 'category' => $locked->category->value, 'impact' => $locked->impact->value];
            $update = $locked->updates()->create([
                'previous_status' => $current,
                'status' => $target,
                'public_response' => $public !== '' ? $public : null,
                'internal_note' => $internal !== '' ? $internal : null,
                'changed_by_user_id' => $actor->id,
                'metadata' => $metadata,
                'client_action_id' => $validated['client_action_id'] ?? null,
                'action_hash' => $hash,
            ]);

            if ($target !== $current || $public !== '') {
                app(HubSupportTicketSideEffectService::class)->memberUpdated($locked, $update);
            }

            return $locked->fresh(['updates', 'attachments']) ?? $locked;
        }, 3);
    }

    public function reply(HubSupportTicket $ticket, User $reporter, string $response, array $context = []): HubSupportTicketUpdate
    {
        $validated = validator(['response' => trim($response)] + $context, [
            'response' => ['required', 'string', 'min:1', 'max:5000'],
            'client_action_id' => ['nullable', 'uuid'], 'expected_update_id' => ['nullable', 'integer', 'min:0'],
        ])->validate();
        $this->authorizeReporter($ticket, $reporter);

        return DB::transaction(function () use ($ticket, $reporter, $validated): HubSupportTicketUpdate {
            $locked = HubSupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->authorizeReporter($locked, $reporter);
            $hash = $this->actionHash('member.reply', $validated);
            if ($existing = $this->replay($locked, $reporter, $validated, $hash)) {
                return $existing;
            }
            $this->editable($locked, $validated);
            $previous = $locked->status;
            $metadata = ['event' => 'reporter_reply'];
            if ($previous === HubSupportTicketStatus::WaitingForReporter) {
                $locked->update(['status' => HubSupportTicketStatus::Acknowledged, 'acknowledged_at' => $locked->acknowledged_at ?? now()]);
            }
            if (in_array($previous, [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::Closed], true)) {
                $previousResolution = trim((string) $locked->resolution_summary);
                if ($previousResolution !== '') {
                    $metadata['previous_resolution_summary'] = $previousResolution;
                }
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
                'metadata' => $metadata,
                'client_action_id' => $validated['client_action_id'] ?? null,
                'action_hash' => $hash,
            ]);

            app(HubSupportTicketSideEffectService::class)->reporterReplied($locked, $update);

            return $update;
        }, 3);
    }

    public function confirmFixed(HubSupportTicket $ticket, User $reporter, array $context = []): HubSupportTicketUpdate
    {
        $this->authorizeReporter($ticket, $reporter);
        $validated = validator($context, [
            'client_action_id' => ['nullable', 'uuid'], 'expected_update_id' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($ticket, $reporter, $validated): HubSupportTicketUpdate {
            $locked = HubSupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->authorizeReporter($locked, $reporter);
            $hash = $this->actionHash('member.confirm_fixed', $validated);
            if ($existing = $this->replay($locked, $reporter, $validated, $hash)) {
                return $existing;
            }
            $this->editable($locked, $validated);
            if ($locked->status !== HubSupportTicketStatus::Resolved) {
                throw ValidationException::withMessages(['response' => 'Only a fixed issue can be confirmed. Reload this report to see its current status.']);
            }
            $locked->update(['status' => HubSupportTicketStatus::Closed, 'closed_at' => now()]);
            $update = $locked->updates()->create([
                'previous_status' => HubSupportTicketStatus::Resolved, 'status' => HubSupportTicketStatus::Closed,
                'changed_by_user_id' => $reporter->id, 'public_response' => 'Confirmed fixed by the reporting member.',
                'metadata' => ['event' => 'reporter_confirmed_fixed'],
                'client_action_id' => $validated['client_action_id'] ?? null, 'action_hash' => $hash,
            ]);
            app(HubSupportTicketSideEffectService::class)->reporterReplied($locked, $update);

            return $update;
        }, 3);
    }

    private function authorizeReporter(HubSupportTicket $ticket, User $reporter): void
    {
        abort_unless($reporter->isAuthenticationAllowed() && ! $reporter->must_change_password
            && $ticket->reported_by_user_id === $reporter->id, 403);
    }

    private function editable(HubSupportTicket $ticket, array $context, string $errorField = 'response'): void
    {
        abort_if($ticket->trashed() || $ticket->isArchived(), 409, 'Restore this report before continuing the conversation.');
        if (isset($context['expected_update_id']) && (int) $context['expected_update_id'] !== (int) $ticket->updates()->max('id')) {
            throw ValidationException::withMessages([$errorField => 'This report changed elsewhere. Reload it before sending your update.']);
        }
    }

    private function actionHash(string $action, array $context): string
    {
        unset($context['client_action_id']);
        ksort($context);

        return hash('sha256', json_encode([$action, $context], JSON_THROW_ON_ERROR));
    }

    private function replay(HubSupportTicket $ticket, User $actor, array $context, string $hash, string $errorField = 'response'): ?HubSupportTicketUpdate
    {
        if (! isset($context['client_action_id'])) {
            return null;
        }
        $existing = $ticket->updates()->where('client_action_id', $context['client_action_id'])->first();
        if ($existing !== null && ($existing->changed_by_user_id !== $actor->id || ! hash_equals((string) $existing->action_hash, $hash))) {
            throw ValidationException::withMessages([$errorField => 'This action identifier was already used for a different update. Reload this report.']);
        }

        return $existing;
    }
}
