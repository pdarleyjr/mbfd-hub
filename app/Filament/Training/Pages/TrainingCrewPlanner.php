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
    protected static ?string $navigationGroup = 'Training';

    protected static ?string $navigationLabel = 'Crew Planner';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Crew Planner';

    protected static ?string $slug = 'crew-planner';

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

    public string $candidateSlot = '';

    public bool $showReview = false;

    public bool $confirmReview = false;

    public string $planSearch = '';

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
    }

    public function reloadProposal(): void
    {
        $this->loadDraft($this->draft());
        $this->resetErrorBag();
    }

    public function updatedCourseId(): void
    {
        $this->actor();
        $this->scope = 'course';
    }

    public function applyScope(): void
    {
        $this->actor();
        $this->validate(['courseId' => ['required', 'integer', 'min:1'], 'scope' => ['required', 'string', 'max:30']]);
        $course = $this->course($this->courseId);
        $module = $this->scope === 'course' ? null : filter_var($this->scope, FILTER_VALIDATE_INT);
        abort_unless($module === null || ($module !== false && $course->modules()->whereKey($module)->exists()), 422);
        $this->change(function (array $draft) use ($course, $module): array {
            $draft['course_id'] = $course->id;
            $draft['module_id'] = $module;

            return $draft;
        });
        $this->candidateSelection = [];
        $this->confirmReview = false;
    }

    public function addSlot(): void
    {
        $this->actor();
        abort_if(count($this->slotEdits) >= 12, 422);
        $this->slotEdits[] = ['id' => (string) Str::uuid(), 'label' => '', 'start' => '', 'end' => ''];
    }

    public function removeSlot(int $index): void
    {
        $this->actor();
        unset($this->slotEdits[$index]);
        $this->slotEdits = array_values($this->slotEdits);
    }

    public function reorderSlot(int $index, int $direction): void
    {
        $this->actor();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $target = $index + $direction;
        if (isset($this->slotEdits[$index], $this->slotEdits[$target])) {
            [$this->slotEdits[$index], $this->slotEdits[$target]] = [$this->slotEdits[$target], $this->slotEdits[$index]];
        }
    }

    public function useSessionSuggestions(): void
    {
        $draft = $this->draft();
        $analysis = $this->analysis($draft);
        $this->slotEdits = array_map(fn (array $slot): array => $this->slotEdit($slot, true), array_slice($analysis['session_suggestions'] ?? [], 0, 12));
    }

    public function saveSlots(): void
    {
        $draft = $this->draft();
        $this->validate([
            'slotEdits' => ['required', 'array', 'min:1', 'max:12'],
            'slotEdits.*.id' => ['required', 'uuid', 'distinct'],
            'slotEdits.*.label' => ['required', 'string', 'max:80'],
            'slotEdits.*.start' => ['required', 'date_format:H:i'],
            'slotEdits.*.end' => ['required', 'date_format:H:i'],
        ]);
        $date = $draft['metadata']['schedule_date'] ?? null;
        if (! $date) {
            throw ValidationException::withMessages(['slotEdits' => 'Upload the daily report first so classroom times use the correct date.']);
        }
        $slots = [];
        foreach ($this->slotEdits as $index => $slot) {
            if ($slot['end'] <= $slot['start']) {
                throw ValidationException::withMessages(["slotEdits.$index.end" => 'End time must be after start time on the training day.']);
            }
            $slots[] = ['id' => $slot['id'], 'label' => trim($slot['label']),
                'start_at' => CarbonImmutable::parse($date.' '.$slot['start'], 'America/New_York')->toIso8601String(),
                'end_at' => CarbonImmutable::parse($date.' '.$slot['end'], 'America/New_York')->toIso8601String()];
        }
        $this->change(function (array $draft) use ($slots): array {
            $draft['slots'] = $slots;

            return $draft;
        });
        $this->confirmReview = false;
    }

    public function addSelected(bool $allEligible = false): void
    {
        $draft = $this->draft();
        $this->validate(['candidateSelection' => ['array', 'max:2000'], 'candidateSelection.*' => ['integer', 'min:1']]);
        $analysis = $this->analysis($draft, $this->cleanFilters());
        $rows = $analysis['rows'] ?? [];
        $ids = $allEligible ? array_keys($rows) : $this->candidateSelection;
        $eligible = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row && $this->eligible($row, true)) {
                $eligible[(string) $id] = ['slot_id' => null];
            }
        }
        $before = count($draft['selections']);
        $existing = count(array_intersect_key($eligible, $draft['selections']));
        $updated = $this->change(function (array $draft) use ($eligible): array {
            $draft['selections'] += $eligible;

            return $draft;
        });
        if ($updated) {
            Notification::make()->success()->title(count($updated['selections']).' proposed · '.(count($updated['selections']) - $before).' added · '.$existing.' already on list')->send();
            $this->candidateSelection = [];
        }
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
        $analysis = $this->analysis($draft);
        $course = $this->course($draft['course_id']);
        $rows = [];
        foreach ($draft['selections'] as $id => $selection) {
            $person = $analysis['rows'][$id] ?? null;
            $review = ! $person || ! $this->eligible($person) || ($selection['slot_id'] !== null && ! in_array($selection['slot_id'], $person['eligible_slots'] ?? [], true));
            if ($review && ! $this->confirmReview) {
                throw ValidationException::withMessages(['confirmReview' => 'Some proposed members need fresh verification. Review the list and acknowledge before exporting the marked proposal.']);
            }
            $slot = collect($draft['slots'])->firstWhere('id', $selection['slot_id']);
            $context = app(TrainingCrewPlannerDutyContext::class)->project($person['observed_unit_context'] ?? [], $slot);
            $rows[$id] = [
                'employee_id' => $person['employee_id'] ?? '', 'name' => $person['name'] ?? 'Previously selected member — identity requires review',
                'rank' => $person['rank'] ?? '', 'bid_position' => ($person['assignment'] ?? '').(! empty($person['is_de_bid']) ? ' · Published Driver Engineer seat' : ''),
                'station' => $context['station'], 'apparatus' => $context['apparatus'],
                'slot_id' => $selection['slot_id'], 'verification_required' => $review,
                'review_reasons' => $review ? ['Recheck identity, current attendance and full classroom-time availability before chief approval.'] : [],
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
        }, 'MBFD-Training-Proposal-'.($draft['metadata']['schedule_date'] ?? 'undated').'.'.$format, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }

    protected function getViewData(): array
    {
        $draft = $this->draft();
        $all = $this->analysis($draft);
        $filtered = $this->analysis($draft, $this->cleanFilters());
        $visible = array_filter($filtered['rows'] ?? [], fn (array $row): bool => $this->showReview || $this->eligible($row, true));
        $courses = TrainingCourse::query()->when($this->courseSearch !== '', fn ($query) => $query->where('name', 'like', '%'.mb_substr($this->courseSearch, 0, 200).'%'))->orderBy('name')->limit(500)->get()->filter(fn (TrainingCourse $course): bool => app(TrainingRosterPolicy::class)->view($this->actor(), $course));
        $chosen = $this->courseId ? $this->course($this->courseId) : null;

        $snapshotWarnings = $this->snapshotWarnings($draft['metadata']);

        return compact('draft', 'all', 'visible', 'courses', 'chosen', 'snapshotWarnings');
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

    private function analysis(array $draft, array $filters = []): array
    {
        if (! $draft['course_id']) {
            return ['rows' => [], 'session_suggestions' => []];
        }
        $staff = app(TeleStaffDutyResolver::class)->resolveStaff($draft['staff'], $draft['slots']);

        return app(TrainingPlannerCandidateService::class)->candidates($this->actor(), $this->course($draft['course_id']), $draft['module_id'], CarbonImmutable::now(), $staff, $filters, $draft['metadata']['schedule_date'] ?? null);
    }

    private function eligible(array $row, bool $applySlotFilter = false): bool
    {
        return ! empty($row['eligible_slots']) && (! $applySlotFilter || $this->candidateSlot === '' || in_array($this->candidateSlot, $row['eligible_slots'], true));
    }

    private function cleanFilters(): array
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
        if ($this->candidateSlot !== '') {
            $slot = collect($this->draft()['slots'])->firstWhere('id', $this->candidateSlot);
            if ($slot) {
                $filters['slot_start_at'] = $slot['start_at'];
                $filters['slot_end_at'] = $slot['end_at'];
            }
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
        if ($this->slotEdits === [] && ! empty($draft['metadata']['schedule_date'])) {
            $this->slotEdits = [
                ['id' => (string) Str::uuid(), 'label' => 'Morning', 'start' => '08:30', 'end' => '11:30'],
                ['id' => (string) Str::uuid(), 'label' => 'Afternoon', 'start' => '13:00', 'end' => '16:00'],
            ];
        }
    }

    private function sessionKey(): string
    {
        return 'training_crew_planner_draft.'.auth()->id();
    }

    private function slotEdit(array $slot, bool $newId = false): array
    {
        return [
            'id' => $newId ? (string) Str::uuid() : $slot['id'], 'label' => $slot['label'],
            'start' => CarbonImmutable::parse($slot['start_at'])->setTimezone('America/New_York')->format('H:i'),
            'end' => empty($slot['end_at']) ? '' : CarbonImmutable::parse($slot['end_at'])->setTimezone('America/New_York')->format('H:i'),
        ];
    }

    private function snapshotWarnings(array $metadata): array
    {
        if (empty($metadata['printed_at'])) {
            return [];
        }
        $printed = CarbonImmutable::parse($metadata['printed_at']);
        $warnings = [];
        if (! empty($metadata['schedule_date']) && $printed->lt(CarbonImmutable::parse($metadata['schedule_date'], 'America/New_York')->startOfDay())) {
            $warnings[] = 'This snapshot was generated before its roster date. Verify any staffing changes made after it was printed.';
        }
        if ($printed->lt(CarbonImmutable::now()->subDay())) {
            $warnings[] = 'This snapshot was generated more than 24 hours ago. Use it as dated planning evidence and verify later staffing changes.';
        }

        return $warnings;
    }
}
