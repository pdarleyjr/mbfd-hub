<?php

declare(strict_types=1);

namespace App\Filament\Training\Pages;

use App\Models\Station;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingCrewPlanPublication;
use App\Models\User;
use App\Policies\TrainingRosterPolicy;
use App\Services\Training\CrewPlanner\TrainingCrewPlanComposer;
use App\Services\Training\CrewPlanner\TrainingCrewPlanExporter;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use App\Services\Training\CrewPlanner\TrainingCrewPlanPublisher;
use App\Services\Training\CrewPlanner\TrainingRosterDuty;
use App\Services\Training\CrewPlanner\TrainingRosterDutySource;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class, personnel, optional sessions, review, publish. Staffing comes from the TeleStaff roster the Hub
 * already holds. Saving or publishing a plan never creates a class or an attendance record.
 */
final class TrainingCrewPlanner extends Page
{
    private const TZ = \App\Support\HubTimezone::NAME;

    private const STATION_CHOICE = 'station:';

    private const OTHER_LOCATION = 'other';

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

    public string $classTitle = '';

    public string $courseSearch = '';

    public string $scope = 'course';

    public string $scheduleDate = '';

    public array $slotEdits = [];

    public array $filters = ['search' => '', 'rank' => '', 'day_station' => '', 'day_apparatus' => '', 'assignment_category' => '', 'published_assignment' => '', 'de_bid' => false];

    public array $candidateSelection = [];

    public array $planSelection = [];

    public string $targetSlot = '';

    public bool $confirmReview = false;

