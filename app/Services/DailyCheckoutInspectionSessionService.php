<?php

declare(strict_types=1);

namespace App\Services;

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
        $templateId = $this->requiredString($checklist['template_id'] ?? null, 'template id');
        $templateVersion = $this->requiredString($checklist['template_version'] ?? null, 'template version');
        if (preg_match('/\A[a-f0-9]{64}\z/i', $checklistHash) !== 1) {
            throw new LogicException('The Daily Checkout checklist hash is invalid.');
        }

        $issuedAt = ($issuedAt ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $requestedIssuanceKey = $this->normalizeIssuanceKey($issuanceKey);
        $sessionIssuanceKey = $requestedIssuanceKey ?? (string) Str::uuid();
        if (! hash_equals(strtolower($checklistHash), $this->canonicalHash($checklist))) {
            throw new LogicException('The Daily Checkout checklist snapshot does not match its hash.');
        }

        $normalizedDueTasks = $this->canonicalize($dueTasks);
        if (! is_array($normalizedDueTasks) || ! array_is_list($normalizedDueTasks)) {
            throw new LogicException('The Daily Checkout scheduled-duty contract is invalid.');
        }

        return DB::transaction(function () use ($apparatus, $actorUserId, $actorSessionHash, $checklist, $checklistHash, $issuedAt, $normalizedDueTasks, $requestedIssuanceKey, $sessionIssuanceKey, $templateId, $templateVersion): array {
            // Serializing starts for one apparatus prevents concurrent browser
            // retries from creating multiple durable contracts.
            Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey());
            $this->pruneExpiredUnsubmitted($issuedAt);

            // A replay after a lost first response must recover its original
            // contract, even if it crosses midnight. A newly supplied key
            // must not bypass an already active contract for this browser.
            $existing = $requestedIssuanceKey === null
                ? null
                : $this->activeContractFor(
                    apparatusId: (int) $apparatus->getKey(),
                    actorUserId: $actorUserId,
                    actorSessionHash: $actorSessionHash,
                    checklistHash: strtolower($checklistHash),
                    dutyDate: $issuedAt->toDateString(),
                    issuedAt: $issuedAt,
                    issuanceKey: $requestedIssuanceKey,
                );
            $existing ??= $this->activeContractFor(
                apparatusId: (int) $apparatus->getKey(),
                actorUserId: $actorUserId,
                actorSessionHash: $actorSessionHash,
                checklistHash: strtolower($checklistHash),
                dutyDate: $issuedAt->toDateString(),
                issuedAt: $issuedAt,
                issuanceKey: null,
            );
            if ($existing !== null) {
                return [
                    'session' => $existing,
                    'token' => $this->tokenFor($existing->public_id, $existing->replay_key),
                    'created' => false,
                ];
            }

            $publicId = (string) Str::uuid();
            $replayKey = (string) Str::uuid();
            $token = $this->tokenFor($publicId, $replayKey);
            $session = DailyCheckoutInspectionSession::query()->create([
                'public_id' => $publicId,
                'apparatus_id' => $apparatus->getKey(),
                'actor_user_id' => $actorUserId,
                'actor_session_hash' => $actorSessionHash,
                'issuance_key' => $sessionIssuanceKey,
                'issued_at' => $issuedAt,
                'duty_date' => $issuedAt->toDateString(),
                'checklist_template_id' => $templateId,
                'checklist_template_version' => $templateVersion,
                'checklist_hash' => strtolower($checklistHash),
                'checklist_snapshot' => $this->canonicalize($checklist),
                'due_tasks' => $normalizedDueTasks,
                'due_tasks_hash' => $this->canonicalHash($normalizedDueTasks),
                'replay_key' => $replayKey,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $issuedAt->addHours($this->expiryHours()),
            ]);

            return ['session' => $session, 'token' => $token, 'created' => true];
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

    private function pruneExpiredUnsubmitted(CarbonImmutable $issuedAt): void
    {
        DailyCheckoutInspectionSession::query()
            ->whereNull('submitted_inspection_id')
            ->where('expires_at', '<=', $issuedAt)
            ->delete();
    }

    private function activeContractFor(
        int $apparatusId,
        ?int $actorUserId,
        ?string $actorSessionHash,
        string $checklistHash,
        string $dutyDate,
        CarbonImmutable $issuedAt,
        ?string $issuanceKey,
    ): ?DailyCheckoutInspectionSession {
        $query = DailyCheckoutInspectionSession::query()
            ->where('apparatus_id', $apparatusId)
            ->whereNull('submitted_inspection_id')
            ->where('expires_at', '>', $issuedAt);

        if ($issuanceKey === null) {
            $query
                ->where('checklist_hash', $checklistHash)
                ->whereDate('duty_date', $dutyDate);
        } else {
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
        if (preg_match('/\\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\\z/', $normalized) !== 1) {
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
