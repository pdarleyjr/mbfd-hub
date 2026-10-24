<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\InventoryRequestMessage;
use App\Models\Ems\SupplyItem;
use App\Models\OperationalFormRecord;
use Illuminate\Support\Collection;

/** JSON shapes for requests. The member view hides stock detail and internal ids; the staff view shows everything. */
final class RequestPresenter
{
    public const AUDIENCE_MEMBER = 'member';

    public const AUDIENCE_STAFF = 'staff';

    private const STATUS_LABELS = [
        InventoryRequest::SUBMITTED => 'Submitted',
        InventoryRequest::REVIEWED => 'Reviewed',
        InventoryRequest::PREPARED => 'Prepared',
        InventoryRequest::PARTIALLY_ISSUED => 'Partly issued',
        InventoryRequest::ISSUED => 'Issued',
        InventoryRequest::CLOSED => 'Closed',
        InventoryRequest::CANCELLED => 'Cancelled',
    ];

    private const LINE_LABELS = [
        InventoryRequestLine::OPEN => 'Waiting',
        InventoryRequestLine::PREPARED => 'Prepared',
        InventoryRequestLine::PARTIALLY_ISSUED => 'Partly issued',
        InventoryRequestLine::ISSUED => 'Issued',
        InventoryRequestLine::NOT_ISSUED => 'Not issued',
    ];

    private const CONTEXT_LABELS = [
        InventoryRequestEvent::CONTEXT_MEMBER => 'Member',
        InventoryRequestEvent::CONTEXT_CAPTAIN5 => 'Captain 5',
        InventoryRequestEvent::CONTEXT_EMS_ADMIN => 'EMS Admin',
        InventoryRequestEvent::CONTEXT_SYSTEM => 'System',
    ];

