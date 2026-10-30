<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use Illuminate\Support\Str;

/**
 * Shapes a narcotics check for the two audiences. Times are ISO-8601 UTC strings (the apps format them
 * in Eastern time). Photo URLs are relative, point at the authorized image route and carry only the
 * check's random UUID: there is no file path, disk name or hash in anything sent to a browser.
 */
final class NarcoticsCheckPresenter
{
    public const AUDIENCE_MEMBER = 'member';

    public const AUDIENCE_STAFF = 'staff';

    private const MEMBER_LABELS = [
        NarcoticsCheck::PENDING => 'Sent to Captain 5',
        NarcoticsCheck::APPROVED => 'Approved',
        NarcoticsCheck::REJECTED => 'Rejected',
    ];

    private const STAFF_LABELS = [
        NarcoticsCheck::PENDING => 'Pending review',
        NarcoticsCheck::APPROVED => 'Approved',
        NarcoticsCheck::REJECTED => 'Rejected',
    ];

    /** @return array<string, mixed> */
    public function summary(NarcoticsCheck $check, string $audience): array
    {
        $staff = $audience === self::AUDIENCE_STAFF;
        $labels = $staff ? self::STAFF_LABELS : self::MEMBER_LABELS;
        $resubmitted = $check->relationLoaded('resubmission')
            ? $check->resubmission
            : $check->resubmission()->first(['uuid']);

        return [
            'id' => $check->uuid,
            'status' => $check->status,
            'status_label' => $labels[$check->status] ?? Str::headline($check->status),
            // "Archived" is not a second status: a decided check leaves the pending inbox and stays searchable.
            'archived' => $check->archived_at !== null,
            'archived_at' => $check->archived_at?->toIso8601String(),
            'submitted_at' => $check->submitted_at->toIso8601String(),
            'decided_at' => $check->decided_at?->toIso8601String(),
            'submitter_name' => $check->submitter_name,
            'submitter_rank' => $check->submitter_rank,
            'has_note' => trim((string) $check->member_note) !== '',
            'member_note' => $check->member_note,
            'decision_note' => $check->decision_note,
            'decided_by_name' => $check->decided_by_name,
            'decided_by_rank' => $check->decided_by_rank,
            'resubmission_of' => $check->resubmission_of_id === null ? null : ($check->relationLoaded('resubmissionOf')
                ? $check->resubmissionOf?->uuid
                : NarcoticsCheck::query()->whereKey($check->resubmission_of_id)->value('uuid')),
            'resubmitted_as' => $resubmitted?->uuid,
            'can_resubmit' => ! $staff && $check->status === NarcoticsCheck::REJECTED && $resubmitted === null,
            'image' => $this->image($check),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(NarcoticsCheck $check, string $audience): array
    {
        $events = $check->events()->get();
        if ($audience !== self::AUDIENCE_STAFF) {
            $events = $events->filter(fn (NarcoticsCheckEvent $event): bool => in_array($event->action, NarcoticsCheckEvent::MEMBER_VISIBLE, true));
        }

        return $this->summary($check, $audience) + [
            'history' => $events->map(fn (NarcoticsCheckEvent $event): array => [
                'action' => $event->action,
                'actor_name' => $event->actor_name,
                'actor_rank' => $event->actor_rank,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function image(NarcoticsCheck $check): array
    {
        $available = $check->imagesAvailable();
        $url = fn (string $variant): ?string => $available
            ? route('narcotics.api.checks.image', ['uuid' => $check->uuid, 'variant' => $variant], false)
            : null;

        return [
            'available' => $available,
            'thumb_url' => $url('thumb'),
            'preview_url' => $url('preview'),
            'full_url' => $url('full'),
            'width' => $check->image_width,
            'height' => $check->image_height,
            'bytes' => $check->image_bytes,
            'mime' => $check->image_mime,
        ];
    }
}
