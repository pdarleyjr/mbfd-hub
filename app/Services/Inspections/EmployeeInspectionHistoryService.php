<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Enums\ApparatusServiceTicketStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\User;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only projection of a submitted Daily Checkout for the employee who
 * submitted it. Everything here is derived from the immutable stored evidence
 * (`results`) plus the current, publicly describable follow-up state.
 *
 * Ownership is decided only from foreign keys: actor_user_id or employee_id.
 * Free-text operator_name snapshots are never used to attribute a record.
 */
final class EmployeeInspectionHistoryService
{
    public const TIMEZONE = 'America/New_York';

    /** Directories under the public disk this feature may ever serve. */
    private const IMAGE_DIRECTORIES = ['signatures/', 'defects/'];

    private const FINDING_STATUSES = ['Missing', 'Damaged'];

    /** Observation kinds that mean "the inspector reported this as a finding". */
    private const REPORTED_OBSERVATIONS = ['first_reported', 'confirmed_again'];

    /**
     * Inspections attributed to this user by foreign key only.
     *
     * @return Builder<ApparatusInspection>
     */
    public function ownedQuery(User $user): Builder
    {
        $userId = $user->getKey();
        $employeeId = $user->employee_profile_id;

        return ApparatusInspection::query()->where(function (Builder $owned) use ($userId, $employeeId): void {
            $owned->where('apparatus_inspections.actor_user_id', $userId);

            if ($employeeId !== null) {
                $owned->orWhere('apparatus_inspections.employee_id', $employeeId);
            }
        });
    }

    public function owns(User $user, ApparatusInspection $inspection): bool
    {
        $userId = $user->getKey();
        if ($userId !== null && $inspection->actor_user_id !== null
            && (int) $inspection->actor_user_id === (int) $userId) {
            return true;
        }

        $employeeId = $user->employee_profile_id;

        return $employeeId !== null && $inspection->employee_id !== null
            && (int) $inspection->employee_id === (int) $employeeId;
    }

    /**
     * Adds follow-up counters for the list without a query per row.
     * A defect belongs to an inspection when it was created by it or when
     * the inspection recorded an observation of it.
     *
     * @param  Builder<ApparatusInspection>  $query
     * @return Builder<ApparatusInspection>
     */
    public function withFollowUpCounts(Builder $query): Builder
    {
        $defects = fn (bool $openOnly) => DB::table('apparatus_defects as d')
            ->selectRaw('count(*)')
            ->where(function ($scope): void {
                $scope->whereColumn('d.apparatus_inspection_id', 'apparatus_inspections.id')
                    ->orWhereExists(function ($observed): void {
                        $observed->selectRaw('1')
                            ->from('apparatus_defect_observations as o')
                            ->whereColumn('o.apparatus_defect_id', 'd.id')
                            ->whereColumn('o.apparatus_inspection_id', 'apparatus_inspections.id')
                            ->whereIn('o.observation', self::REPORTED_OBSERVATIONS);
                    });
            })
            ->when($openOnly, fn ($q) => $q->where('d.resolved', false));

        return $query->select('apparatus_inspections.*')->addSelect([
            'followup_total' => $defects(false),
            'followup_open' => $defects(true),
        ]);
    }

