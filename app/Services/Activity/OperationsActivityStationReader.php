<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Enums\StationRequestStatus;
use App\Filament\Resources\StationRequestResource;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Services\Dashboards\Sources\Station\StationImageKey;
use Carbon\CarbonImmutable;

/**
 * Station request history (station_request_updates) for the Latest activity card.
 * Same gate as StationRequestSource and StationRequestResource: admin.stations.view. Archived requests are out of
 * scope. A row written by the assistant (automation_intake_id set) is an automated receipt: labelled as such and
 * never "needs action". A new request needs action while it is still pending.
 */
final class OperationsActivityStationReader
{
    public static function canRead(User $user): bool
    {
        return $user->can('admin.stations.view');
    }

    /** @return list<OperationsActivityEvent> */
    public function events(User $user, CarbonImmutable $since, int $limit): array
    {
        if (! self::canRead($user)) {
            return [];
        }
        $rows = StationRequestUpdate::query()
            ->where('created_at', '>=', $since)
            ->with(['stationRequest.station:id,station_number,name', 'changedBy:id,name,display_name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $firstIds = $this->firstUpdateIds($rows->pluck('station_request_id')->unique()->all());

        $out = [];
        foreach ($rows as $row) {
            $request = $row->stationRequest;
            if ($request instanceof StationRequest && ! $request->isArchived()) {
                $out[] = $this->fromUpdate($row, $request, ($firstIds[$row->station_request_id] ?? null) === $row->getKey());
            }
        }

        return $out;
    }

    private function fromUpdate(StationRequestUpdate $update, StationRequest $request, bool $isFirst): OperationsActivityEvent
    {
        $automated = $update->isAutoReceipt();
        $metadata = is_array($update->metadata) ? $update->metadata : [];
        $memberResolved = ($metadata['event'] ?? null) === 'member_resolved';
        $isNew = $isFirst && $update->status === StationRequestStatus::Pending->value && ! $automated;
        $public = trim((string) $update->public_note);
        $internalNote = trim((string) $update->internal_note);
        $status = StationRequestStatus::tryFrom((string) $request->status);

        return new OperationsActivityEvent(
            key: 'station_request_updates:'.$update->getKey(),
            domain: OperationsActivityEvent::DOMAIN_STATIONS,
            kind: $this->kindOf($update, $automated, $memberResolved, $isNew),
            subject: StationImageKey::label($request->station) ?? (trim((string) $request->request_number) ?: null),
            summary: OperationsActivityText::lead(
                (string) $request->title,
                $public !== '' ? $public : ($internalNote !== '' ? $internalNote : ($isNew ? (string) $request->description : '')),
            ),
            actorLabel: $automated
                ? OperationsActivityText::AUTOMATED_ACTOR
                : (OperationsActivityText::person($update->changedBy) ?? ($isNew ? (trim((string) $request->requester_name_snapshot) ?: 'Station') : 'System')),
            at: CarbonImmutable::instance($update->created_at),
            statusLabel: $status?->label() ?? (string) $request->status,
            actionRequired: ! $automated && $isNew && $request->status === StationRequestStatus::Pending->value,
            internal: ! $automated && $public === '' && $internalNote !== '',
            automated: $automated,
            message: $automated || $public !== '',
            ownerTag: null,
            url: StationRequestResource::getUrl('view', ['record' => $request->getKey()], panel: 'admin'),
        );
    }

    private function kindOf(StationRequestUpdate $update, bool $automated, bool $memberResolved, bool $isNew): string
    {
        return match (true) {
            $automated => 'Support Services reply',
            $memberResolved => 'Request resolved by member',
            $isNew => 'New request',
            $update->status === StationRequestStatus::Completed->value => 'Completed',
            $update->status === StationRequestStatus::Denied->value => 'Denied',
            $update->status === StationRequestStatus::Cancelled->value => 'Cancelled',
            default => 'Status update',
        };
    }

    /**
     * The first update of each request (its submission), in one query.
     *
     * @param  list<int>  $requestIds
     * @return array<int, int> request id => update id
     */
    private function firstUpdateIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        return StationRequestUpdate::query()
            ->whereIn('station_request_id', $requestIds)
            ->selectRaw('station_request_id, MIN(id) as first_id')
            ->groupBy('station_request_id')
            ->pluck('first_id', 'station_request_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
