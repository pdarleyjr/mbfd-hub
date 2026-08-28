<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DailyCheckoutInspectionSessionException;
use App\Models\Apparatus;
use App\Models\DailyCheckoutInspectionSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class DailyCheckoutInspectionSessionService
{
    public const TIMEZONE = 'America/New_York';

    /**
     * @param  array<string, mixed>  $checklist
     * @param  list<array<string, mixed>>  $dueTasks
     * @return array{session: DailyCheckoutInspectionSession, token: string, created: bool}
     */
    public function issue(
        Apparatus $apparatus,
        array $checklist,
        string $checklistHash,
        array $dueTasks,
        ?int $actorUserId,
        ?string $actorSessionHash,
        ?string $issuanceKey = null,
        ?CarbonImmutable $issuedAt = null,
    ): array {
        $preparedContract = $this->prepareContract($checklist, $checklistHash, $dueTasks);
        $issuedAt = ($issuedAt ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $requestedIssuanceKey = $this->normalizeIssuanceKey($issuanceKey);
        $sessionIssuanceKey = $requestedIssuanceKey ?? (string) Str::uuid();

        return DB::transaction(function () use ($apparatus, $actorUserId, $actorSessionHash, $issuedAt, $preparedContract, $requestedIssuanceKey, $sessionIssuanceKey): array {
            // Serializing starts for one apparatus prevents concurrent browser
            // retries from creating multiple durable contracts.
            Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey());

            // An issuance-key replay must recover its original valid contract,
            // including after midnight. A valid contract owned by this browser
            // likewise remains authoritative until submitted, expired, or
            // explicitly abandoned.
            $existing = $requestedIssuanceKey === null
                ? null
                : $this->activeContractForActor(
                    apparatusId: (int) $apparatus->getKey(),
                    actorUserId: $actorUserId,
                    actorSessionHash: $actorSessionHash,
                    issuedAt: $issuedAt,
                    issuanceKey: $requestedIssuanceKey,
                );
            $existing ??= $this->activeContractForActor(
                apparatusId: (int) $apparatus->getKey(),
                actorUserId: $actorUserId,
                actorSessionHash: $actorSessionHash,
                issuedAt: $issuedAt,
            );
            if ($existing !== null) {
                return $this->issuedContract($existing, false);
            }

            // A missing browser binding must never become an implicit way to
            // create a second duty date while another valid contract exists.
            if ($this->activeContractForApparatus((int) $apparatus->getKey(), $issuedAt) !== null) {
                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_ACTIVE',
                    'A valid Fire Boat inspection session is already active. Reconnect with the issuing browser session or ask an officer to reconcile it.',
                );
            }

            return $this->createContract(
                apparatus: $apparatus,
                preparedContract: $preparedContract,
                actorUserId: $actorUserId,
                actorSessionHash: $actorSessionHash,
                issuanceKey: $sessionIssuanceKey,
                issuedAt: $issuedAt,
            );
        }, 3);
    }

    /**
     * Explicitly abandons one valid prior-day contract and issues the current
     * duty-day contract. The transition is bound to the original session's
     * credentials and idempotency key; browser-state loss alone cannot invoke
     * it or create a replacement contract.
     *
     * @param  array<string, mixed>  $checklist
     * @param  list<array<string, mixed>>  $dueTasks
     * @return array{session: DailyCheckoutInspectionSession, token: string, created: bool}
     */
    public function abandonAndIssue(
        Apparatus $apparatus,
        string $priorPublicId,
        string $priorToken,
        string $priorReplayKey,
        string $transitionKey,
        array $checklist,
        string $checklistHash,
        array $dueTasks,
        ?int $actorUserId,
        ?string $actorSessionHash,
        ?CarbonImmutable $issuedAt = null,
    ): array {
        $preparedContract = $this->prepareContract($checklist, $checklistHash, $dueTasks);
        $issuedAt = ($issuedAt ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $transitionKey = $this->normalizeIssuanceKey($transitionKey);
        if ($transitionKey === null) {
            throw new LogicException('The Daily Checkout inspection-session transition key is required.');
        }

        return DB::transaction(function () use ($apparatus, $priorPublicId, $priorToken, $priorReplayKey, $transitionKey, $preparedContract, $actorUserId, $actorSessionHash, $issuedAt): array {
            Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey());
            $prior = DailyCheckoutInspectionSession::query()
                ->where('public_id', $priorPublicId)
                ->lockForUpdate()
                ->first();
            if ($prior === null || (int) $prior->apparatus_id !== (int) $apparatus->getKey()) {
                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
                    'The Fire Boat inspection session is unavailable. Reconnect and start a new inspection session.',
                );
            }

            $this->assertContractOwnership(
                session: $prior,
                token: $priorToken,
                replayKey: $priorReplayKey,
                actorUserId: $actorUserId,
                actorSessionHash: $actorSessionHash,
            );

            if ($prior->abandoned_at !== null) {
                if ($prior->abandonment_transition_key !== null
                    && hash_equals($prior->abandonment_transition_key, $transitionKey)
                    && $prior->replacement_session_id !== null) {
                    $replacement = DailyCheckoutInspectionSession::query()
                        ->whereKey($prior->replacement_session_id)
                        ->first();
                    if ($replacement !== null
                        && (int) $replacement->apparatus_id === (int) $apparatus->getKey()
                        && (int) $replacement->prior_inspection_session_id === (int) $prior->getKey()) {
                        return $this->issuedContract($replacement, false);
                    }
                }

                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_ALREADY_ABANDONED',
                    'This Fire Boat inspection session was already abandoned and cannot be transitioned again.',
                );
            }

            if ($prior->submitted_inspection_id !== null) {
                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_ALREADY_SUBMITTED',
                    'This Fire Boat inspection session has already been submitted.',
                );
            }

            $priorExpiresAt = $this->inspectionSessionDateAttribute($prior, 'expires_at');
            if ($priorExpiresAt === null || $priorExpiresAt->lessThanOrEqualTo($issuedAt)) {
                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_EXPIRED',
                    'This Fire Boat inspection session has expired. Reconnect and start a new inspection session.',
                );
            }

            $priorDutyDate = $this->inspectionSessionDateAttribute($prior, 'duty_date');
            if ($priorDutyDate === null || $priorDutyDate->toDateString() === $issuedAt->toDateString()) {
                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_ABANDONMENT_NOT_REQUIRED',
                    'The active Fire Boat inspection already belongs to today and cannot be replaced by this transition.',
                );
            }

            $otherActiveContract = $this->activeContractsForApparatus((int) $apparatus->getKey(), $issuedAt)
                ->where('id', '!=', $prior->getKey())
                ->orderBy('id')
                ->first();
            if ($otherActiveContract !== null) {
                throw new DailyCheckoutInspectionSessionException(
                    'DAILY_CHECKOUT_INSPECTION_SESSION_ACTIVE',
                    'A different valid Fire Boat inspection session is already active. Reconnect with the issuing browser session or ask an officer to reconcile it.',
                );
            }

            $replacement = $this->createContract(
                apparatus: $apparatus,
                preparedContract: $preparedContract,
                actorUserId: $actorUserId,
                actorSessionHash: $actorSessionHash,
                issuanceKey: $transitionKey,
                issuedAt: $issuedAt,
                priorInspectionSessionId: (int) $prior->getKey(),
            );
            $prior->update([
                'abandoned_at' => $issuedAt,
                'abandoned_by_user_id' => $actorUserId,
                'abandoned_by_session_hash' => $actorSessionHash,
                'abandonment_reason' => 'operator_requested_start_today',
                'abandonment_transition_type' => 'abandon_prior_inspection_start_today',
                'abandonment_transition_key' => $transitionKey,
                'replacement_session_id' => $replacement['session']->getKey(),
            ]);

            return $replacement;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function publicContract(DailyCheckoutInspectionSession $session, ?string $token = null): array
    {
        return [
            'id' => $session->public_id,
            'token' => $token ?? $this->tokenFor($session->public_id, $session->replay_key),
            'issued_at' => $session->issued_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'duty_date' => $session->duty_date?->toDateString(),
            'checklist_template_id' => $session->checklist_template_id,
            'checklist_template_version' => $session->checklist_template_version,
            'checklist_hash' => $session->checklist_hash,
            'due_tasks' => $session->due_tasks,
            'due_tasks_hash' => $session->due_tasks_hash,
            'replay_key' => $session->replay_key,
        ];
    }

    public function tokenIsValid(DailyCheckoutInspectionSession $session, string $token): bool
    {
        return hash_equals($session->token_hash, hash('sha256', $token));
    }

    /**
     * Derives the anonymous browser binding for a client-held issuance key.
     * The key remains local to the browser; only the SHA-256 binding hash is
     * persisted with the inspection session.
     */
    public function browserBindingTokenForIssuanceKey(string $issuanceKey): string
    {
        return hash_hmac('sha256', "daily-checkout-browser|{$this->normalizeIssuanceKey($issuanceKey)}", $this->appKey());
    }

    public function dueTasksHaveIntegrity(DailyCheckoutInspectionSession $session): bool
    {
        return is_array($session->due_tasks)
            && hash_equals($session->due_tasks_hash, $this->canonicalHash($session->due_tasks));
    }

    public function checklistHasIntegrity(DailyCheckoutInspectionSession $session): bool
    {
        return is_array($session->checklist_snapshot)
            && hash_equals($session->checklist_hash, $this->canonicalHash($session->checklist_snapshot));
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  list<array<string, mixed>>  $dueTasks
     * @return array{template_id: string, template_version: string, checklist_hash: string, checklist_snapshot: array<string, mixed>, due_tasks: list<array<string, mixed>>, due_tasks_hash: string}
     */
    private function prepareContract(array $checklist, string $checklistHash, array $dueTasks): array
    {
        $templateId = $this->requiredString($checklist['template_id'] ?? null, 'template id');
        $templateVersion = $this->requiredString($checklist['template_version'] ?? null, 'template version');
        if (preg_match('/\A[a-f0-9]{64}\z/i', $checklistHash) !== 1) {
            throw new LogicException('The Daily Checkout checklist hash is invalid.');
        }

        $canonicalChecklist = $this->canonicalize($checklist);
        if (! is_array($canonicalChecklist) || ! hash_equals(strtolower($checklistHash), $this->canonicalHash($canonicalChecklist))) {
            throw new LogicException('The Daily Checkout checklist snapshot does not match its hash.');
        }

        $normalizedDueTasks = $this->canonicalize($dueTasks);
        if (! is_array($normalizedDueTasks) || ! array_is_list($normalizedDueTasks)) {
            throw new LogicException('The Daily Checkout scheduled-duty contract is invalid.');
        }

        return [
            'template_id' => $templateId,
            'template_version' => $templateVersion,
            'checklist_hash' => strtolower($checklistHash),
            'checklist_snapshot' => $canonicalChecklist,
            'due_tasks' => $normalizedDueTasks,
            'due_tasks_hash' => $this->canonicalHash($normalizedDueTasks),
        ];
    }

    /**
     * @param  array{template_id: string, template_version: string, checklist_hash: string, checklist_snapshot: array<string, mixed>, due_tasks: list<array<string, mixed>>, due_tasks_hash: string}  $preparedContract
     * @return array{session: DailyCheckoutInspectionSession, token: string, created: bool}
     */
    private function createContract(
        Apparatus $apparatus,
        array $preparedContract,
        ?int $actorUserId,
        ?string $actorSessionHash,
        string $issuanceKey,
        CarbonImmutable $issuedAt,
        ?int $priorInspectionSessionId = null,
    ): array {
        $publicId = (string) Str::uuid();
        $replayKey = (string) Str::uuid();
        $token = $this->tokenFor($publicId, $replayKey);
        $session = DailyCheckoutInspectionSession::query()->create([
            'public_id' => $publicId,
            'apparatus_id' => $apparatus->getKey(),
            'actor_user_id' => $actorUserId,
            'actor_session_hash' => $actorSessionHash,
            'issuance_key' => $issuanceKey,
            'issued_at' => $issuedAt,
            'duty_date' => $issuedAt->toDateString(),
            'checklist_template_id' => $preparedContract['template_id'],
            'checklist_template_version' => $preparedContract['template_version'],
            'checklist_hash' => $preparedContract['checklist_hash'],
            'checklist_snapshot' => $preparedContract['checklist_snapshot'],
            'due_tasks' => $preparedContract['due_tasks'],
            'due_tasks_hash' => $preparedContract['due_tasks_hash'],
            'replay_key' => $replayKey,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $issuedAt->addHours($this->expiryHours()),
            'prior_inspection_session_id' => $priorInspectionSessionId,
        ]);

        return ['session' => $session, 'token' => $token, 'created' => true];
    }

    /** @return array{session: DailyCheckoutInspectionSession, token: string, created: bool} */
    private function issuedContract(DailyCheckoutInspectionSession $session, bool $created): array
    {
        return [
            'session' => $session,
            'token' => $this->tokenFor($session->public_id, $session->replay_key),
            'created' => $created,
        ];
    }

    private function activeContractForActor(
        int $apparatusId,
        ?int $actorUserId,
        ?string $actorSessionHash,
        CarbonImmutable $issuedAt,
        ?string $issuanceKey = null,
    ): ?DailyCheckoutInspectionSession {
        $query = $this->activeContractsForApparatus($apparatusId, $issuedAt);
        if ($issuanceKey !== null) {
            $query->where('issuance_key', $issuanceKey);
        }

        if ($actorUserId === null) {
            $query->whereNull('actor_user_id');
        } else {
            $query->where('actor_user_id', $actorUserId);
        }

        if ($actorSessionHash === null) {
            $query->whereNull('actor_session_hash');
        } else {
            $query->where('actor_session_hash', $actorSessionHash);
        }

        return $query->orderBy('id')->first();
    }

    private function activeContractForApparatus(int $apparatusId, CarbonImmutable $issuedAt): ?DailyCheckoutInspectionSession
    {
        $session = $this->activeContractsForApparatus($apparatusId, $issuedAt)
            ->orderBy('id')
            ->first();

        return $session instanceof DailyCheckoutInspectionSession ? $session : null;
    }

    private function activeContractsForApparatus(int $apparatusId, CarbonImmutable $issuedAt): \Illuminate\Database\Eloquent\Builder
    {
        return DailyCheckoutInspectionSession::query()
            ->where('apparatus_id', $apparatusId)
            ->whereNull('submitted_inspection_id')
            ->whereNull('abandoned_at')
            ->where('expires_at', '>', $issuedAt);
    }

    private function inspectionSessionDateAttribute(DailyCheckoutInspectionSession $session, string $attribute): ?CarbonImmutable
    {
        try {
            $value = $session->getAttribute($attribute);
        } catch (\Throwable) {
            return null;
        }

        return $value instanceof CarbonImmutable ? $value : null;
    }

    private function assertContractOwnership(
        DailyCheckoutInspectionSession $session,
        string $token,
        string $replayKey,
        ?int $actorUserId,
        ?string $actorSessionHash,
    ): void {
        if (! $this->tokenIsValid($session, $token)) {
            throw new DailyCheckoutInspectionSessionException(
                'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
                'The Fire Boat inspection session is unavailable. Reconnect and start a new inspection session.',
            );
        }

        if (! hash_equals($session->replay_key, $replayKey)) {
            throw new DailyCheckoutInspectionSessionException(
                'DAILY_CHECKOUT_INSPECTION_SESSION_REPLAY_MISMATCH',
                'This Fire Boat inspection replay key does not match the server-issued session.',
            );
        }

        if ($session->actor_user_id !== null && $actorUserId !== (int) $session->actor_user_id) {
            throw new DailyCheckoutInspectionSessionException(
                'DAILY_CHECKOUT_INSPECTION_SESSION_ACTOR_MISMATCH',
                'This Fire Boat inspection session was issued to a different authenticated user.',
                403,
            );
        }

        if ($session->actor_session_hash !== null && (
            $actorSessionHash === null || ! hash_equals($session->actor_session_hash, $actorSessionHash)
        )) {
            throw new DailyCheckoutInspectionSessionException(
                'DAILY_CHECKOUT_INSPECTION_SESSION_ACTOR_MISMATCH',
                'This Fire Boat inspection session was issued to a different browser session.',
                403,
            );
        }
    }

    private function tokenFor(string $publicId, string $replayKey): string
    {
        return hash_hmac('sha256', "{$publicId}|{$replayKey}", $this->appKey());
    }

    private function appKey(): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new LogicException('The application key is unavailable for Daily Checkout inspection sessions.');
        }

        return $appKey;
    }

    private function normalizeIssuanceKey(?string $issuanceKey): ?string
    {
        if ($issuanceKey === null) {
            return null;
        }

        $normalized = strtolower($issuanceKey);
        if (preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/', $normalized) !== 1) {
            throw new LogicException('The Daily Checkout inspection-session issuance key is invalid.');
        }

        return $normalized;
    }

    private function expiryHours(): int
    {
        $configured = filter_var(
            config('daily-checkout.inspection_session_expiry_hours', 12),
            FILTER_VALIDATE_INT,
        );

        return is_int($configured) && $configured >= 1 && $configured <= 24 ? $configured : 12;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new LogicException("The Daily Checkout {$label} is unavailable.");
        }

        return trim($value);
    }

    /** @param array<mixed> $value */
    private function canonicalHash(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
