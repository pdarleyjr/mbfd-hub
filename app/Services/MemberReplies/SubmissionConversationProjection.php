<?php

declare(strict_types=1);

namespace App\Services\MemberReplies;

use App\Models\ApparatusServiceTicket;
use App\Models\ApparatusServiceTicketUpdate;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\StationSupplyRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The member's view of one station request, supply request or service ticket: what they sent, every public
 * update staff (or the Support Services receipt) gave, and the conversation. Built from an allowlist: internal
 * notes (`internal_note`, `admin_notes`, `status_detail`) are never read (docs/member-replies.md section 2.3).
 */
final class SubmissionConversationProjection
{
    public function __construct(private readonly SubmissionConversationService $conversations) {}

    /**
     * @return array{type: string, id: int, reference: string, title: string, description: string|null, status: string,
     *     is_open: bool, can_reply: bool, updates: list<array{text: string, from: string, at: CarbonImmutable|null}>,
     *     messages: list<array<string, mixed>>}
     */
    public function memberView(User $viewer, string $type, Model $subject): array
    {
        $base = match (true) {
            $subject instanceof StationRequest => [
                'reference' => (string) ($subject->request_number ?: 'Station request #'.$subject->getKey()),
                'title' => (string) $subject->title,
                'description' => $subject->description,
                'status' => str((string) $subject->status)->replace('_', ' ')->title()->toString(),
                'is_open' => (bool) $subject->is_open,
                'updates' => $this->stationUpdates($subject),
            ],
            $subject instanceof ApparatusServiceTicket => [
                'reference' => (string) ($subject->ticket_number ?: 'Service ticket #'.$subject->getKey()),
                'title' => trim($subject->unit_designation_snapshot.' · '.$subject->title, ' ·'),
                'description' => $subject->description,
                'status' => str((string) $subject->status)->replace('_', ' ')->title()->toString(),
                'is_open' => (bool) $subject->is_open,
                'updates' => $this->ticketUpdates($subject),
            ],
            $subject instanceof StationSupplyRequest => [
                'reference' => 'Supply request #'.$subject->getKey(),
                'title' => 'Supply request',
                'description' => $subject->request_text,
                'status' => str((string) $subject->status)->title()->toString(),
                'is_open' => (string) $subject->status === 'open',
                'updates' => $this->supplyUpdates($subject),
            ],
            default => throw new \InvalidArgumentException('Unsupported conversation subject.'),
        };

        return [
            'type' => $type,
            'id' => (int) $subject->getKey(),
            ...$base,
            'can_reply' => $subject->getAttribute('archived_at') === null,
            'messages' => $this->conversations->thread($viewer, $type, $subject),
        ];
    }

    /** @return list<array{text: string, from: string, at: CarbonImmutable|null}> */
    private function stationUpdates(StationRequest $request): array
    {
        return $request->updates()
            ->select(['id', 'station_request_id', 'public_note', 'automation_intake_id', 'metadata', 'created_at'])
            ->whereNotNull('public_note')->where('public_note', '<>', '')
            ->orderBy('id')->get()
            ->map(fn (StationRequestUpdate $update): array => [
                'text' => (string) $update->public_note,
                'from' => $update->isAutoReceipt() ? StationRequestUpdate::autoReceiptSender() : (data_get($update->metadata, 'event') === 'member_resolved' ? 'A crew member' : 'Support Services'),
                'at' => $this->moment($update->created_at),
            ])->values()->all();
    }

    /** @return list<array{text: string, from: string, at: CarbonImmutable|null}> */
    private function ticketUpdates(ApparatusServiceTicket $ticket): array
    {
        return $ticket->updates()
            ->select(['id', 'apparatus_service_ticket_id', 'public_note', 'created_at'])
            ->whereNotNull('public_note')->where('public_note', '<>', '')
            ->orderBy('id')->get()
            ->map(fn (ApparatusServiceTicketUpdate $update): array => [
                'text' => (string) $update->public_note,
                'from' => 'Fleet',
                'at' => $this->moment($update->created_at),
            ])->values()->all();
    }

    /** @return list<array{text: string, from: string, at: CarbonImmutable|null}> */
    private function supplyUpdates(StationSupplyRequest $request): array
    {
        $updates = [];
        if (filled($request->getAttribute('ai_receipt_text'))) {
            $updates[] = ['text' => (string) $request->getAttribute('ai_receipt_text'), 'from' => StationRequestUpdate::autoReceiptSender(), 'at' => $this->moment($request->getAttribute('ai_receipt_at'))];
        }
        if (filled($request->public_response)) {
            $updates[] = ['text' => (string) $request->public_response, 'from' => 'Support Services', 'at' => $this->moment($request->updated_at)];
        }

        return $updates;
    }

    private function moment(mixed $value): ?CarbonImmutable
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : null;
    }
}