    /** @return array<string, mixed> */
    public function summary(InventoryRequest $request): array
    {
        return [
            'id' => $request->uuid,
            'number' => $request->number,
            'status' => $request->status,
            'status_label' => self::STATUS_LABELS[$request->status] ?? $request->status,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'submitter_name' => $request->submitter_name,
            'submitter_rank' => $request->submitter_rank,
            'target_kind' => $request->target_kind,
            'target_label' => $request->target_label,
            'apparatus_id' => $request->apparatus_id,
            'event_assignment' => $request->event_assignment,
            'line_count' => (int) ($request->lines_count ?? $request->lines()->count()),
            'issued_line_count' => (int) ($request->issued_lines_count ?? $request->lines()->where('issued_qty', '>', 0)->count()),
            'has_note' => trim((string) $request->member_note) !== '',
            'last_activity_at' => $request->last_activity_at?->toIso8601String(),
            'closed_at' => $request->closed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(InventoryRequest $request, string $audience, bool $withUrls = true): array
    {
        $request->loadMissing(['lines', 'events', 'messages']);
        $staff = $audience === self::AUDIENCE_STAFF;

        $data = $this->summary($request) + [
            'member_note' => $request->member_note,
            'lines' => $this->lines($request->lines, $staff),
            'history' => $this->history($request->events, $staff),
            'messages' => $request->messages->map(fn (InventoryRequestMessage $m): array => $this->message($m))->values()->all(),
            'close_reason' => $request->close_reason,
            'cancel_reason' => $request->cancel_reason,
            'files_copy' => $this->filesCopy($request, $withUrls && ! $staff),
        ];

        if ($staff) {
            $data += [
                'submitted_by_user_id' => $request->submitted_by_user_id,
                'submitted_by_employee_id' => $request->submitted_by_employee_id,
                'source_form_versions' => $request->source_form_versions,
                'snapshot_sha256' => $request->snapshot_sha256,
                'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            ];
        } else {
            $data['can_cancel'] = $this->memberCanCancel($request);
        }

        return $data;
    }

    public function memberCanCancel(InventoryRequest $request): bool
    {
        $request->loadMissing('lines');

        return in_array($request->status, [InventoryRequest::SUBMITTED, InventoryRequest::REVIEWED, InventoryRequest::PREPARED], true)
            && $request->lines->sum('issued_qty') === 0;
    }

    /**
     * @param  Collection<int, InventoryRequestLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $lines, bool $staff): array
    {
        $candidates = $staff ? $this->candidates($lines) : [];

        return $lines->map(function (InventoryRequestLine $line) use ($staff, $candidates): array {
            $row = [
                'id' => $line->getKey(),
                'bag_key' => $line->bag_key,
                'item_key' => $line->item_key,
                'label' => $line->label,
                'section_label' => $line->section_label,
                'variant_label' => $line->variant_label,
                'min_text' => $line->min_text,
                'unit' => $line->unit,
                'response_type' => $line->response_type,
                'requested_qty' => $line->requested_qty,
                'requested_flag' => $line->requested_flag,
                'prepared_qty' => $line->prepared_qty,
                'issued_qty' => $line->issued_qty,
                'line_status' => $line->line_status,
                'line_status_label' => self::LINE_LABELS[$line->line_status] ?? $line->line_status,
                'not_issued_reason' => $line->not_issued_reason,
                'refrigerated' => $line->flag('refrigerated'),
                'controlled_substance' => $line->flag('controlled_substance'),
            ];
            if ($staff) {
                $row['catalog_item_id'] = $line->catalog_item_id;
                $row['supply_candidates'] = $candidates[$line->catalog_item_id] ?? [];
            }

            return $row;
        })->values()->all();
    }

    /**
     * Supply room items a line could be issued from (same catalog identity, never a name guess).
     *
     * @param  Collection<int, InventoryRequestLine>  $lines
     * @return array<int, list<array<string, mixed>>>
     */
    private function candidates(Collection $lines): array
    {
        $ids = $lines->pluck('catalog_item_id')->filter()->unique()->all();
        if ($ids === []) {
            return [];
        }

        return SupplyItem::query()->with('location:id,code')->whereIn('catalog_item_id', $ids)
            ->where('active', true)->where('controlled_substance', false)->orderBy('name')->get()
            ->groupBy('catalog_item_id')
            ->map(fn (Collection $group): array => $group->map(fn (SupplyItem $item): array => [
                'id' => $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code,
                'package_unit' => $item->package_unit, 'units_per_package' => $item->units_per_package,
            ])->values()->all())
            ->all();
    }

    /**
     * @param  Collection<int, InventoryRequestEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function history(Collection $events, bool $staff): array
    {
        return $events
            ->filter(fn (InventoryRequestEvent $e): bool => $staff || in_array($e->action, InventoryRequestEvent::MEMBER_VISIBLE, true))
            ->map(fn (InventoryRequestEvent $e): array => [
                'id' => $e->getKey(),
                'action' => $e->action,
                'actor_name' => $e->actor_name,
                'actor_rank' => $e->actor_rank,
                'actor_context' => $e->actor_context,
                'actor_context_label' => self::CONTEXT_LABELS[$e->actor_context] ?? $e->actor_context,
                'request_line_id' => $e->request_line_id,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'payload' => $staff ? ($e->payload ?? []) : $this->memberPayload($e),
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @return array<string, mixed> only what a member needs: quantities and reasons, never stock detail. */
    private function memberPayload(InventoryRequestEvent $event): array
    {
        $payload = (array) ($event->payload ?? []);

        return array_filter([
            'quantity' => $payload['quantity'] ?? $payload['new_quantity'] ?? null,
            // Over-issue reasons and correction notes are staff-internal; members see why something was not issued, closed or cancelled.
            'reason' => in_array($event->action, ['not_issued', 'closed', 'cancelled'], true) ? ($payload['reason'] ?? null) : null,
            'label' => $payload['label'] ?? null,
        ], fn ($value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function message(InventoryRequestMessage $message): array
    {
        return [
            'id' => $message->getKey(),
            'author_name' => $message->author_name,
            'author_rank' => $message->author_rank,
            'author_context' => $message->author_context,
            'author_context_label' => self::CONTEXT_LABELS[$message->author_context] ?? $message->author_context,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function filesCopy(InventoryRequest $request, bool $withUrls): array
    {
        $record = $request->operational_form_record_id !== null
            ? OperationalFormRecord::query()->with('latestDocument')->find($request->operational_form_record_id)
            : null;
        $document = $record?->latestDocument;
        if ($document === null) {
            return ['status' => 'pending', 'record_id' => $record?->id, 'document_id' => null];
        }

        return array_filter([
            'status' => 'ready',
            'record_id' => $record->id,
            'document_id' => $document->id,
            'display_name' => $document->display_name,
            'preview_url' => $withUrls ? route('employee.forms.api.documents.preview', $document) : null,
            'download_url' => $withUrls ? route('employee.forms.api.documents.download', $document) : null,
        ], fn ($value): bool => $value !== null);
    }
}
