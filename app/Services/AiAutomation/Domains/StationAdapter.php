<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Domains;

use App\Enums\StationRequestStatus;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\StationSupplyRequest;
use App\Services\AiAutomation\BacklogCandidate;
use App\Services\AiAutomation\Contracts\DomainAdapter;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ReplyRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Station requests (repair/service and equipment) and station supply requests.
 *
 * The receipt means "the assistant received this", nothing more. It is one append-only history row on a
 * station request (status copied, never changed; no staff identity; not an acknowledgment) or, for a supply
 * request that has no history, three receipt columns on the request itself. Nothing here calls the workflow
 * service, sends email, fires the staff notifications, or touches status, priority, acknowledged_*,
 * current_public_response, public_response or updated_at.
 */
final class StationAdapter implements DomainAdapter
{
    public const SOURCE_REQUEST = 'station_request';

    public const SOURCE_SUPPLY = 'station_supply_request';

    /** Categories that belong to EMS, narcotics or personnel/PPE entitlement work, never to station work. */
    private const OUT_OF_SCOPE = '/\b(ems|medical|medic|narcotics?|controlled\s+substances?|medications?|drugs?|ppe|uniforms?|turnout|bunker\s*gear)\b/i';

    private const BACKLOG_SCAN_FACTOR = 3;

    private const BACKLOG_MAX_BATCHES = 20;

    public function domain(): string
    {
        return 'station';
    }

    public function sourceTypes(): array
    {
        return [self::SOURCE_REQUEST, self::SOURCE_SUPPLY];
    }

    public function facts(AiIntakeEvent $intake): IntakeFacts
    {
        return match ($intake->source_type) {
            self::SOURCE_SUPPLY => $this->supplyFacts((int) $intake->source_id),
            self::SOURCE_REQUEST => $this->requestFacts((int) $intake->source_id),
            default => new IntakeFacts('station', 'station_request', null, ineligibleReason: 'unknown_source_type'),
        };
    }

