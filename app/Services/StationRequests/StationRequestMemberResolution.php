<?php

declare(strict_types=1);

namespace App\Services\StationRequests;

use App\Enums\StationRequestStatus;
use App\Models\Employee;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Services\StationRequestSideEffectService;
use App\Support\Stations\StationRequestMemberAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A member closes a station request from the Daily app: "it is fixed", "we no longer need it", "it arrived".
 *
 * One locked transaction: the request becomes completed with status_detail member_resolved, and exactly one
 * append-only history row records who did it. No room asset is created, linked, retired or repaired here; that
 * stays a staff job done from the full record. A staff member can reopen it (StationRequestWorkflowService).
 */
class StationRequestMemberResolution
{
    public const EVENT = 'member_resolved';

    public const STATUS_DETAIL = 'member_resolved';

    /** @var array<string, string> */
    public const OUTCOMES = [
        'fixed' => 'Fixed',
        'no_longer_needed' => 'No longer needed',
        'received' => 'Received',
        'other' => 'Other',
    ];

    public function __construct(private readonly StationRequestSideEffectService $sideEffects) {}

    /**
     * @return array{status: string, status_label: string, message: string, replayed: bool}
     *
     * @throws StationRequestMemberResolveRefused
     * @throws ValidationException
     */
    public function resolve(StationRequest $stationRequest, User $member, string $clientActionId, string $outcome, ?string $note): array
    {
        if (! StationRequestMemberAccess::isEligibleMember($member)) {
            throw StationRequestMemberResolveRefused::notEligible();
        }
        if (! array_key_exists($outcome, self::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => 'Choose Fixed, No longer needed, Received or Other.']);
        }
        $note = $this->cleanNote($note);

        return DB::transaction(function () use ($stationRequest, $member, $clientActionId, $outcome, $note): array {
            /** @var StationRequest $request */
            $request = StationRequest::query()->lockForUpdate()->findOrFail($stationRequest->getKey());

            $earlier = $this->earlierUpdate($request, $clientActionId);
            if ($earlier instanceof StationRequestUpdate) {
                if ((int) $earlier->changed_by_user_id !== (int) $member->getKey()) {
                    throw StationRequestMemberResolveRefused::clientActionReused();
                }

                return $this->result(true);
            }

            if (! StationRequestMemberAccess::mayResolve($member, $request)) {
                $status = StationRequestStatus::tryFrom((string) $request->status);

                throw StationRequestMemberResolveRefused::closed((string) $request->status, $status?->label() ?? (string) $request->status);
            }

            $isRequester = StationRequestMemberAccess::isRequester($member, $request);
            if (! $isRequester && mb_strlen((string) $note) < StationRequestMemberAccess::MIN_OTHER_NOTE_LENGTH) {
                throw ValidationException::withMessages([
                    'note' => 'Say what you did. A few words is enough.',
                ]);
            }

            $line = $this->publicLine($member, $outcome, $note);
            $request->update([
                'status' => StationRequestStatus::Completed->value,
                'status_detail' => self::STATUS_DETAIL,
                'completed_at' => now(),
                'current_public_response' => $line,
            ]);
            /** @var StationRequestUpdate $update */
            $update = $request->updates()->create([
                'status' => StationRequestStatus::Completed->value,
                'public_note' => $line,
                'internal_note' => null,
                'changed_by_user_id' => $member->getKey(),
                'metadata' => [
                    'event' => self::EVENT,
                    'outcome' => $outcome,
                    'client_action_id' => $clientActionId,
                    'resolver_is_requester' => $isRequester,
                ],
            ]);

            DB::afterCommit(fn () => $this->sideEffects->memberResolved($request, $update, $member, $isRequester, self::OUTCOMES[$outcome]));

            return $this->result(false);
        }, 3);
    }

    private function earlierUpdate(StationRequest $request, string $clientActionId): ?StationRequestUpdate
    {
        return $request->updates()
            ->where('metadata->event', self::EVENT)
            ->where('metadata->client_action_id', $clientActionId)
            ->first();
    }

    /** @return array{status: string, status_label: string, message: string, replayed: bool} */
    private function result(bool $replayed): array
    {
        return [
            'status' => StationRequestStatus::Completed->value,
            'status_label' => StationRequestStatus::Completed->label(),
            'message' => 'Marked resolved. Thank you.',
            'replayed' => $replayed,
        ];
    }

    /** "Marked resolved by Pat Smith: Fixed. Replaced the bulb." */
    private function publicLine(User $member, string $outcome, ?string $note): string
    {
        $profile = $member->getRelationValue('employeeProfile');
        $profileName = $profile instanceof Employee ? $profile->name : null;
        $name = trim((string) ($profileName ?? $member->name));
        $line = sprintf('Marked resolved by %s: %s.', $name !== '' ? $name : 'a member', self::OUTCOMES[$outcome]);

        return $note === null ? $line : $line.' '.$note;
    }

    private function cleanNote(?string $note): ?string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $note));

        return $clean === '' ? null : $clean;
    }
}
