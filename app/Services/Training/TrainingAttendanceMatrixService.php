<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TrainingAttendanceMatrixService
{
    public const LABELS = [
        'digital' => 'Digitally signed', 'paper' => 'Verified paper roster',
        'digital_and_paper' => 'Digital and verified paper', 'provisional' => 'Provisional',
        'review' => 'Pending review', 'missing' => 'Missing', 'not_applicable' => 'N/A',
    ];

    public function __construct(private readonly TrainingPersonnelClassificationService $personnel) {}

    /** One live projection shared by the screen and both workbook modes. No signature art is returned. */
    public function snapshot(TrainingCourse $course, array $filters = [], ?string $asOf = null): array
    {
        Gate::authorize('view', $course);
        $course->refresh();
        abort_unless($course->track_attendance_matrix, 404);
        $asOf ??= CarbonImmutable::now('America/New_York')->toDateString();
        Validator::make(['as_of' => $asOf], ['as_of' => ['required', 'date_format:Y-m-d']])->validate();
        $filters = $this->validateFilters($filters);
        $moduleId = filled($filters['module_id'] ?? null) ? (int) $filters['module_id'] : null;
        $modules = TrainingModule::query()->where('training_course_id', $course->id)
            ->when($moduleId !== null, fn ($query) => $query->whereKey($moduleId))->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($module): array => $module->only(['id', 'name', 'is_required', 'audience', 'sort_order']))->all();
        if ($moduleId !== null && $modules === []) {
            throw ValidationException::withMessages(['module_id' => 'Choose a module belonging to this course.']);
        }
        $moduleNames = array_column($modules, 'name', 'id');
        $sessionModels = TrainingSession::query()->where('training_course_id', $course->id)
            ->when($moduleId !== null, fn ($query) => $query->where('training_module_id', $moduleId))
            ->orderBy('held_on')->orderBy('starts_at')->orderBy('id')->get();
        $sessions = $sessionModels->map(fn ($session): array => [
            'id' => (int) $session->id, 'module_id' => $session->training_module_id === null ? null : (int) $session->training_module_id,
            'module_name' => $moduleNames[$session->training_module_id] ?? 'Unassigned',
            'held_on' => $session->held_on->toDateString(), 'starts_at' => $session->starts_at?->toIso8601String(),
            'location' => $session->location, 'status' => $session->status, 'source' => $session->attendance_source ?? 'digital',
        ])->all();
        $moduleOrder = array_flip(array_column($modules, 'id'));
        usort($sessions, static fn (array $left, array $right): int => ($moduleOrder[$left['module_id']] ?? PHP_INT_MAX) <=> ($moduleOrder[$right['module_id']] ?? PHP_INT_MAX));
        $sessionById = array_column($sessions, null, 'id');
        $sessionsByModule = collect($sessions)->groupBy('module_id');
        $entries = [];
        $exceptions = [];
        foreach (TrainingAttendance::query()->whereIn('training_session_id', array_keys($sessionById))->whereNull('voided_at')->get() as $attendance) {
            $session = $sessionById[$attendance->training_session_id];
            $signature = $attendance->getAttribute('signature');
            $valid = is_array($signature) && filled($signature['disk'] ?? null) && filled($signature['path'] ?? null)
                && in_array($signature['mime'] ?? null, ['image/png', 'image/jpeg'], true)
                && preg_match('/^[a-f0-9]{64}$/D', (string) ($signature['sha256'] ?? ''));
            $status = ! $valid ? 'review' : (in_array($session['status'], ['submitted', 'certified'], true) ? 'digital' : 'provisional');
            $entries[(int) $attendance->employee_profile_id][$session['id']][] = [
                'status' => $status, 'source' => 'digital', 'attendance_id' => (int) $attendance->id,
                'roster_status' => $session['status'], 'signed_at' => $attendance->signed_at->toIso8601String(),
                'signature_signed_at' => ($attendance->signature_signed_at ?? $attendance->signed_at)->toIso8601String(),
                'attendance_snapshot' => array_intersect_key($attendance->snapshot ?? [], array_flip(['rank', 'shift', 'assignment_id', 'as_of'])),
            ];
            if (! $valid) {
                $exceptions[] = $this->exception('digital_evidence', $session, (int) $attendance->employee_profile_id, 'Digital evidence needs review.');
            }
        }
        $paper = DB::table('training_paper_attendances as a')->join('training_paper_roster_imports as i', 'i.id', '=', 'a.training_paper_roster_import_id')
            ->whereIn('a.training_session_id', array_keys($sessionById))->whereColumn('a.training_session_id', 'i.training_session_id')
            ->select(['a.id', 'a.training_session_id', 'a.employee_profile_id', 'a.source_page', 'a.source_row', 'a.review_status', 'a.evidence_present', 'a.reviewed_by', 'a.reviewed_at', 'i.source_sha256', 'i.page_count'])->get();
        foreach ($paper as $entry) {
            $session = $sessionById[$entry->training_session_id];
            $approved = $entry->review_status === 'approved' && $entry->employee_profile_id !== null && $entry->evidence_present
                && $entry->reviewed_by !== null && $entry->reviewed_at !== null
                && preg_match('/^[a-f0-9]{64}$/D', $entry->source_sha256)
                && $entry->source_page >= 1 && $entry->source_page <= $entry->page_count && filled($entry->source_row);
            if (in_array($entry->review_status, ['excluded', 'voided'], true)) {
                $exceptions[] = $this->exception('paper_'.$entry->review_status, $session, $entry->employee_profile_id, 'Paper entry '.$entry->review_status.'.', $entry);

                continue;
            }
            if (! $approved) {
                $exceptions[] = $this->exception('paper_pending', $session, $entry->employee_profile_id, 'Paper evidence or identity needs review.', $entry);
            }
            if ($entry->employee_profile_id !== null) {
                $entries[(int) $entry->employee_profile_id][$session['id']][] = [
                    'status' => $approved ? 'paper' : 'review', 'source' => 'paper', 'paper_entry_id' => (int) $entry->id,
                    'source_sha256' => $entry->source_sha256, 'source_page' => (int) $entry->source_page,
                    'source_row' => $entry->source_row, 'reviewed_at' => $entry->reviewed_at,
                ];
            }
        }
        foreach ($sessions as $session) {
            if ($session['module_id'] === null) {
                $exceptions[] = $this->exception('unassigned_session', $session, null, 'Associate this session with a module before counting module coverage.');
            }
        }
        $rows = [];
        $population = $this->personnel->population($asOf);
        if (count($population) * (count($modules) + count($sessions)) > 250000) {
            throw ValidationException::withMessages(['module_id' => 'This course has too many cells to display together. Select a module.']);
        }
        $activeIds = array_fill_keys(array_column($population, 'employee_profile_id'), true);
        foreach ($entries as $employee => $employeeSessions) {
            if (! isset($activeIds[$employee])) {
                foreach (array_keys($employeeSessions) as $sessionId) {
                    $exceptions[] = $this->exception('outside_active_population', $sessionById[$sessionId], null, 'Attendance exists for a member outside the current active personnel population.');
                }
            }
        }
        foreach ($population as $person) {
            $row = $person + ['module_cells' => [], 'session_cells' => []];
            foreach ($sessions as $session) {
                $evidence = $entries[$person['employee_profile_id']][$session['id']] ?? [];
                $row['session_cells'][$session['id']] = $this->cell($evidence);
            }
            foreach ($modules as $module) {
                $applicable = $this->applicable($person, $module['audience'] ?? []);
                $evidence = [];
                $ids = [];
                foreach ($sessionsByModule->get($module['id'], []) as $session) {
                    $items = $entries[$person['employee_profile_id']][$session['id']] ?? [];
                    $evidence = array_merge($evidence, $items);
                    if ($items !== []) {
                        $ids[] = $session['id'];
                    }
                }
                $cell = $this->cell($evidence);
                $cell['applicable'] = $applicable;
                $cell['session_ids'] = $ids;
                if ($applicable !== true) {
                    $cell['status'] = $applicable === null ? 'review' : 'not_applicable';
                    $cell['label'] = self::LABELS[$cell['status']];
                }
                $row['module_cells'][$module['id']] = $cell;
            }
            if (! $person['assignment_verified'] || $person['assignment_category'] === 'unverified') {
                $exceptions[] = ['type' => 'assignment_unverified', 'session_id' => null, 'module_id' => null,
                    'employee_profile_id' => $person['employee_profile_id'], 'reason' => 'Current published assignment or its personnel category needs review.'];
            }
            $rows[] = $row;
        }

        return $this->filterSnapshot([
            'course' => ['id' => (int) $course->id, 'name' => $course->name], 'as_of' => $asOf,
            'modules' => $modules, 'sessions' => $sessions, 'rows' => $rows, 'exceptions' => $exceptions,
        ], $filters);
    }

    public function filterSnapshot(array $snapshot, array $filters): array
    {
        $filters = $this->validateFilters($filters);
        $moduleId = filled($filters['module_id'] ?? null) ? (int) $filters['module_id'] : null;
        if ($moduleId !== null) {
            $snapshot['modules'] = array_values(array_filter($snapshot['modules'], fn ($module): bool => (int) $module['id'] === $moduleId));
            $snapshot['sessions'] = array_values(array_filter($snapshot['sessions'], fn ($session): bool => $session['module_id'] === $moduleId));
        }
        $modules = array_column($snapshot['modules'], null, 'id');
        $sessionIds = array_fill_keys(array_column($snapshot['sessions'], 'id'), true);
        $rows = [];
        foreach ($snapshot['rows'] as $row) {
            $row['module_cells'] = array_intersect_key($row['module_cells'], $modules);
            $row['session_cells'] = array_intersect_key($row['session_cells'], $sessionIds);
            $required = $covered = 0;
            $missing = [];
            foreach ($modules as $id => $module) {
                $cell = $row['module_cells'][$id];
                if ($module['is_required'] && $cell['applicable'] === true) {
                    $required++;
                    if ($cell['covered']) {
                        $covered++;
                    } else {
                        $missing[$id] = $module['name'];
                    }
                }
            }
            $statuses = array_column($row['module_cells'], 'status');
            foreach ($row['session_cells'] as $cell) {
                $statuses = array_merge($statuses, array_column($cell['sources'], 'status'));
            }
            $row['required_count'] = $required;
            $row['covered_count'] = $covered;
            $row['missing_module_ids'] = array_keys($missing);
            $row['missing_modules'] = array_values($missing);
            $row['percent'] = $required === 0 ? null : round($covered / $required * 100, 1);
            $row['status'] = $required === 0 ? 'not_applicable' : ($covered === $required ? 'all' : ($covered === 0 ? 'none' : 'partial'));
            $row['has_provisional'] = in_array('provisional', $statuses, true);
            $row['has_review'] = ! $row['assignment_verified'] || $row['assignment_category'] === 'unverified' || in_array('review', $statuses, true);
            if ($this->matches($row, $filters)) {
                $rows[] = $row;
            }
        }
        $snapshot['rows'] = $rows;
        $snapshot['filters'] = $filters;
        $ids = array_fill_keys(array_column($rows, 'employee_profile_id'), true);
        $snapshot['exceptions'] = array_values(array_filter($snapshot['exceptions'], static fn ($exception): bool => ($moduleId === null || $exception['module_id'] === $moduleId)
            && ($exception['employee_profile_id'] === null || isset($ids[$exception['employee_profile_id']]))));
        $snapshot['totals'] = ['personnel' => count($rows), 'required' => array_sum(array_column($rows, 'required_count')),
            'covered' => array_sum(array_column($rows, 'covered_count')), 'exceptions' => count($snapshot['exceptions'])];

        return $snapshot;
    }

    private function validateFilters(array $filters): array
    {
        return Validator::make($filters, [
            'cohort' => ['nullable', Rule::in(['all', 'officers', 'captain', 'lieutenant', 'firefighters'])],
            'assignment' => ['nullable', Rule::in(['rescue', 'combat', 'day_other', 'unverified'])],
            'de_bid' => ['nullable', 'boolean'], 'floating' => ['nullable', 'boolean'],
            'shift' => ['nullable', Rule::in(['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'])],
            'module_id' => ['nullable', 'integer'], 'missing_module_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'partial', 'none', 'missing', 'provisional', 'review', 'not_applicable'])],
            'search' => ['nullable', 'string', 'max:100'],
        ])->validate();
    }

    private function matches(array $row, array $filters): bool
    {
        $ranks = match ($filters['cohort'] ?? 'all') {
            'officers' => ['Captain', 'Lieutenant'], 'captain' => ['Captain'], 'lieutenant' => ['Lieutenant'],
            'firefighters' => ['Firefighter'], default => null,
        };
        if ($ranks !== null && ! in_array($row['rank'], $ranks, true)) {
            return false;
        }
        foreach (['assignment' => 'assignment_category', 'shift' => 'shift', 'de_bid' => 'is_de_bid', 'floating' => 'is_floating'] as $filter => $key) {
            if (isset($filters[$filter]) && $filters[$filter] !== '' && $row[$key] !== (in_array($filter, ['de_bid', 'floating'], true) ? (bool) $filters[$filter] : $filters[$filter])) {
                return false;
            }
        }
        if (filled($filters['search'] ?? null) && ! str_contains(mb_strtolower($row['name'].' '.$row['employee_id']), mb_strtolower(trim($filters['search'])))) {
            return false;
        }
        if (filled($filters['missing_module_id'] ?? null) && ! in_array((int) $filters['missing_module_id'], $row['missing_module_ids'], true)) {
            return false;
        }

        return match ($filters['status'] ?? null) {
            'missing' => $row['missing_module_ids'] !== [], 'provisional' => $row['has_provisional'], 'review' => $row['has_review'],
            'all', 'partial', 'none', 'not_applicable' => $row['status'] === $filters['status'], default => true,
        };
    }

    private function applicable(array $person, array $audience): ?bool
    {
        $unknown = false;
        foreach (['ranks' => 'rank', 'shifts' => 'shift'] as $key => $field) {
            if (($audience[$key] ?? []) === []) {
                continue;
            }
            if (blank($person[$field])) {
                $unknown = true;
            } elseif (! in_array($person[$field], $audience[$key], true)) {
                return false;
            }
        }

        return $unknown ? null : true;
    }

    private function cell(array $evidence): array
    {
        $statuses = array_column($evidence, 'status');
        $digital = in_array('digital', $statuses, true);
        $paper = in_array('paper', $statuses, true);
        $status = $digital && $paper ? 'digital_and_paper' : ($digital ? 'digital' : ($paper ? 'paper' :
            (in_array('review', $statuses, true) ? 'review' : (in_array('provisional', $statuses, true) ? 'provisional' : 'missing'))));

        return ['status' => $status, 'label' => self::LABELS[$status], 'covered' => $digital || $paper, 'sources' => $evidence];
    }

    private function exception(string $type, array $session, int|string|null $employee, string $reason, ?object $entry = null): array
    {
        return ['type' => $type, 'session_id' => $session['id'], 'module_id' => $session['module_id'],
            'employee_profile_id' => $employee === null ? null : (int) $employee, 'reason' => $reason,
            'source_sha256' => $entry?->source_sha256, 'source_page' => $entry?->source_page, 'source_row' => $entry?->source_row];
    }
}