    /**
     * Restricts to inspections whose stored results contain a Missing/Damaged item.
     *
     * @param  Builder<ApparatusInspection>  $query
     * @return Builder<ApparatusInspection>
     */
    public function whereHasFindings(Builder $query, bool $has = true): Builder
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $expression = "(apparatus_inspections.results::text ~ '\"status\"\\s*:\\s*\"(Missing|Damaged)\"')";
        } else {
            $expression = "(apparatus_inspections.results LIKE '%\"status\":\"Missing\"%' OR apparatus_inspections.results LIKE '%\"status\":\"Damaged\"%')";
        }

        return $query->whereRaw($has ? $expression : "NOT COALESCE({$expression}, false)");
    }

    /** @return array{missing: int, damaged: int} */
    public function countFindings(mixed $results): array
    {
        $missing = 0;
        $damaged = 0;
        foreach (is_array($results) ? $results : [] as $compartment) {
            foreach (is_array($compartment) ? ($compartment['items'] ?? []) : [] as $item) {
                $status = is_array($item) ? ($item['status'] ?? null) : null;
                $missing += $status === 'Missing' ? 1 : 0;
                $damaged += $status === 'Damaged' ? 1 : 0;
            }
        }

        return ['missing' => $missing, 'damaged' => $damaged];
    }

    public function findingsLabel(ApparatusInspection $inspection): string
    {
        $counts = $this->countFindings($inspection->results);
        $parts = array_filter([
            $counts['missing'] > 0 ? "{$counts['missing']} missing" : null,
            $counts['damaged'] > 0 ? "{$counts['damaged']} damaged" : null,
        ]);

        return $parts === [] ? 'None' : implode(', ', $parts);
    }

    public function followUpSummary(ApparatusInspection $inspection): string
    {
        $findings = $this->countFindings($inspection->results);
        $total = (int) ($inspection->getAttribute('followup_total') ?? 0);
        $open = (int) ($inspection->getAttribute('followup_open') ?? 0);

        if ($total === 0) {
            if ($findings['missing'] + $findings['damaged'] === 0) {
                return 'No follow-up needed';
            }

            return $inspection->review_status === 'pending_review'
                ? 'Awaiting review'
                : 'No defect record linked';
        }

        return $open === 0
            ? ($total === 1 ? 'Resolved' : "All {$total} resolved")
            : "{$open} of {$total} open";
    }

    public function reviewStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending_review' => 'Pending review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Submitted',
        };
    }

    public function processingStatusLabel(?string $status): string
    {
        return match ($status) {
            'accepted' => 'Accepted',
            'accepted_with_exception' => 'Accepted, meter reading flagged',
            'pending_vehicle_swap' => 'Saved, waiting for Fleet to confirm the vehicle',
            default => 'Submitted',
        };
    }

    /** Stage of the defect record itself, in plain language. */
    public function defectStageLabel(ApparatusDefect $defect): string
    {
        $status = strtolower((string) $defect->status);

        if ($defect->resolved || $status === 'resolved') {
            return 'Resolved';
        }
        if ($defect->service_ticket_id !== null) {
            return 'Service ticket opened';
        }

        return match ($status) {
            'open', 'missing', 'damaged', '' => 'Reported',
            'in_progress' => 'Under review',
            'acknowledged' => 'Acknowledged',
            default => str($status)->replace('_', ' ')->headline()->toString(),
        };
    }

    public function ticketStatusLabel(?string $status): string
    {
        $enum = $status === null ? null : ApparatusServiceTicketStatus::tryFrom($status);

        return $enum !== null ? $enum->label() : str((string) $status)->replace('_', ' ')->headline()->toString();
    }

    /**
     * Full read-only view model for the detail page and the PDF.
     *
     * @return array<string, mixed>
     */
    public function detail(ApparatusInspection $inspection): array
    {
        $inspection->loadMissing(['apparatus.station', 'reviewEvents']);

        $defects = $this->linkedDefects($inspection);
        $observationPhotos = $this->observationPhotoPaths($inspection);
        $matched = [];

        $compartments = [];
        $findings = [];
        $totals = ['items' => 0, 'present' => 0, 'missing' => 0, 'damaged' => 0];

        foreach (is_array($inspection->results) ? $inspection->results : [] as $compartment) {
            if (! is_array($compartment)) {
                continue;
            }
            $items = [];
            foreach ($compartment['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $status = (string) ($item['status'] ?? 'Present');
                $isFinding = in_array($status, self::FINDING_STATUSES, true);
                $totals['items']++;
                $totals['present'] += $status === 'Present' ? 1 : 0;
                $totals['missing'] += $status === 'Missing' ? 1 : 0;
                $totals['damaged'] += $status === 'Damaged' ? 1 : 0;

                $defect = $isFinding
                    ? ($defects[$this->identityKey($compartment['name'] ?? null, $item['name'] ?? null)] ?? null)
                    : null;
                if ($defect !== null) {
                    $matched[$defect->getKey()] = true;
                }

                $photoPath = $this->safeImagePath($item['photo_path'] ?? null)
                    ?? ($defect !== null ? $this->safeImagePath($observationPhotos[$defect->getKey()] ?? null) : null);

                $row = [
                    'name' => (string) ($item['name'] ?? '—'),
                    'status' => $status,
                    'notes' => filled($item['notes'] ?? null) ? (string) $item['notes'] : null,
                    'value' => $this->displayValue($item['value'] ?? null),
                    'photo_key' => $photoPath !== null ? $this->imageKey($photoPath) : null,
                    'photo_path' => $photoPath,
                    'is_finding' => $isFinding,
                    'follow_up' => $isFinding ? $this->followUpFor($defect, $inspection) : null,
                ];
                $items[] = $row;
                if ($isFinding) {
                    $findings[] = ['compartment' => (string) ($compartment['name'] ?? 'Compartment')] + $row;
                }
            }
            $compartments[] = ['name' => (string) ($compartment['name'] ?? 'Compartment'), 'items' => $items];
        }

        $unmatchedDefects = array_values(array_filter(
            $defects,
            fn (ApparatusDefect $defect): bool => ! isset($matched[$defect->getKey()]),
        ));
        $unmatched = array_map(fn (ApparatusDefect $defect): array => [
            'compartment' => (string) $defect->compartment,
            'name' => (string) $defect->item,
            'status' => str((string) $defect->issue_type)->headline()->toString(),
            'notes' => null,
            'photo_key' => null,
            'follow_up' => $this->followUpFor($defect, $inspection),
        ], $unmatchedDefects);

        $signaturePath = $this->safeImagePath($inspection->officer_signature);
        $apparatus = $inspection->getRelationValue('apparatus');
        $apparatus = $apparatus instanceof Apparatus ? $apparatus : null;
        $station = $apparatus?->getRelationValue('station');

        return [
            'inspection' => $inspection,
            'reference' => $inspection->inspection_reference ?: 'Inspection #'.$inspection->getKey(),
            'apparatus_label' => $inspection->designation_at_time ?: ($apparatus?->designation ?: ($apparatus?->name ?: 'Apparatus')),
            'vehicle_number' => $inspection->vehicle_number ?: $apparatus?->vehicle_number,
            'station_label' => $station instanceof Station ? $station->name : null,
            'submitted_at' => $this->eastern($inspection->completed_at ?? $inspection->created_at),
            'inspector_name' => (string) $inspection->operator_name,
            'inspector_rank' => (string) $inspection->rank,
            'shift' => $inspection->shift,
            'engine_hours' => $inspection->engine_hours,
            'miles' => $inspection->miles,
            'checklist_version_short' => $inspection->checklist_version
                ? substr((string) $inspection->checklist_version, 0, 8)
                : null,
            'review_status' => $inspection->review_status,
            'review_label' => $this->reviewStatusLabel($inspection->review_status),
            'processing_label' => $this->processingStatusLabel($inspection->processing_status),
            'acceptance' => $this->acceptance($inspection),
            'reviewed_at' => $this->reviewedAt($inspection),
            'automatically_accepted' => $inspection->processing_status !== null,
            'paper_fields' => $this->paperFields($inspection),
            'totals' => $totals,
            'compartments' => $compartments,
            'findings' => $findings,
            'unmatched_defects' => $unmatched,
            'signature_path' => $signaturePath,
            'signature_key' => $signaturePath !== null ? 'signature' : null,
        ];
    }

    /**
     * Resolve an opaque image key to a stored path belonging to this inspection.
     * Paths come only from the inspection's own evidence; the caller never
     * supplies one.
     */
    public function resolveImagePath(ApparatusInspection $inspection, string $key): ?string
    {
        if ($key === 'signature') {
            return $this->safeImagePath($inspection->officer_signature);
        }

        foreach ($this->allImagePaths($inspection) as $path) {
            if (hash_equals($this->imageKey($path), $key)) {
                return $path;
            }
        }

        return null;
    }

    public function imageKey(string $path): string
    {
        return 'photo-'.substr(hash_hmac('sha256', $path, (string) config('app.key')), 0, 24);
    }

    /**
     * Base64 data URI for PDF embedding, or null when missing/oversized.
     */
    public function embeddableImage(?string $path, int $maxBytes): ?string
    {
        $path = $this->safeImagePath($path);
        if ($path === null) {
            return null;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($path) || $disk->size($path) > $maxBytes) {
            return null;
        }
        $bytes = $disk->get($path);
        $mime = $bytes === null ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    public function safeImagePath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || str_contains($path, '..') || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return null;
        }
        foreach (self::IMAGE_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory)) {
                return $path;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function allImagePaths(ApparatusInspection $inspection): array
    {
        $paths = [];
        foreach (is_array($inspection->results) ? $inspection->results : [] as $compartment) {
            foreach (is_array($compartment) ? ($compartment['items'] ?? []) : [] as $item) {
                $path = is_array($item) ? $this->safeImagePath($item['photo_path'] ?? null) : null;
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }
        foreach ($this->observationPhotoPaths($inspection) as $candidate) {
            $path = $this->safeImagePath($candidate);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    /** @return array<int, string> defect id => photo path recorded by this inspection */
    private function observationPhotoPaths(ApparatusInspection $inspection): array
    {
        return DB::table('apparatus_defect_observations')
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->whereNotNull('photo_path')
            ->pluck('photo_path', 'apparatus_defect_id')
            ->map(fn ($path): string => (string) $path)
            ->all();
    }

    /**
     * Defects this inspection created or observed, keyed by compartment+item.
     *
     * @return array<string, ApparatusDefect>
     */
    private function linkedDefects(ApparatusInspection $inspection): array
    {
        $id = $inspection->getKey();

        $rows = ApparatusDefect::query()
            ->with('serviceTicket:id,ticket_number,status,completed_at')
            ->where(function (Builder $scope) use ($id): void {
                $scope->where('apparatus_inspection_id', $id)
                    ->orWhereIn('id', DB::table('apparatus_defect_observations')
                        ->where('apparatus_inspection_id', $id)
                        ->whereIn('observation', self::REPORTED_OBSERVATIONS)
                        ->select('apparatus_defect_id'));
            })
            ->orderBy('id')
            ->get(['id', 'apparatus_id', 'apparatus_inspection_id', 'compartment', 'item', 'status', 'issue_type', 'resolved', 'resolved_at', 'service_ticket_id', 'action_status', 'first_reported_at']);

        $keyed = [];
        foreach ($rows as $defect) {
            $keyed[$this->identityKey($defect->compartment, $defect->item)] = $defect;
        }

        return $keyed;
    }

    /** @return array<string, mixed> */
    private function followUpFor(?ApparatusDefect $defect, ApparatusInspection $inspection): array
    {
        if ($defect === null) {
            return [
                'stage' => $inspection->review_status === 'pending_review' ? 'Awaiting review' : 'No defect record linked',
                'resolved' => false,
                'ticket_number' => null,
                'ticket_status' => null,
                'repair_completed' => false,
                'linked' => false,
                'defect_id' => null,
                'action_status' => null,
                'action_label' => null,
                'first_reported_at' => null,
                'resolved_at' => null,
            ];
        }

        $ticket = $defect->service_ticket_id !== null ? $defect->getRelationValue('serviceTicket') : null;
        $ticket = $ticket instanceof ApparatusServiceTicket ? $ticket : null;

        return [
            'stage' => $this->defectStageLabel($defect),
            'resolved' => (bool) $defect->resolved,
            'ticket_number' => $ticket?->ticket_number,
            'ticket_status' => $ticket !== null ? $this->ticketStatusLabel($ticket->status) : null,
            'repair_completed' => $ticket !== null && $ticket->status === ApparatusServiceTicketStatus::Completed->value,
            'linked' => true,
            'defect_id' => (int) $defect->getKey(),
            'action_status' => (string) ($defect->action_status ?? DefectActionStatus::REPORTED),
            'action_label' => DefectActionStatus::label($defect->action_status),
            'first_reported_at' => $this->eastern($defect->first_reported_at),
            // A later fact shown beside the unchanged historical observation.
            'resolved_at' => $defect->resolved && $defect->resolved_at !== null ? $this->eastern($defect->resolved_at) : null,
        ];
    }

    /**
     * Plain-language acceptance state for the member. Reasons come only from open blocking
     * holds; reviewer notes and internal text are never used here.
     *
     * @return array{state: string, headline: string, reasons: list<string>}
     */
    public function acceptance(ApparatusInspection $inspection): array
    {
        if ($inspection->review_status === 'rejected') {
            return ['state' => 'rejected', 'headline' => 'Rejected', 'reasons' => []];
        }
        if ($inspection->review_status !== 'pending_review') {
            return ['state' => 'accepted', 'headline' => 'Accepted', 'reasons' => []];
        }

        $reasons = ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->where('blocks_acceptance', true)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->orderBy('id')
            ->get(['field', 'reason'])
            ->map(fn (ApparatusInspectionException $hold): string => $this->holdLabel((string) $hold->field, (string) $hold->reason))
            ->when($inspection->processing_status === 'pending_vehicle_swap', fn ($list) => $list->push('Fleet is confirming which vehicle you inspected'))
            ->unique()
            ->values()
            ->all();

        return ['state' => 'needs_approval', 'headline' => 'Needs approval', 'reasons' => $reasons];
    }

    public function holdLabel(string $field, string $reason): string
    {
        return match (true) {
            $reason === 'member_flagged' => 'You flagged this for review',
            $reason === 'new_significant_damage' => 'New significant damage',
            $reason === 'rollback' && $field === 'engine_hours' => 'Engine hours lower than last reading',
            $reason === 'rollback' => 'Mileage lower than last reading',
            default => 'A reviewer needs to look at this',
        };
    }

    private function identityKey(mixed $compartment, mixed $item): string
    {
        return mb_strtolower(trim((string) $compartment)).'|'.mb_strtolower(trim((string) $item));
    }

    private function displayValue(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    /** @return list<array{name: string, value: string}> */
    private function paperFields(ApparatusInspection $inspection): array
    {
        $fields = [];
        foreach (is_array($inspection->checklist_evidence) ? $inspection->checklist_evidence : [] as $field) {
            if (! is_array($field) || ! isset($field['name'])) {
                continue;
            }
            $fields[] = [
                'name' => (string) $field['name'],
                'value' => $this->displayValue($field['value'] ?? null) ?? 'Not entered',
            ];
        }

        return $fields;
    }

    private function reviewedAt(ApparatusInspection $inspection): ?CarbonImmutable
    {
        $event = $inspection->reviewEvents
            ->filter(fn ($event): bool => in_array($event->status, ['approved', 'rejected'], true))
            ->last();

        return $event?->created_at ? $this->eastern($event->created_at) : null;
    }

    private function eastern(mixed $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone(self::TIMEZONE);
    }
}
