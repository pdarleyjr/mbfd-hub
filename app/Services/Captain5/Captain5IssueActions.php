<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Security\Captain5AccessDecision;
use App\Support\Fleet\DefectActionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Maps the Captain 5 workspace actions onto the one authoritative corrective-action engine
 * (docs/captain5-ems-operations-center.md section 5). No action here writes issue state itself.
 */
final class Captain5IssueActions
{
    public const ACTIONS = ['acknowledge', 'ordered', 'update_delivery', 'available_for_pickup', 'replaced', 'verify', 'dismiss', 'reopen'];

    public const REPLACEMENT_RESOLUTIONS = ['replacement_installed', 'spare_issued', 'repair_completed'];

    public function __construct(
        private readonly DefectCorrectiveActionService $engine,
        private readonly Captain5IssueScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException|\App\Services\Defects\StaleDefectStateException
     */
    public function perform(User $actor, Captain5AccessDecision $decision, int $defectId, array $input): ApparatusDefect
    {
        $valid = Validator::make($input, [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'expected_state' => ['required', 'string', 'max:80'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,48}$/D'],
            'member_message' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:4000'],
            'vendor_reference' => ['nullable', 'string', 'max:255'],
            'expected_delivery' => ['nullable', 'date_format:Y-m-d'],
            'parts_ordered' => ['nullable', 'string', 'max:255'],
            'pickup_instructions' => ['nullable', 'string', 'max:2000'],
            'resolution_type' => ['nullable', Rule::in(self::REPLACEMENT_RESOLUTIONS)],
            'resolution_notes' => ['nullable', 'string', 'max:5000'],
            'verified_in_place' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        if (! $decision->canAct) {
            throw new AuthorizationException('You can view this workspace but not act on issues.');
        }
        $probe = ApparatusDefect::query()->find($defectId);
        if ($probe === null || ! $this->scope->inScope($probe)) {
            throw new AuthorizationException('Not available.');
        }

        $engine = $this->engineFor($decision)->expectingState((string) $valid['expected_state']);
        $key = (string) $valid['idempotency_key'];
        $messages = [
            'member_message' => $valid['member_message'] ?? null,
            'internal_note' => $valid['internal_note'] ?? null,
        ];

        return match ($valid['action']) {
            'acknowledge' => $engine->acknowledge($defectId, $actor, $messages, $key),
            'ordered' => $engine->updateAction($defectId, $actor, [
                ...$messages,
                'action_status' => DefectActionStatus::ACTION_PENDING,
                'details' => $this->details($valid, ['parts_ordered', 'vendor_reference', 'expected_delivery'], ['parts_ordered' => 'Ordered']),
            ], $key),
            'update_delivery' => $engine->updateAction($defectId, $actor, [
                ...$messages,
                'action_status' => $this->pendingStatus($probe),
                'details' => $this->details($valid, ['vendor_reference', 'expected_delivery', 'pickup_instructions']),
            ], $key),
            'available_for_pickup' => $engine->updateAction($defectId, $actor, [
                ...$messages,
                'action_status' => DefectActionStatus::REPLACEMENT_AVAILABLE,
                'details' => $this->details($valid, ['pickup_instructions']),
            ], $key),
            'replaced' => $this->replaced($engine, $defectId, $actor, $valid, $messages, $key),
            'verify' => $engine->verifyCorrection($defectId, $actor, [...$messages, 'notes' => $valid['resolution_notes'] ?? null], $key),
            'dismiss' => $engine->dismiss($defectId, $actor, [...$messages, 'reason' => $this->required($valid, 'reason', 'Explain why this issue is being dismissed.')], $key),
            'reopen' => $engine->reopen($defectId, $actor, [...$messages, 'reason' => $this->required($valid, 'reason', 'Explain why this issue is being reopened.')], $key),
            default => throw ValidationException::withMessages(['action' => 'Unknown action.']),
        };
    }

    /** Officers holding a Captain 5 role act as Captain 5; Logistics reviewers keep their own path. */
    private function engineFor(Captain5AccessDecision $decision): DefectCorrectiveActionService
    {
        return $decision->actsAsLogistics()
            ? $this->engine
            : $this->engine->forCaptain5(Captain5ActorContext::from($decision));
    }

    /**
     * Replaced / returned to service closes the issue only with an explicit confirmation that the
     * replacement was verified in place and a description of what was done.
     *
     * @param  array<string, mixed>  $valid
     * @param  array<string, ?string>  $messages
     */
    private function replaced(DefectCorrectiveActionService $engine, int $defectId, User $actor, array $valid, array $messages, string $key): ApparatusDefect
    {
        if (($valid['verified_in_place'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'verified_in_place' => 'Confirm the replacement is in place on the unit before closing the issue. Ordered or ready for pickup is not replaced.',
            ]);
        }

        return $engine->resolve($defectId, $actor, [
            ...$messages,
            'resolution_type' => $valid['resolution_type'] ?? 'replacement_installed',
            'resolution_notes' => $this->required($valid, 'resolution_notes', 'Describe what was replaced or repaired.'),
        ], $key);
    }

    /**
     * @param  array<string, mixed>  $valid
     * @param  list<string>  $keys
     * @param  array<string, string>  $defaults
     * @return array<string, mixed>
     */
    private function details(array $valid, array $keys, array $defaults = []): array
    {
        $details = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $valid) && $valid[$key] !== null && trim((string) $valid[$key]) !== '') {
                $details[$key] = trim((string) $valid[$key]);
            } elseif (isset($defaults[$key])) {
                $details[$key] = $defaults[$key];
            }
        }

        return $details;
    }

    private function pendingStatus(ApparatusDefect $defect): string
    {
        return $defect->action_status === DefectActionStatus::REPLACEMENT_AVAILABLE
            ? DefectActionStatus::REPLACEMENT_AVAILABLE
            : DefectActionStatus::ACTION_PENDING;
    }

    /** @param array<string, mixed> $valid */
    private function required(array $valid, string $key, string $message): string
    {
        $value = trim((string) ($valid[$key] ?? ''));
        if ($value === '') {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $value;
    }
}
