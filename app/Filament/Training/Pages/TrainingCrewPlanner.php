<?php

declare(strict_types=1);

namespace App\Filament\Training\Pages;

use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Policies\TrainingRosterPolicy;
use App\Services\Training\CrewPlanner\TeleStaffDutyResolver;
use App\Services\Training\CrewPlanner\TrainingCrewPlanExporter;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDutyContext;
use App\Services\Training\CrewPlanner\TrainingPlannerCandidateService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TrainingCrewPlanner extends Page
{
    private const TZ = \App\Support\HubTimezone::NAME;

    /** A rejected roster upload's message, held until the page itself loads and shows it. */
    public const UPLOAD_ERROR_KEY = 'training_crew_planner_upload_error';

    protected static ?string $navigationGroup = 'Training';

    protected static ?string $navigationLabel = 'Crew Planner';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Crew Planner';

    protected static ?string $slug = 'crew-planner';

    protected static string|array $routeMiddleware = [\App\Http\Middleware\TrainingRosterPrivateResponse::class];

    protected static string $view = 'filament.training.pages.crew-planner';

    #[Locked]
    public string $draftId;

    #[Locked]
    public int $version;

    public ?int $courseId = null;

    public string $courseSearch = '';

    public string $scope = 'course';

    public array $slotEdits = [];

    public array $filters = ['search' => '', 'rank' => '', 'day_station' => '', 'day_apparatus' => '', 'assignment_category' => '', 'published_assignment' => '', 'de_bid' => false];

    public array $candidateSelection = [];

    public array $planSelection = [];

    public string $targetSlot = '';

    public bool $confirmReview = false;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(TrainingRosterPolicy::class)->create($actor);
    }

    public function mount(): void
    {
        $actor = $this->actor();
        $id = session()->get($this->sessionKey());
        try {
            $draft = is_string($id) ? $this->store()->get($actor, $id) : $this->store()->create($actor);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 410) {
                throw $exception;
            }
            $draft = $this->store()->create($actor);
        }
        $this->loadDraft($draft);
        session()->put($this->sessionKey(), $draft['id']);
        $uploadError = session()->pull(self::UPLOAD_ERROR_KEY);
        if (is_string($uploadError) && $uploadError !== '' && ! $this->getErrorBag()->has('roster_pdf')) {
            $this->addError('roster_pdf', $uploadError);
        }
    }

    public function reloadProposal(): void
    {
        $this->loadDraft($this->draft());
        $this->resetErrorBag();
    }

    /** Choosing a course applies it immediately; the module choice starts at the entire course. */
    public function updatedCourseId(): void
    {
        $this->actor();
        $this->scope = 'course';
        if ($this->courseId !== null) {
            $this->applyScope();
        }
    }

    /** Choosing a module (or the entire course) applies immediately. */
    public function updatedScope(): void
    {
        $this->actor();
        if ($this->courseId !== null) {
            $this->applyScope();
        }
    }

    public function applyScope(): void
    {
        $this->actor();
        $this->validate(['courseId' => ['required', 'integer', 'min:1'], 'scope' => ['required', 'string', 'max:30']]);
        $course = $this->course($this->courseId);
        $module = $this->scope === 'course' ? null : filter_var($this->scope, FILTER_VALIDATE_INT);
        abort_unless($module === null || ($module !== false && $course->modules()->whereKey($module)->exists()), 422);
        $updated = $this->change(function (array $draft) use ($course, $module): array {
            $draft['course_id'] = $course->id;
            $draft['module_id'] = $module;

            return $draft;
        });
        $this->candidateSelection = [];
        $this->confirmReview = false;
        if ($updated) {
            $this->prefillSessionRows($this->draft());
        }
    }

    public function addSlot(): void
    {
        $this->actor();
        abort_if(count($this->slotEdits) >= 12, 422);
        $this->slotEdits = [...$this->slotEdits, $this->blankRow()];
    }

    public function removeSlot(int $index): void
    {
        $this->actor();
        $rows = $this->slotEdits;
        unset($rows[$index]);
        $this->slotEdits = array_values($rows) ?: [$this->blankRow()];
        $this->resetErrorBag(['slotEdits', 'slotEdits.*']);
        $this->persistSlots(true);
    }

    public function reorderSlot(int $index, int $direction): void
    {
        $this->actor();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $target = $index + $direction;
        if (isset($this->slotEdits[$index], $this->slotEdits[$target])) {
            [$this->slotEdits[$index], $this->slotEdits[$target]] = [$this->slotEdits[$target], $this->slotEdits[$index]];
            $this->persistSlots();
        }
    }

    public function useSessionSuggestions(): void
    {
        $draft = $this->draft();
        $analysis = $this->analysis($draft);
        $this->slotEdits = array_map(fn (array $slot): array => $this->slotEdit($slot, true), array_slice($analysis['session_suggestions'] ?? [], 0, 12));
    }

    /** Sessions save themselves once every row has a valid start before its end. */
    public function updatedSlotEdits(): void
    {
        $this->actor();
        $this->resetErrorBag(['slotEdits', 'slotEdits.*']);
        $this->persistSlots();
    }

    public function saveSlots(): void
    {
        $draft = $this->draft();
        $this->validate([
            'slotEdits' => ['required', 'array', 'min:1', 'max:12'],
            'slotEdits.*.id' => ['required', 'uuid', 'distinct'],
            'slotEdits.*.label' => ['nullable', 'string', 'max:80'],
            'slotEdits.*.start' => ['required', 'date_format:H:i'],
            'slotEdits.*.end' => ['required', 'date_format:H:i'],
        ]);
        $date = $draft['metadata']['schedule_date'] ?? null;
        if (! $date) {
            throw ValidationException::withMessages(['slotEdits' => 'Read the daily report first so session times use the correct date.']);
        }
        $this->commitSlots($this->slotsFromEdits($date, array_column($draft['slots'], 'id'), true));
    }

    public function addSelected(bool $allEligible = false): void
    {
        $draft = $this->draft();
        $this->requireCurrentSlotDates($draft);
        $this->validate(['candidateSelection' => ['array', 'max:2000'], 'candidateSelection.*' => ['integer', 'min:1']]);
        $rows = $this->analysis($draft, $this->cleanFilters($draft))['rows'] ?? [];
        $ids = $allEligible ? array_keys($rows) : $this->candidateSelection;
        $before = count($draft['selections']);
        $existing = count(array_intersect_key(array_flip(array_map('intval', $ids)), $draft['selections']));
        $updated = $this->addMembers($draft, $rows, $ids);
        if ($updated) {
            Notification::make()->success()->title(count($updated['selections']).' proposed · '.(count($updated['selections']) - $before).' added · '.$existing.' already on list')->send();
            $this->candidateSelection = [];
        }
    }

    /** One tap on a candidate adds or removes that member; eligibility is rechecked on the server. */
    public function toggleMember(int $id): void
    {
        $draft = $this->draft();
        $this->requireCurrentSlotDates($draft);
        abort_unless($id > 0, 422);
        if (isset($draft['selections'][$id])) {
            $this->removeMember($id);

            return;
        }
        $this->addMembers($draft, $this->analysis($draft)['rows'] ?? [], [$id]);
    }

    public function selectAllShown(): void
    {
        $draft = $this->draft();
        $this->requireCurrentSlotDates($draft);
        $rows = $this->analysis($draft, $this->cleanFilters($draft))['rows'] ?? [];
        $this->addMembers($draft, $rows, array_keys($rows));
    }

    public function clearSelection(): void
    {
        $this->actor();
        $this->change(static fn (array $draft): array => [...$draft, 'removed' => $draft['selections'], 'selections' => []]);
        $this->confirmReview = false;
    }

    /** A member has exactly one session; it must be one the member is on duty for in full. */
    public function setMemberSlot(int $id, string $slotId): void
    {
        $draft = $this->draft();
        $this->requireCurrentSlotDates($draft);
        abort_unless($id > 0 && isset($draft['selections'][$id]), 422);
        $slot = $slotId === '' ? null : $slotId;
        abort_unless($slot === null || in_array($slot, array_column($draft['slots'], 'id'), true), 422);
        if ($slot !== null) {
            $row = ($this->analysis($draft)['rows'] ?? [])[$id] ?? null;
            if (! $row || ! in_array($slot, $row['eligible_slots'] ?? [], true)) {
                throw ValidationException::withMessages(['selection' => 'This member is not on duty for the whole of that session. Choose another session.']);
            }
        }
        $this->change(function (array $draft) use ($id, $slot): array {
            $draft['selections'][$id]['slot_id'] = $slot;

            return $draft;
        });
        $this->confirmReview = false;
    }

    public function removeSelected(): void
    {
        $this->actor();
        $this->validate(['planSelection' => ['array', 'max:2000'], 'planSelection.*' => ['integer', 'min:1']]);
        $ids = $this->planSelection;
        $this->change(function (array $draft) use ($ids): array {
            $draft['removed'] = array_intersect_key($draft['selections'], array_flip($ids));
            $draft['selections'] = array_diff_key($draft['selections'], array_flip($ids));

            return $draft;
        });
        $this->planSelection = [];
    }

    public function removeMember(int $id): void
    {
        $this->actor();
        $this->planSelection = [(string) $id];
        $this->removeSelected();
    }

    public function undoRemove(): void
    {
        $this->change(function (array $draft): array {
            $draft['selections'] += $draft['removed'];
            $draft['removed'] = [];

            return $draft;
        });
        $this->confirmReview = false;
    }

    public function moveSelected(): void
    {
        $draft = $this->draft();
        $this->requireCurrentSlotDates($draft);
        $this->validate(['planSelection' => ['array', 'max:2000'], 'planSelection.*' => ['integer', 'min:1']]);
        $rows = $this->analysis($draft)['rows'] ?? [];
        $slot = $this->targetSlot === '' ? null : $this->targetSlot;
        abort_unless($slot === null || in_array($slot, array_column($draft['slots'], 'id'), true), 422);
        foreach ($this->planSelection as $id) {
            abort_unless(isset($draft['selections'][$id]), 422);
            if ($slot !== null && ! in_array($slot, $rows[$id]['eligible_slots'] ?? [], true)) {
                throw ValidationException::withMessages(['targetSlot' => 'A selected member does not fully qualify for that time. Choose another time or keep the member unassigned for review.']);
            }
        }
        $ids = $this->planSelection;
        $this->change(function (array $draft) use ($ids, $slot): array {
            foreach ($ids as $id) {
                $draft['selections'][$id]['slot_id'] = $slot;
            }

            return $draft;
        });
        $this->planSelection = [];
    }

    public function discardProposal(): void
    {
        $actor = $this->actor();
        $this->store()->discard($actor, $this->draftId, $this->version);
        $this->loadDraft($this->store()->create($actor));
        session()->put($this->sessionKey(), $this->draftId);
        $this->candidateSelection = $this->planSelection = [];
        $this->confirmReview = false;
        $this->resetErrorBag();
    }

    public function download(string $format): mixed
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        $draft = $this->draft();
        abort_if($draft['version'] !== $this->version, 409, 'Reload the proposal before exporting; another tab changed it.');
        $this->requireCurrentSlotDates($draft);
        $analysis = $this->analysis($draft);
        $course = $this->course($draft['course_id']);
        $rows = [];
        foreach ($draft['selections'] as $id => $selection) {
            $person = $analysis['rows'][$id] ?? null;
            $review = $this->needsVerification($person, $selection);
            if ($review && ! $this->confirmReview) {
                throw ValidationException::withMessages(['confirmReview' => 'Some selected members need fresh verification. Review them under "Needs verification" and confirm before exporting.']);
            }
            $slot = collect($draft['slots'])->firstWhere('id', $selection['slot_id']);
            $context = app(TrainingCrewPlannerDutyContext::class)->project($person['observed_unit_context'] ?? [], $slot);
            $rows[$id] = [
                'employee_id' => $person['employee_id'] ?? '', 'name' => $person['name'] ?? 'Previously selected member — identity requires review',
                'rank' => $person['rank'] ?? '', 'bid_position' => ($person['assignment'] ?? '').(! empty($person['is_de_bid']) ? ' · Published Driver Engineer seat' : ''),
                'station' => $context['station_plain'], 'apparatus' => $context['apparatus_plain'],
                'slot_id' => $selection['slot_id'], 'verification_required' => $review,
                'review_reasons' => $review ? ['Recheck identity, current attendance and full class-time availability before approval.'] : [],
            ];
        }
        $projection = [
            'course_name' => $course->name, 'module_name' => $analysis['module']['name'] ?? null,
            'scope_label' => $draft['module_id'] ? 'Selected module — no verified prior attendance' : 'Entire course — no verified prior attendance',
            ...$draft['metadata'], 'generated_at' => now()->toIso8601String(), 'author' => $this->actor()->name,
            'slots' => $draft['slots'], 'rows' => $rows,
        ];
        $bytes = app(TrainingCrewPlanExporter::class)->{$format}($projection);
        $this->resetErrorBag('confirmReview');

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'MBFD-Training-Personnel-Request-'.($draft['metadata']['schedule_date'] ?? 'undated').'.'.$format, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }

    protected function getViewData(): array
    {
        $draft = $this->draft();
        [$cutoff, $fromClassStart] = $this->attendanceCutoff($draft);
        $all = $this->analysis($draft);
        $filters = $this->cleanFilters($draft);
        $filtered = $filters === [] ? $all : $this->analysis($draft, $filters);
        $usable = $draft['slots'] !== [] && ! $this->hasStaleSlotDates($draft);
        $labels = $this->slotLabels($draft['slots']);

        $candidates = $notListed = [];
        foreach ($filtered['rows'] ?? [] as $id => $row) {
            if ($this->eligible($row)) {
                $candidates[$id] = $this->candidateCard($id, $row, $draft, $labels);
            } elseif ($reason = $this->notListedReason($row, $usable, $draft['module_id'] !== null)) {
                $notListed[] = ['name' => $row['name'], 'rank' => $row['rank'] ?? '', 'employee_id' => $row['employee_id'], 'reason' => $reason];
            }
        }
        $unmatched = $this->unmatchedIdentities($filtered['review'] ?? [], $filters);

        $courses = TrainingCourse::query()->when($this->courseSearch !== '', fn ($query) => $query->where('name', 'like', '%'.mb_substr($this->courseSearch, 0, 200).'%'))->orderBy('name')->limit(500)->get()->filter(fn (TrainingCourse $course): bool => app(TrainingRosterPolicy::class)->view($this->actor(), $course));
        $chosen = $this->courseId ? $this->course($this->courseId) : null;

        return [
            'draft' => $draft, 'all' => $all, 'candidates' => $candidates, 'notListed' => $notListed, 'unmatched' => $unmatched,
            'courses' => $courses, 'chosen' => $chosen, 'showCourseSearch' => $this->courseSearch !== '' || $courses->count() >= 15,
            'snapshotWarnings' => $this->snapshotWarnings($draft['metadata']),
            'eligibleTotal' => count(array_filter($all['rows'] ?? [], fn (array $row): bool => $this->eligible($row))),
            'review' => $this->reviewModel($draft, $all, $labels), 'cutoff' => $cutoff, 'cutoffIsClassStart' => $fromClassStart,
            'scopeName' => $all['module']['name'] ?? null, 'courseName' => $all['course']['name'] ?? null, 'sessionsReady' => $usable,
            'rosterDay' => $this->rosterDay($draft['metadata']), 'slotLabels' => $labels,
            'ranks' => collect($all['rows'] ?? [])->pluck('rank')->filter()->unique()->sort()->values()->all(),
            'stations' => collect($all['rows'] ?? [])->pluck('observed_unit_context')->flatten(1)->pluck('station')->filter()->unique()->sort()->values()->all(),
            'units' => collect($all['rows'] ?? [])->pluck('observed_unit_context')->flatten(1)->pluck('apparatus')->filter()->unique()->sort()->values()->all(),
            'assignments' => collect($all['rows'] ?? [])->pluck('assignment')->filter()->unique()->sort()->values()->all(),
            'categories' => collect($all['rows'] ?? [])->pluck('assignment_category')->filter()->unique()->sort()->values()->all(),
        ];
    }

    private function actor(): User
    {
        abort_unless(self::canAccess(), 403);

        return auth()->user();
    }

    private function course(?int $id): TrainingCourse
    {
        $actor = $this->actor();
        $course = TrainingCourse::query()->findOrFail($id);
        abort_unless(app(TrainingRosterPolicy::class)->view($actor, $course), 403);

        return $course;
    }

    private function draft(): array
    {
        $draft = $this->store()->get($this->actor(), $this->draftId);
        if ($draft['course_id']) {
            $this->course($draft['course_id']);
        }

        return $draft;
    }

    private function store(): TrainingCrewPlannerDraftStore
    {
        return app(TrainingCrewPlannerDraftStore::class);
    }

    private function change(callable $change): ?array
    {
        $this->draft();
        try {
            $draft = $this->store()->update($this->actor(), $this->draftId, $this->version, $change);
            $this->version = $draft['version'];

            return $draft;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->addError('proposal', $exception->getMessage());

            return null;
        }
    }

    /**
     * Attendance is judged as it stood when today's class begins: the earliest saved session
     * start when that is already past, otherwise the present moment. An older roster never
     * moves the cutoff back, so recent verified attendance always excludes a member.
     *
     * @return array{0: CarbonImmutable, 1: bool}
     */
    private function attendanceCutoff(array $draft): array
    {
        $now = CarbonImmutable::now();
        if ($draft['slots'] === [] || $this->hasStaleSlotDates($draft)
            || ($draft['metadata']['schedule_date'] ?? null) !== $now->setTimezone(self::TZ)->toDateString()) {
            return [$now, false];
        }
        $earliest = collect($draft['slots'])->map(fn (array $slot): CarbonImmutable => CarbonImmutable::parse($slot['start_at']))->sortBy(fn (CarbonImmutable $start): int => $start->getTimestamp())->first();

        return $earliest !== null && $earliest->lt($now) ? [$earliest, true] : [$now, false];
    }

    private function analysis(array $draft, array $filters = []): array
    {
        if (! $draft['course_id']) {
            return ['rows' => [], 'session_suggestions' => []];
        }
        $staff = app(TeleStaffDutyResolver::class)->resolveStaff($draft['staff'], $this->hasStaleSlotDates($draft) ? [] : $draft['slots']);

        return app(TrainingPlannerCandidateService::class)->candidates($this->actor(), $this->course($draft['course_id']), $draft['module_id'], $this->attendanceCutoff($draft)[0], $staff, $filters, $draft['metadata']['schedule_date'] ?? null);
    }

    private function eligible(array $row): bool
    {
        return ! empty($row['eligible_slots']);
    }

    private function needsVerification(?array $person, array $selection): bool
    {
        return ! $person || ! $this->eligible($person)
            || ($selection['slot_id'] !== null && ! in_array($selection['slot_id'], $person['eligible_slots'] ?? [], true));
    }

    /** Re-reads current eligibility on the server; the browser never decides who qualifies. */
    private function addMembers(array $draft, array $rows, array $ids): ?array
    {
        $slotIds = array_column($draft['slots'], 'id');
        $add = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if (! $row || ! $this->eligible($row)) {
                continue;
            }
            $fits = array_values(array_intersect($row['eligible_slots'], $slotIds));
            $add[(int) $id] = ['slot_id' => count($fits) === 1 ? $fits[0] : null];
        }
        if ($add === [] && $ids !== []) {
            $this->addError('selection', 'That member is no longer eligible for this class. Refresh the check and try again.');

            return null;
        }
        $updated = $this->change(function (array $draft) use ($add): array {
            $draft['selections'] += $add;

            return $draft;
        });
        $this->confirmReview = false;

        return $updated;
    }

    private function hasStaleSlotDates(array $draft): bool
    {
        $date = $draft['metadata']['schedule_date'] ?? null;
        foreach ($draft['slots'] as $slot) {
            if (CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TZ)->toDateString() !== $date) {
                return true;
            }
        }

        return false;
    }

    private function requireCurrentSlotDates(array $draft): void
    {
        if ($this->hasStaleSlotDates($draft)) {
            throw ValidationException::withMessages(['slotEdits' => 'The session times belong to a different roster date. Apply them to the new date before selecting or exporting. Your selected members are safe.']);
        }
    }

    private function cleanFilters(array $draft): array
    {
        $filters = [];
        foreach (['search', 'rank', 'day_station', 'day_apparatus', 'assignment_category', 'published_assignment'] as $key) {
            if (is_string($this->filters[$key] ?? null) && trim($this->filters[$key]) !== '') {
                $filters[$key] = mb_substr(trim($this->filters[$key]), 0, $key === 'published_assignment' ? 200 : 100);
            }
        }
        if (($this->filters['de_bid'] ?? false) === true) {
            $filters['de_bid'] = true;
        }
        // With one session, station and unit filters follow that session's own hours.
        if (count($draft['slots']) === 1 && (isset($filters['day_station']) || isset($filters['day_apparatus']))) {
            $filters['slot_start_at'] = $draft['slots'][0]['start_at'];
            $filters['slot_end_at'] = $draft['slots'][0]['end_at'];
        }

        return $filters;
    }

    private function loadDraft(array $draft): void
    {
        $this->draftId = $draft['id'];
        $this->version = $draft['version'];
        $this->courseId = $draft['course_id'];
        $this->scope = $draft['module_id'] === null ? 'course' : (string) $draft['module_id'];
        $this->slotEdits = array_map(fn (array $slot): array => $this->slotEdit($slot), $draft['slots']);
        $this->prefillSessionRows($draft);
    }

    /** No invented times: existing Hub sessions supply a start, otherwise one empty row. */
    private function prefillSessionRows(array $draft): void
    {
        if ($draft['slots'] !== [] || ! $this->sessionRowsAreBlank()) {
            return;
        }
        $rows = [];
        if (! empty($draft['metadata']['schedule_date'])) {
            foreach (array_slice($this->analysis($draft)['session_suggestions'] ?? [], 0, 12) as $suggestion) {
                $rows[] = [...$this->slotEdit($suggestion, true), 'label' => ''];
            }
        }
        $this->slotEdits = $rows ?: [$this->blankRow()];
    }

    private function sessionRowsAreBlank(): bool
    {
        foreach ($this->slotEdits as $row) {
            if (($row['start'] ?? '') !== '' || ($row['end'] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    private function blankRow(): array
    {
        return ['id' => (string) Str::uuid(), 'label' => '', 'start' => '', 'end' => ''];
    }

    private function persistSlots(bool $allowClear = false): void
    {
        $draft = $this->draft();
        $date = $draft['metadata']['schedule_date'] ?? null;
        if (! is_string($date) || $date === '') {
            return;
        }
        $slots = $this->slotsFromEdits($date, array_column($draft['slots'], 'id'), false);
        if ($slots === null || ($slots === [] && ! $allowClear) || $slots === $draft['slots']) {
            return;
        }
        $this->commitSlots($slots);
    }

    /**
     * @param  list<string>  $savedIds
     * @return ?list<array{id: string, label: string, start_at: string, end_at: string}> null while a row is unfinished or invalid
     */
    private function slotsFromEdits(string $date, array $savedIds, bool $strict): ?array
    {
        abort_if(count($this->slotEdits) > 12, 422);
        abort_unless(array_is_list($this->slotEdits), 422);
        $slots = [];
        foreach ($this->slotEdits as $index => $edit) {
            abort_unless(is_array($edit) && is_string($edit['id'] ?? null) && Str::isUuid($edit['id']), 422);
            abort_if(in_array($edit['id'], array_column($slots, 'id'), true), 422);
            $start = is_string($edit['start'] ?? null) ? $edit['start'] : '';
            $end = is_string($edit['end'] ?? null) ? $edit['end'] : '';
            if ($start === '' && $end === '' && ! in_array($edit['id'], $savedIds, true)) {
                continue;
            }
            if ($start === '' || $end === '') {
                return null;
            }
            if (! $this->validTime($start) || ! $this->validTime($end)) {
                return $this->slotProblem($strict, $index, 'Enter the time as hours and minutes.');
            }
            if ($end <= $start) {
                return $this->slotProblem($strict, $index, 'End time must be after start time on the training day.');
            }
            $label = mb_substr(trim(is_string($edit['label'] ?? null) ? $edit['label'] : ''), 0, 80);
            $slots[] = ['id' => $edit['id'], 'label' => $label !== '' ? $label : 'Session '.(count($slots) + 1),
                'start_at' => CarbonImmutable::parse($date.' '.$start, self::TZ)->toIso8601String(),
                'end_at' => CarbonImmutable::parse($date.' '.$end, self::TZ)->toIso8601String()];
        }

        return $slots;
    }

    private function slotProblem(bool $strict, int|string $index, string $message): null
    {
        if ($strict) {
            throw ValidationException::withMessages(["slotEdits.$index.end" => $message]);
        }
        $this->addError("slotEdits.$index.end", $message);

        return null;
    }

    private function validTime(string $time): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time);
    }

    private function commitSlots(array $slots): void
    {
        $ids = array_column($slots, 'id');
        $keepOnlyKnown = static fn (array $rows): array => array_map(
            static fn (array $row): array => in_array($row['slot_id'], $ids, true) ? $row : ['slot_id' => null], $rows);
        $this->change(static fn (array $draft): array => [...$draft, 'slots' => $slots,
            'selections' => $keepOnlyKnown($draft['selections']), 'removed' => $keepOnlyKnown($draft['removed'])]);
        $this->confirmReview = false;
    }

    private function sessionKey(): string
    {
        return 'training_crew_planner_draft.'.auth()->id();
    }

    private function slotEdit(array $slot, bool $newId = false): array
    {
        return [
            'id' => $newId ? (string) Str::uuid() : $slot['id'], 'label' => $slot['label'],
            'start' => CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TZ)->format('H:i'),
            'end' => empty($slot['end_at']) ? '' : CarbonImmutable::parse($slot['end_at'])->setTimezone(self::TZ)->format('H:i'),
        ];
    }

    /** @return array<string, array{label: string, time: string}> */
    private function slotLabels(array $slots): array
    {
        $labels = [];
        foreach ($slots as $slot) {
            $labels[$slot['id']] = ['label' => $slot['label'],
                'time' => CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TZ)->format('H:i').'–'.CarbonImmutable::parse($slot['end_at'])->setTimezone(self::TZ)->format('H:i')];
        }

        return $labels;
    }

    private function candidateCard(int|string $id, array $row, array $draft, array $labels): array
    {
        $place = $this->dutyPlace($row['observed_unit_context'] ?? [], $draft['slots']);
        $eligibleSlots = array_values(array_intersect($row['eligible_slots'], array_keys($labels)));

        return [
            'id' => (int) $id, 'name' => $row['name'], 'rank' => $row['rank'] ?? '', 'employee_id' => $row['employee_id'],
            'station' => $place['station'], 'unit' => $place['unit'], 'duty' => $this->dutyWindow($row['working_intervals'] ?? []),
            'selected' => isset($draft['selections'][$id]), 'slot_id' => $draft['selections'][$id]['slot_id'] ?? null,
            'slot_options' => array_map(fn (string $slotId): array => ['id' => $slotId] + $labels[$slotId], $eligibleSlots),
            'partial' => count($eligibleSlots) < count($labels),
        ];
    }

    /** Station and unit worked during the saved sessions (all of the day when none are saved). */
    private function dutyPlace(array $contexts, array $slots): array
    {
        $stations = $units = [];
        foreach ($contexts as $context) {
            $overlaps = $slots === [];
            foreach ($slots as $slot) {
                $overlaps = $overlaps || (! empty($context['start_at']) && ! empty($context['end_at'])
                    && CarbonImmutable::parse($context['start_at'])->lt(CarbonImmutable::parse($slot['end_at']))
                    && CarbonImmutable::parse($context['end_at'])->gt(CarbonImmutable::parse($slot['start_at'])));
            }
            if ($overlaps) {
                $stations[] = (string) ($context['station'] ?? '');
                $units[] = (string) ($context['apparatus'] ?? '');
            }
        }
        $join = static fn (array $values): string => implode(' / ', array_unique(array_filter($values))) ?: 'Not reported';

        return ['station' => $join($stations), 'unit' => $join($units)];
    }

    private function dutyWindow(array $intervals): string
    {
        $parts = [];
        foreach ($intervals as $interval) {
            $start = CarbonImmutable::parse($interval['start_at'])->setTimezone(self::TZ);
            $end = CarbonImmutable::parse($interval['end_at'])->setTimezone(self::TZ);
            $parts[] = $start->format('H:i').'–'.$end->format('H:i').($end->toDateString() !== $start->toDateString() ? ' (next day)' : '');
        }

        return implode(', ', $parts) ?: 'Not reported';
    }

    private function notListedReason(array $row, bool $sessionsReady, bool $moduleScope): ?string
    {
        $status = $row['attendance_status'] ?? '';
        if ($status === 'VERIFIED_ATTENDED') {
            return $moduleScope ? 'Already attended this module (verified record)' : 'Already attended this course (verified record)';
        }
        if ($status === 'NOT_APPLICABLE') {
            return "Outside this module's audience";
        }
        if (in_array($status, ['UNVERIFIED_OR_PROVISIONAL', 'IDENTITY_OR_EVIDENCE_REVIEW'], true)) {
            return 'Attendance record needs verification';
        }
        if (! $sessionsReady) {
            return null;
        }
        foreach ($row['slot_review_reasons'] ?? [] as $reasons) {
            foreach ($reasons as $reason) {
                if (is_string($reason) && $reason !== '') {
                    return 'Duty needs verification: '.$reason;
                }
            }
        }

        return 'Not on duty for the whole session';
    }

    /** Schedule identities with no unique active Hub employee; searchable so absence is explained. */
    private function unmatchedIdentities(array $review, array $filters): array
    {
        if (array_intersect_key($filters, array_flip(['rank', 'day_station', 'day_apparatus', 'assignment_category', 'published_assignment', 'de_bid'])) !== []) {
            return [];
        }
        $needle = mb_strtolower($filters['search'] ?? '');
        $result = [];
        foreach ($review as $identity) {
            $name = (string) ($identity['raw_name'] ?? '') ?: 'Name not reported';
            $id = (string) ($identity['raw_employee_id'] ?? '');
            if ($needle === '' || str_contains(mb_strtolower($name.' '.$id), $needle)) {
                $result[] = ['name' => $name, 'employee_id' => $id, 'reason' => 'Not matched to an active Hub employee (ID '.($id !== '' ? $id : 'not reported').')'];
            }
        }

        return $result;
    }

    /** Selected members grouped by session; anyone who stopped qualifying is held apart for verification. */
    private function reviewModel(array $draft, array $all, array $labels): array
    {
        $groups = [];
        foreach ($labels as $slotId => $label) {
            $groups[$slotId] = $label + ['members' => []];
        }
        $unassigned = $verify = [];
        foreach ($draft['selections'] as $id => $selection) {
            $person = $all['rows'][$id] ?? null;
            $slot = collect($draft['slots'])->firstWhere('id', $selection['slot_id']);
            $place = $this->dutyPlace($person['observed_unit_context'] ?? [], $slot ? [$slot] : []);
            $member = ['id' => (int) $id, 'name' => $person['name'] ?? 'Previously selected member', 'rank' => $person['rank'] ?? '',
                'employee_id' => $person['employee_id'] ?? '', 'place' => $place['station'].' · '.$place['unit']];
            if ($this->needsVerification($person, $selection)) {
                $verify[] = $member + ['reason' => $person ? 'No longer qualifies for the whole session. Check attendance and duty time.' : 'Not found on the current report. Check identity.'];
            } elseif ($slot) {
                $groups[$slot['id']]['members'][] = $member;
            } else {
                $unassigned[] = $member;
            }
        }

        return ['groups' => $groups, 'unassigned' => $unassigned, 'verify' => $verify, 'count' => count($draft['selections'])];
    }

    private function rosterDay(array $metadata): ?string
    {
        return empty($metadata['schedule_date']) ? null : CarbonImmutable::parse($metadata['schedule_date'], self::TZ)->format('l');
    }

    private function snapshotWarnings(array $metadata): array
    {
        if (empty($metadata['printed_at'])) {
            return [];
        }
        $printed = CarbonImmutable::parse($metadata['printed_at']);
        $warnings = [];
        if (! empty($metadata['schedule_date']) && $printed->lt(CarbonImmutable::parse($metadata['schedule_date'], self::TZ)->startOfDay())) {
            $warnings[] = 'This snapshot was generated before its roster date. Verify any staffing changes made after it was printed.';
        }
        if ($printed->lt(CarbonImmutable::now()->subDay())) {
            $warnings[] = 'This snapshot was generated more than 24 hours ago. Use it as dated planning evidence and verify later staffing changes.';
        }

        return $warnings;
    }
}