    public function writeReply(AiIntakeEvent $intake, IntakeFacts $facts, string $text): ReplyRecord
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('An empty station receipt cannot be written.');
        }

        return $intake->source_type === self::SOURCE_SUPPLY
            ? $this->writeSupplyReceipt($intake, $text)
            : $this->writeRequestReceipt($intake, $text);
    }

    public function memberUrl(AiIntakeEvent $intake): ?string
    {
        if ($intake->source_type === self::SOURCE_SUPPLY) {
            return '/daily/forms-hub/station-inventory';
        }

        $stationId = StationRequest::query()->whereKey((int) $intake->source_id)->value('station_id');

        return $stationId === null ? null : '/daily/stations/'.(int) $stationId;
    }

    public function backlogSourceTypes(): array
    {
        return [self::SOURCE_REQUEST, self::SOURCE_SUPPLY];
    }

    public function backlogCandidates(string $sourceType, int $afterId, int $limit): array
    {
        if ($limit < 1 || ! in_array($sourceType, $this->sourceTypes(), true)) {
            return [];
        }

        $candidates = [];
        $cursor = $afterId;
        for ($batch = 0; $batch < self::BACKLOG_MAX_BATCHES && count($candidates) < $limit; $batch++) {
            $rows = $sourceType === self::SOURCE_SUPPLY
                ? $this->unattendedSupplyRequests($cursor, $limit * self::BACKLOG_SCAN_FACTOR)
                : $this->unattendedRequests($cursor, $limit * self::BACKLOG_SCAN_FACTOR);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = (int) $row->getKey();
                $inScope = $row instanceof StationRequest ? $this->requestIneligibleReason($row) === null : true;
                if ($inScope) {
                    $candidates[] = new BacklogCandidate($sourceType, $cursor, 'received', $row->created_at);
                    if (count($candidates) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $candidates;
    }

    // ---- station requests ------------------------------------------------------------------------------------

    private function requestFacts(int $id): IntakeFacts
    {
        $request = StationRequest::query()
            ->with('items:id,station_request_id,item_name,category')
            ->find($id);
        if (! $request instanceof StationRequest) {
            return new IntakeFacts('station', 'station_request', null, ineligibleReason: 'source_missing');
        }

        $reason = $this->requestIneligibleReason($request);

        return new IntakeFacts(
            domain: 'station',
            kind: 'station_request',
            requesterUserId: $request->actor_user_id !== null ? (int) $request->actor_user_id : null,
            memberText: trim($request->title."\n".$request->description),
            category: $this->requestCategory($request),
            alreadyAnswered: $reason === null && $this->requestAnswered($request),
            ineligibleReason: $reason,
            // An open, active request sits in the Station Requests worklist (admin.stations.view) and
            // StationRequestSideEffectService::requestCreated alerts the station_requests audience.
            routed: $reason === null,
            submittedAt: $request->created_at,
        );
    }

    /** Why this request is not something to answer, or null. Never looks at who answered it. */
    private function requestIneligibleReason(StationRequest $request): ?string
    {
        $metadata = $request->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        return match (true) {
            $request->isArchived() => 'archived',
            in_array($request->status, StationRequestStatus::terminalValues(), true) => 'request_'.$request->status,
            ! in_array($request->status, StationRequestStatus::openValues(), true) => 'request_status_unknown',
            $request->legacy_source !== null => 'legacy_import',
            ($metadata['is_test'] ?? false) === true || ($metadata['test'] ?? false) === true => 'test_data',
            $request->actor_user_id === null => 'no_member_account',
            $this->outOfScope($request) => 'out_of_scope_category',
            default => null,
        };
    }

    private function outOfScope(StationRequest $request): bool
    {
        $labels = $request->items->pluck('category')->push($request->subject_type);
        if ($request->request_type === 'equipment') {
            $labels = $labels->merge($request->items->pluck('item_name'));
        }

        return $labels->filter(fn ($label): bool => is_string($label) && $label !== '')
            ->contains(fn (string $label): bool => preg_match(self::OUT_OF_SCOPE, $label) === 1);
    }

    /** The category the member picked: the first item's, else the subject (room / facility / equipment_item). */
    private function requestCategory(StationRequest $request): ?string
    {
        $fromItem = $request->items->pluck('category')->first(fn ($value): bool => is_string($value) && $value !== '');

        return $fromItem ?? (filled($request->subject_type) ? (string) $request->subject_type : null);
    }

    /** A person already responded (note, or moved it off pending / acknowledged it), or the assistant already did. */
    private function requestAnswered(StationRequest $request): bool
    {
        return $request->acknowledged_at !== null
            || $request->updates()->autoReceipts()->exists()
            || $this->humanResponseQuery($request->updates()->withoutAutoReceipts())->exists();
    }

    /** Narrow a StationRequestUpdate query (or relation) to rows where a person responded to the member. */
    private function humanResponseQuery(Builder|HasMany $query): Builder|HasMany
    {
        return $query->whereNotNull('changed_by_user_id')
            ->where(fn ($response) => $response
                ->where(fn ($note) => $note->whereNotNull('public_note')->where('public_note', '!=', ''))
                ->orWhere('status', '!=', StationRequestStatus::Pending->value));
    }

    /** @return list<StationRequest> */
    private function unattendedRequests(int $afterId, int $take): array
    {
        return StationRequest::query()
            ->open()
            ->where('id', '>', $afterId)
            ->whereNull('legacy_source')
            ->whereNotNull('actor_user_id')
            ->whereNull('acknowledged_at')
            ->whereDoesntHave('updates', fn ($updates) => $updates->where(fn ($any) => $any
                ->whereNotNull('automation_intake_id')
                ->orWhere(fn ($human) => $this->humanResponseQuery($human))))
            ->with('items:id,station_request_id,item_name,category')
            ->orderBy('id')
            ->limit($take)
            ->get()
            ->all();
    }

    private function writeRequestReceipt(AiIntakeEvent $intake, string $text): ReplyRecord
    {
        /** @var StationRequest|null $request */
        $request = StationRequest::query()->lockForUpdate()->find((int) $intake->source_id);
        if ($request === null) {
            throw new RuntimeException('The station request no longer exists.');
        }

        $existing = StationRequestUpdate::query()
            ->where('station_request_id', $request->id)
            ->autoReceipts()
            ->orderBy('id')
            ->first();
        if ($existing instanceof StationRequestUpdate) {
            return new ReplyRecord('station_request_update', (int) $existing->id);
        }

        // Direct append: the status is copied, so the history shows no change, and the request row is not saved.
        // forceFill: automation_intake_id is deliberately not mass-assignable.
        $update = (new StationRequestUpdate)->forceFill([
            'station_request_id' => $request->id,
            'status' => $request->status,
            'public_note' => $text,
            'changed_by_user_id' => null,
            'automation_intake_id' => (int) $intake->getKey(),
            'metadata' => [
                'event' => StationRequestUpdate::AUTO_RECEIPT_EVENT,
                'automation' => [
                    'intake_id' => (int) $intake->getKey(),
                    'sender' => StationRequestUpdate::autoReceiptSender(forStaff: true),
                ],
            ],
        ]);
        $update->save();

        return new ReplyRecord('station_request_update', (int) $update->id);
    }

    // ---- station supply requests -----------------------------------------------------------------------------

    private function supplyFacts(int $id): IntakeFacts
    {
        $supply = StationSupplyRequest::query()->find($id);
        if (! $supply instanceof StationSupplyRequest) {
            return new IntakeFacts('station', 'supply_request', null, ineligibleReason: 'source_missing');
        }

        $reason = match (true) {
            $supply->isArchived() => 'archived',
            $supply->status !== 'open' => 'request_'.$supply->status,
            $supply->actor_user_id === null => 'no_member_account',
            default => null,
        };

        return new IntakeFacts(
            domain: 'station',
            kind: 'supply_request',
            requesterUserId: $supply->actor_user_id !== null ? (int) $supply->actor_user_id : null,
            memberText: (string) $supply->request_text,
            category: 'station_supply',
            alreadyAnswered: $reason === null && (filled($supply->public_response) || $supply->ai_receipt_intake_id !== null),
            ineligibleReason: $reason,
            // Supply requests raise no staff alert; they only appear on the station's Supply Requests tab.
            routed: false,
            submittedAt: $supply->created_at,
        );
    }

    /** @return list<StationSupplyRequest> */
    private function unattendedSupplyRequests(int $afterId, int $take): array
    {
        return StationSupplyRequest::query()
            ->open()
            ->where('id', '>', $afterId)
            ->whereNotNull('actor_user_id')
            ->whereNull('ai_receipt_intake_id')
            ->where(fn ($answer) => $answer->whereNull('public_response')->orWhere('public_response', ''))
            ->orderBy('id')
            ->limit($take)
            ->get()
            ->all();
    }

    private function writeSupplyReceipt(AiIntakeEvent $intake, string $text): ReplyRecord
    {
        /** @var StationSupplyRequest|null $supply */
        $supply = StationSupplyRequest::query()->lockForUpdate()->find((int) $intake->source_id);
        if ($supply === null) {
            throw new RuntimeException('The station supply request no longer exists.');
        }
        if ($supply->ai_receipt_intake_id !== null) {
            return new ReplyRecord('station_supply_request_receipt', (int) $supply->id);
        }

        // Query-builder write on purpose: no model events, and updated_at (a staff concurrency signal) is untouched.
        DB::table('station_supply_requests')->where('id', $supply->id)->update([
            'ai_receipt_text' => $text,
            'ai_receipt_at' => now(),
            'ai_receipt_intake_id' => (int) $intake->getKey(),
        ]);

        return new ReplyRecord('station_supply_request_receipt', (int) $supply->id);
    }
}