    public array $publishTargets = [];

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(TrainingRosterPolicy::class)->create($actor);
    }

    public function mount(): void
    {
        $actor = $this->actor();
        $draft = $this->store()->latest($actor) ?? $this->newDraft($actor);
        $this->loadDraft($this->syncRoster($draft));
    }

    public function reloadProposal(): void
    {
        $this->loadDraft($this->draft());
        $this->resetErrorBag();
    }

    /** Picks up a roster that arrived while the page was open. Polled while visible; an edit in progress is untouched. */
    public function refreshRoster(): void
    {
        $draft = $this->draft();
        if ($draft['version'] === $this->version) {
            $this->syncRoster($draft);
        }
    }

    public function acknowledgeRoster(): void
    {
        $snapshot = $this->composer()->duty($this->draft())->snapshotId;
        $this->change(function (array $draft) use ($snapshot): array {
            $draft['metadata']['acknowledged_snapshot_id'] = $snapshot;

            return $draft;
        });
        $this->confirmReview = false;
    }

    /** Choosing a course applies it immediately; the module choice starts at the entire course. */
    public function updatedCourseId(): void
    {
        $this->actor();
        $this->scope = 'course';
        if ($this->courseId !== null) {
            $this->classTitle = '';
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
        $course = $this->composer()->course($this->actor(), $this->courseId);
        $module = $this->scope === 'course' ? null : filter_var($this->scope, FILTER_VALIDATE_INT);
        abort_unless($module === null || ($module !== false && $course->modules()->whereKey($module)->exists()), 422);
        $updated = $this->change(function (array $draft) use ($course, $module): array {
            $draft['course_id'] = $course->id;
            $draft['module_id'] = $module;
            $draft['class_title'] = '';

            return $draft;
        });
        $this->candidateSelection = [];
        $this->confirmReview = false;
        if ($updated) {
            $this->prefillSessionRows($this->draft());
        }
    }

    /** A class outside the course catalog. It stays a title on this plan and creates no course or training record. */
    public function updatedClassTitle(): void
    {
        $this->actor();
        $this->validate(['classTitle' => ['nullable', 'string', 'max:160']]);
        $title = trim($this->classTitle);
        if ($title !== '') {
            $this->courseId = null;
            $this->scope = 'course';
        }
        $this->change(function (array $draft) use ($title): array {
            $draft['class_title'] = $title;
            if ($title !== '') {
                $draft['course_id'] = $draft['module_id'] = null;
            }

            return $draft;
        });
    }

    /** Sessions keep their clock times on the new date, and selections stay put to be rechecked against that day's roster. */
    public function updatedScheduleDate(): void
    {
        $this->actor();
        $today = CarbonImmutable::now(self::TZ);
        $valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->scheduleDate) && checkdate((int) substr($this->scheduleDate, 5, 2), (int) substr($this->scheduleDate, 8, 2), (int) substr($this->scheduleDate, 0, 4))
            && $this->scheduleDate >= $today->subYear()->toDateString() && $this->scheduleDate <= $today->addYear()->toDateString();
        if (! $valid) {
            $this->addError('scheduleDate', 'Choose a date within a year of today.');
            $this->scheduleDate = $this->composer()->date($this->draft());

            return;
        }
        $date = $this->scheduleDate;
        $snapshot = app(TrainingRosterDutySource::class)->forDate($date)->snapshotId;
        $this->change(function (array $draft) use ($date, $snapshot): array {
            $draft['metadata']['schedule_date'] = $date;
            $draft['metadata']['acknowledged_snapshot_id'] = $snapshot;
            $draft['slots'] = array_map(static fn (array $slot): array => [
                ...$slot,
                'start_at' => CarbonImmutable::parse($date.' '.CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TZ)->format('H:i'), self::TZ)->toIso8601String(),
                'end_at' => CarbonImmutable::parse($date.' '.CarbonImmutable::parse($slot['end_at'])->setTimezone(self::TZ)->format('H:i'), self::TZ)->toIso8601String(),
            ], $draft['slots']);

            return $draft;
        });
        $this->confirmReview = false;
    }

    public function addSlot(): void
    {
        $this->actor();
        abort_if(count($this->slotEdits) >= 12, 422);
        $last = end($this->slotEdits) ?: [];
        // A new session starts at the location of the one before it; most classes stay in one place.
        $this->slotEdits = [...$this->slotEdits, [...$this->blankRow(), 'location_choice' => $last['location_choice'] ?? '', 'venue' => $last['venue'] ?? '']];
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

    public function applyLocationToAll(int $index): void
    {
        $this->actor();
        abort_unless(isset($this->slotEdits[$index]), 422);
        $source = $this->slotEdits[$index];
        $this->slotEdits = array_map(static fn (array $row): array => [...$row, 'location_choice' => $source['location_choice'] ?? '', 'venue' => $source['venue'] ?? ''], $this->slotEdits);
        $this->persistSlots();
    }

    /** Sessions save themselves once every row has a valid start before its end. */
    public function updatedSlotEdits(): void
    {
        $this->actor();
        $this->resetErrorBag(['slotEdits', 'slotEdits.*']);
        $this->persistSlots();
    }

    public function addSelected(bool $allEligible = false): void
    {
        $draft = $this->draft();
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
        $rows = $this->analysis($draft, $this->cleanFilters($draft))['rows'] ?? [];
        $this->addMembers($draft, $rows, array_keys($rows));
    }

    /** A deliberate addition of someone the roster or attendance check left out. It is labelled wherever the plan goes. */
    public function addException(int $id): void
    {
        $draft = $this->draft();
        abort_unless($id > 0, 422);
        $rows = $this->analysis($draft)['rows'] ?? [];
        if (! isset($rows[$id])) {
            $this->addError('selection', 'That member is not an active member of the department.');

            return;
        }
        $only = count($draft['slots']) === 1 ? $draft['slots'][0]['id'] : null;
        $this->change(function (array $draft) use ($id, $only): array {
            $draft['selections'][$id] = ['slot_id' => $only, 'exception' => true];

            return $draft;
        });
        $this->confirmReview = false;
    }

    public function clearSelection(): void
    {
        $this->actor();
        $this->change(static fn (array $draft): array => [...$draft, 'removed' => $draft['selections'], 'selections' => []]);
        $this->confirmReview = false;
    }

    /** A member has exactly one session; it must be one the member is on duty for in full, unless added as an exception. */
    public function setMemberSlot(int $id, string $slotId): void
    {
        $draft = $this->draft();
        abort_unless($id > 0 && isset($draft['selections'][$id]), 422);
        $slot = $slotId === '' ? null : $slotId;
        abort_unless($slot === null || in_array($slot, array_column($draft['slots'], 'id'), true), 422);
        if ($slot !== null && empty($draft['selections'][$id]['exception'])) {
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
        $this->validate(['planSelection' => ['array', 'max:2000'], 'planSelection.*' => ['integer', 'min:1']]);
        $rows = $this->analysis($draft)['rows'] ?? [];
        $slot = $this->targetSlot === '' ? null : $this->targetSlot;
        abort_unless($slot === null || in_array($slot, array_column($draft['slots'], 'id'), true), 422);
        foreach ($this->planSelection as $id) {
            abort_unless(isset($draft['selections'][$id]), 422);
            if ($slot !== null && empty($draft['selections'][$id]['exception']) && ! in_array($slot, $rows[$id]['eligible_slots'] ?? [], true)) {
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

    /** Puts everyone still without a session into this one, where they fit. Anyone who does not fit stays unassigned. */
    public function assignUnassignedTo(string $slotId): void
    {
        $draft = $this->draft();
        abort_unless(in_array($slotId, array_column($draft['slots'], 'id'), true), 422);
        $rows = $this->analysis($draft)['rows'] ?? [];
        $this->change(function (array $draft) use ($rows, $slotId): array {
            foreach ($draft['selections'] as $id => $selection) {
                if (($selection['slot_id'] ?? null) === null && (! empty($selection['exception']) || in_array($slotId, $rows[$id]['eligible_slots'] ?? [], true))) {
                    $draft['selections'][$id]['slot_id'] = $slotId;
                }
            }

            return $draft;
        });
        $this->confirmReview = false;
    }

    public function discardProposal(): void
    {
        $actor = $this->actor();
        $this->store()->discard($actor, $this->draftId, $this->version);
        $this->loadDraft($this->newDraft($actor));
        $this->candidateSelection = $this->planSelection = $this->publishTargets = [];
        $this->confirmReview = false;
        $this->resetErrorBag();
    }

    public function download(string $format): mixed
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        $draft = $this->draft();
        abort_if($draft['version'] !== $this->version, 409, 'Reload the proposal before exporting; another tab changed it.');
        $projection = $this->composer()->projection($this->actor(), $draft, $this->confirmReview);
        $bytes = app(TrainingCrewPlanExporter::class)->{$format}($projection);
        $this->resetErrorBag('confirmReview');

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'MBFD-Training-Class-Roster-'.$projection['schedule_date'].'.'.$format, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }

    public function publish(): void
    {
        $actor = $this->actor();
        $draft = $this->draft();
        if ($draft['version'] !== $this->version) {
            $this->addError('proposal', 'This proposal changed in another tab. Reload it before sending.');

            return;
        }
        $this->validate(['publishTargets' => ['required', 'array', 'min:1'], 'publishTargets.*' => ['string', 'in:'.implode(',', TrainingCrewPlanPublisher::DESTINATIONS)]],
            ['publishTargets.required' => 'Choose where to send this plan.', 'publishTargets.min' => 'Choose where to send this plan.']);
        $this->send($actor, $draft, $this->publishTargets);
    }

    /** Only what failed goes again; what already went out stays as it is. */
    public function retryFailed(): void
    {
        $actor = $this->actor();
        $draft = $this->draft();
        if ($draft['version'] !== $this->version) {
            $this->addError('proposal', 'This proposal changed in another tab. Reload it before sending.');

            return;
        }
        $publisher = app(TrainingCrewPlanPublisher::class);
        $history = $publisher->history($actor, $draft['id']);
        $latest = $history->first()?->fingerprint;
        $failed = $history->filter(static fn (TrainingCrewPlanPublication $row): bool => $row->fingerprint === $latest && $row->needsRetry())
            ->pluck('destination')->values()->all();
        if ($failed === []) {
            return;
        }
        // A retry repeats what failed, word for word. A plan edited since then is a new send, chosen on purpose.
        if ($publisher->fingerprint($draft, $this->composer()->projection($actor, $draft, $this->confirmReview)) !== $latest) {
            $this->addError('publishTargets', 'This plan changed after that send. Choose where to send the new version.');

            return;
        }
        $this->send($actor, $draft, $failed);
    }

    protected function getViewData(): array
    {
        $actor = $this->actor();
        $draft = $this->draft();
        $composer = $this->composer();
        $duty = $composer->duty($draft);
        [$cutoff, $fromClassStart] = $composer->attendanceCutoff($draft);
        $all = $this->analysis($draft, [], $duty);
        $filters = $this->cleanFilters($draft);
        $filtered = $filters === [] ? $all : $this->analysis($draft, $filters, $duty);
        $labels = $this->slotLabels($draft['slots']);
        $searching = trim((string) ($filters['search'] ?? '')) !== '';

        $candidates = $notListed = [];
        foreach ($filtered['rows'] ?? [] as $id => $row) {
            if ($composer->eligible($row, $draft)) {
                $candidates[$id] = $this->candidateCard($id, $row, $draft, $labels);
            } elseif (! ($row['off_roster'] ?? false) || $searching) {
                $notListed[] = ['id' => (int) $id, 'name' => $row['name'], 'rank' => $row['rank'] ?? '', 'employee_id' => $row['employee_id'],
                    'reason' => $this->notListedReason($row, $draft), 'selected' => isset($draft['selections'][$id])];
            }
        }

        $courses = TrainingCourse::query()->when($this->courseSearch !== '', fn ($query) => $query->where('name', 'like', '%'.mb_substr($this->courseSearch, 0, 200).'%'))->orderBy('name')->limit(500)->get()->filter(fn (TrainingCourse $course): bool => app(TrainingRosterPolicy::class)->view($actor, $course));
        $chosen = $composer->course($actor, $draft['course_id'] ?? null);
        $contexts = collect($all['rows'] ?? [])->pluck('observed_unit_context')->flatten(1);
        $publisher = app(TrainingCrewPlanPublisher::class);

        return [
            'draft' => $draft, 'duty' => $duty, 'all' => $all, 'candidates' => $candidates, 'notListed' => array_slice($notListed, 0, 100),
            'unmatched' => $this->unmatchedIdentities($filtered['review'] ?? [], $filters),
            'courses' => $courses, 'chosen' => $chosen, 'showCourseSearch' => $this->courseSearch !== '' || $courses->count() >= 15,
            'hasClass' => $composer->hasClass($draft), 'dates' => $this->dateChoices($duty),
            'rosterChange' => $composer->rosterChange($actor, $draft, $duty),
            'eligibleTotal' => count(array_filter($all['rows'] ?? [], fn (array $row): bool => $composer->eligible($row, $draft))),
            'review' => $this->reviewModel($draft, $all, $labels), 'cutoff' => $cutoff, 'cutoffIsClassStart' => $fromClassStart,
            'slotLabels' => $labels, 'stationsList' => Station::query()->orderBy('station_number')->get(['id', 'name', 'station_number']),
            'locationConflicts' => $this->locationConflicts($draft['slots']),
            'ranks' => collect($all['rows'] ?? [])->pluck('rank')->filter()->unique()->sort()->values()->all(),
            'stations' => $contexts->pluck('station')->filter()->unique()->sort()->values()->all(),
            'units' => $contexts->pluck('apparatus')->filter()->unique()->sort()->values()->all(),
            'assignments' => collect($all['rows'] ?? [])->pluck('assignment')->filter()->unique()->sort()->values()->all(),
            'categories' => collect($all['rows'] ?? [])->pluck('assignment_category')->filter()->unique()->sort()->values()->all(),
            'targets' => [
                ['id' => TrainingCrewPlanPublisher::COMMAND_300, 'label' => '300 message card', 'allowed' => $publisher->allows($actor, TrainingCrewPlanPublisher::COMMAND_300)],
                ['id' => TrainingCrewPlanPublisher::CAPTAIN_5, 'label' => 'Captain 5 message card', 'allowed' => $publisher->allows($actor, TrainingCrewPlanPublisher::CAPTAIN_5)],
                ['id' => TrainingCrewPlanPublisher::DEPARTMENT, 'label' => 'Department Updates (visible department-wide)', 'allowed' => $publisher->allows($actor, TrainingCrewPlanPublisher::DEPARTMENT)],
            ],
            'publications' => $publisher->history($actor, $draft['id']),
        ];
    }

    private function send(User $actor, array $draft, array $destinations): void
    {
        $limit = 'training-crew-plan-publish:'.$actor->getKey();
        if (RateLimiter::tooManyAttempts($limit, 12)) {
            $this->addError('publishTargets', 'Too many sends in a short time. Wait a minute and try again.');

            return;
        }
        RateLimiter::hit($limit, 60);
        $results = app(TrainingCrewPlanPublisher::class)->publish($actor, $draft, $destinations, $this->confirmReview);
        $failed = array_filter($results, static fn (TrainingCrewPlanPublication $row): bool => $row->status === TrainingCrewPlanPublication::STATUS_FAILED);
        if ($failed === []) {
            Notification::make()->success()->title('Plan sent to '.count($results).' '.Str::plural('destination', count($results)).'.')->send();
        } else {
            Notification::make()->warning()->title(count($failed).' of '.count($results).' did not go out. Your plan is safe. See the status below.')->send();
        }
        $this->publishTargets = [];
    }

    private function actor(): User
    {
        abort_unless(self::canAccess(), 403);

        return auth()->user();
    }

    private function draft(): array
    {
        $draft = $this->store()->get($this->actor(), $this->draftId);
        if ($draft['course_id'] ?? null) {
            $this->composer()->course($this->actor(), $draft['course_id']);
        }

        return $draft;
    }

    private function store(): TrainingCrewPlannerDraftStore
    {
        return app(TrainingCrewPlannerDraftStore::class);
    }

    private function composer(): TrainingCrewPlanComposer
    {
        return app(TrainingCrewPlanComposer::class);
    }

    private function newDraft(User $actor): array
    {
        return $this->store()->create($actor, app(TrainingRosterDutySource::class)->today());
    }

    /** Adopts a newer roster silently unless it changes someone on this plan; then the planner has to look first. */
    private function syncRoster(array $draft): array
    {
        $composer = $this->composer();
        $duty = $composer->duty($draft);
        if (($draft['metadata']['acknowledged_snapshot_id'] ?? null) === $duty->snapshotId || $composer->rosterChange($this->actor(), $draft, $duty)['material']) {
            return $draft;
        }
        $updated = $this->store()->update($this->actor(), $draft['id'], $draft['version'], function (array $current) use ($duty): array {
            $current['metadata']['acknowledged_snapshot_id'] = $duty->snapshotId;

            return $current;
        });
        if (isset($this->version)) {
            $this->version = $updated['version'];
        }

        return $updated;
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

    private function analysis(array $draft, array $filters = [], ?TrainingRosterDuty $duty = null): array
    {
        return $this->composer()->analyze($this->actor(), $draft, $filters, $duty);
    }

    /** Re-reads current eligibility on the server; the browser never decides who qualifies. */
    private function addMembers(array $draft, array $rows, array $ids): ?array
    {
        $slotIds = array_column($draft['slots'], 'id');
        $add = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if (! $row || ! $this->composer()->eligible($row, $draft)) {
                continue;
            }
            $fits = array_values(array_intersect($row['eligible_slots'] ?? [], $slotIds));
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
        $this->courseId = $draft['course_id'] ?? null;
        $this->classTitle = (string) ($draft['class_title'] ?? '');
        $this->scope = ($draft['module_id'] ?? null) === null ? 'course' : (string) $draft['module_id'];
        $this->scheduleDate = $this->composer()->date($draft);
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
        foreach (array_slice($this->analysis($draft)['session_suggestions'] ?? [], 0, 12) as $suggestion) {
            $rows[] = [...$this->slotEdit($suggestion, true), 'label' => ''];
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
        return ['id' => (string) Str::uuid(), 'label' => '', 'start' => '', 'end' => '', 'location_choice' => '', 'venue' => ''];
    }

    private function persistSlots(bool $allowClear = false): void
    {
        $draft = $this->draft();
        $slots = $this->slotsFromEdits($this->composer()->date($draft), array_column($draft['slots'], 'id'));
        if ($slots === null || ($slots === [] && ! $allowClear) || $slots === $draft['slots']) {
            return;
        }
        $this->commitSlots($slots);
    }

    /**
     * @param  list<string>  $savedIds
     * @return ?list<array<string, mixed>> null while a row is unfinished or invalid
     */
    private function slotsFromEdits(string $date, array $savedIds): ?array
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
                $this->addError("slotEdits.$index.end", 'Enter the time as hours and minutes.');

                return null;
            }
            if ($end <= $start) {
                $this->addError("slotEdits.$index.end", 'End time must be after start time on the training day.');

                return null;
            }
            $label = mb_substr(trim(is_string($edit['label'] ?? null) ? $edit['label'] : ''), 0, 80);
            $slots[] = ['id' => $edit['id'], 'label' => $label !== '' ? $label : 'Session '.(count($slots) + 1),
                'start_at' => CarbonImmutable::parse($date.' '.$start, self::TZ)->toIso8601String(),
                'end_at' => CarbonImmutable::parse($date.' '.$end, self::TZ)->toIso8601String(),
                ...$this->location($edit)];
        }

        return $slots;
    }

    /** A station from the Hub's own list, or a typed venue. The stored words are what people read. */
    private function location(array $edit): array
    {
        $choice = is_string($edit['location_choice'] ?? null) ? $edit['location_choice'] : '';
        if (str_starts_with($choice, self::STATION_CHOICE)) {
            $station = Station::query()->find((int) substr($choice, strlen(self::STATION_CHOICE)));

            return $station === null ? ['station_id' => null, 'venue' => '', 'location' => '']
                : ['station_id' => (int) $station->getKey(), 'venue' => '', 'location' => (string) $station->name];
        }
        if ($choice === self::OTHER_LOCATION) {
            $venue = mb_substr(trim(is_string($edit['venue'] ?? null) ? $edit['venue'] : ''), 0, 160);

            return ['station_id' => null, 'venue' => $venue, 'location' => $venue];
        }

        return ['station_id' => null, 'venue' => '', 'location' => ''];
    }

    private function validTime(string $time): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time);
    }

    private function commitSlots(array $slots): void
    {
        $ids = array_column($slots, 'id');
        $keepOnlyKnown = static fn (array $rows): array => array_map(
            static fn (array $row): array => [...$row, 'slot_id' => in_array($row['slot_id'] ?? null, $ids, true) ? $row['slot_id'] : null], $rows);
        $this->change(static fn (array $draft): array => [...$draft, 'slots' => $slots,
            'selections' => $keepOnlyKnown($draft['selections']), 'removed' => $keepOnlyKnown($draft['removed'])]);
        $this->confirmReview = false;
    }

    private function slotEdit(array $slot, bool $newId = false): array
    {
        return [
            'id' => $newId ? (string) Str::uuid() : $slot['id'], 'label' => $slot['label'],
            'start' => CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TZ)->format('H:i'),
            'end' => empty($slot['end_at']) ? '' : CarbonImmutable::parse($slot['end_at'])->setTimezone(self::TZ)->format('H:i'),
            'location_choice' => ! empty($slot['station_id']) ? self::STATION_CHOICE.$slot['station_id'] : (($slot['venue'] ?? '') !== '' ? self::OTHER_LOCATION : ''),
            'venue' => $slot['venue'] ?? '',
        ];
    }

    /** @return array<string, array{label: string, time: string, location: string}> */
    private function slotLabels(array $slots): array
    {
        $labels = [];
        foreach ($slots as $slot) {
            $labels[$slot['id']] = ['label' => $slot['label'], 'location' => (string) ($slot['location'] ?? ''),
                'time' => CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TZ)->format('H:i').'–'.CarbonImmutable::parse($slot['end_at'])->setTimezone(self::TZ)->format('H:i')];
        }

        return $labels;
    }

    /** @return list<string> sessions booked into the same place at overlapping times; a nudge, not a stop */
    private function locationConflicts(array $slots): array
    {
        $conflicts = [];
        foreach ($slots as $index => $slot) {
            foreach (array_slice($slots, $index + 1) as $other) {
                if (($slot['location'] ?? '') !== '' && ($slot['location'] ?? '') === ($other['location'] ?? '')
                    && CarbonImmutable::parse($slot['start_at'])->lt(CarbonImmutable::parse($other['end_at']))
                    && CarbonImmutable::parse($other['start_at'])->lt(CarbonImmutable::parse($slot['end_at']))) {
                    $conflicts[] = $slot['label'].' and '.$other['label'].' overlap at '.$slot['location'].'.';
                }
            }
        }

        return $conflicts;
    }

    /** @return list<array{date: string, label: string, verified: bool}> */
    private function dateChoices(TrainingRosterDuty $duty): array
    {
        $source = app(TrainingRosterDutySource::class);
        $today = $source->today();
        $verified = array_slice($source->availableDates(5), 0, 5);
        $dates = array_values(array_unique([$today, ...$verified]));
        rsort($dates);

        return array_map(static fn (string $date): array => [
            'date' => $date, 'verified' => in_array($date, $verified, true),
            'label' => ($date === $today ? 'Today · ' : '').CarbonImmutable::parse($date, self::TZ)->format('D M j'),
        ], $dates);
    }

    private function candidateCard(int|string $id, array $row, array $draft, array $labels): array
    {
        $place = $this->dutyPlace($row['observed_unit_context'] ?? [], $draft['slots']);
        $eligibleSlots = array_values(array_intersect($row['eligible_slots'] ?? [], array_keys($labels)));

        return [
            'id' => (int) $id, 'name' => $row['name'], 'rank' => $row['rank'] ?? '', 'employee_id' => $row['employee_id'],
            'station' => $place['station'], 'unit' => $place['unit'], 'duty' => $this->dutyWindow($row['working_intervals'] ?? []),
            'selected' => isset($draft['selections'][$id]), 'slot_id' => $draft['selections'][$id]['slot_id'] ?? null,
            'slot_options' => array_map(fn (string $slotId): array => ['id' => $slotId] + $labels[$slotId], $eligibleSlots),
            'partial' => count($eligibleSlots) < count($labels), 'provisional' => $row['provisional'] ?? false,
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

    private function notListedReason(array $row, array $draft): string
    {
        $status = $row['attendance_status'] ?? '';
        if ($status === 'VERIFIED_ATTENDED') {
            return $draft['module_id'] ? 'Already attended this module (verified record)' : 'Already attended this course (verified record)';
        }
        if ($status === 'NOT_APPLICABLE') {
            return "Outside this module's audience";
        }
        if (in_array($status, ['UNVERIFIED_OR_PROVISIONAL', 'IDENTITY_OR_EVIDENCE_REVIEW'], true)) {
            return 'Attendance record needs verification';
        }
        if ($row['off_roster'] ?? false) {
            return 'Not on this roster';
        }
        foreach ($row['slot_review_reasons'] ?? [] as $reasons) {
            foreach ($reasons as $reason) {
                if (is_string($reason) && $reason !== '') {
                    return 'Duty needs verification: '.$reason;
                }
            }
        }
        if ($draft['slots'] === []) {
            return ($row['needs_review'] ?? []) !== [] ? 'Duty needs verification' : 'Not working on this date';
        }

        return 'Not on duty for the whole session';
    }

    /** Roster identities with no unique active Hub employee; searchable so absence is explained. */
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
            $slot = collect($draft['slots'])->firstWhere('id', $selection['slot_id'] ?? null);
            $place = $this->dutyPlace($person['observed_unit_context'] ?? [], $slot ? [$slot] : []);
            $member = ['id' => (int) $id, 'name' => $person['name'] ?? 'Previously selected member', 'rank' => $person['rank'] ?? '',
                'employee_id' => $person['employee_id'] ?? '', 'place' => $place['station'].' · '.$place['unit'], 'exception' => ! empty($selection['exception'])];
            if ($this->composer()->needsVerification($person, $selection, $draft)) {
                $verify[] = $member + ['reason' => $person ? 'No longer qualifies for this class. Check attendance and duty time.' : 'Not found on the current report. Check identity.'];
            } elseif ($slot) {
                $groups[$slot['id']]['members'][] = $member;
            } else {
                $unassigned[] = $member;
            }
        }

        return ['groups' => $groups, 'unassigned' => $unassigned, 'verify' => $verify, 'count' => count($draft['selections'])];
    }
}
