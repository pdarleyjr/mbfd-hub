<?php

declare(strict_types=1);

namespace App\Livewire\Dashboards;

use App\Models\User;
use App\Services\Dashboards\DashboardAccess;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\DashboardSnapshot;
use App\Services\Dashboards\IssuePresenter;
use App\Services\Inspections\InspectionNotificationBatch;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardImages;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSourceRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * All of one apparatus, station or member's issues, by kind of record. One action can go to every
 * selected issue, through the same IssueSource::perform() and the same version check as the single-
 * issue panel, which still opens from each row. Nothing here writes around the sources.
 */
final class IssueGroupBoard extends Component
{
    /** No more than this many issues in one action. */
    public const MAX_BULK = 100;

    private const LOAD_FAILED = 'Some items could not be loaded just now, so this list may be out of date. It will retry on the next refresh.';

    private const KEY_PATTERN = '/^(apparatus|station|member):[1-9][0-9]{0,18}$/';

    private const SECTION_LABELS = [
        'apparatus_defect' => 'Equipment issues',
        'service_ticket' => 'Service tickets',
        'inspection_exception' => 'Inspection follow-ups',
        'inventory_correction' => 'Inventory change requests',
        'vehicle_changeout' => 'Vehicle changes',
        'station_request' => 'Station requests',
        'station_inspection' => 'Station inspections',
        'support_message' => 'Messages',
        'hub_support_ticket' => 'Member reports',
    ];

    #[Locked]
    public string $group = '';

    #[Locked]
    public string $heading = '';

    /** @var array{image: ?string, image2x: ?string, imageKind: ?string, icon: string} */
    #[Locked]
    public array $identity = ['image' => null, 'image2x' => null, 'imageKind' => null, 'icon' => 'heroicon-o-truck'];

    /** @var list<array{type: string, label: string, rows: list<array<string, mixed>>, actions: list<array<string, mixed>>}> */
    #[Locked]
    public array $sections = [];

    /** @var array<string, string> ref => the version shown to the admin, so a bulk action cannot overwrite a newer change */
    #[Locked]
    public array $versions = [];

    #[Locked]
    public string $token = '';

    /** Why this list may not be complete right now, so a gap is never read as "all handled". */
    #[Locked]
    public ?string $warning = null;

    /** @var array{tone: string, text: string}|null */
    #[Locked]
    public ?array $notice = null;

    /** @var array<string, string> "<type>.<action>.<field>" => message */
    #[Locked]
    public array $fieldErrors = [];

    /** @var list<string> refs; client-writable, so only ever used intersected with what was loaded */
    public array $selected = [];

    /** @var array<string, mixed> type => action => field => typed value; client-writable, validated before use */
    public array $input = [];

    public static function isValidKey(string $group): bool
    {
        return preg_match(self::KEY_PATTERN, $group) === 1;
    }

    public function mount(string $group): void
    {
        abort_unless(self::isValidKey($group), 404);
        $this->authorizeBoard();
        $this->group = $group;
        $this->load(false, selectOpen: true);
    }

    /** Polled: reloads the full detail only when something in this group actually changed. */
    public function refreshIfChanged(): void
    {
        $this->authorizeBoard();
        $snapshot = $this->snapshot(false);
        if ($this->keepsListAfterFailure($snapshot)) {
            return;
        }
        $versions = [];
        foreach ($this->issuesIn($snapshot) as $issue) {
            $versions[$issue->ref()] = $issue->version;
        }
        if ($versions != $this->versions) {
            $this->load(false);
        }
    }

    #[On(DashboardBoard::CHANGED_EVENT)]
    public function sourcesChanged(): void
    {
        $this->authorizeBoard();
        $this->load(true);
    }

    /** Selects every open issue of one kind, or clears them if all are selected. */
    public function toggleSection(mixed $type = null): void
    {
        $this->authorizeBoard();
        $section = collect($this->sections)->firstWhere('type', $type);
        if (! is_array($section)) {
            return;
        }
        $refs = array_column(array_filter($section['rows'], static fn (array $row): bool => $row['open']), 'ref');
        $selected = $this->selectedRefs();
        $this->selected = array_diff($refs, $selected) === []
            ? array_values(array_diff($selected, $refs))
            : array_values(array_unique([...$selected, ...$refs]));
    }

    /** $withInput is false for the one-click button: it never sends text left in a form that is not open. */
    public function apply(mixed $type = null, mixed $action = null, mixed $token = null, mixed $withInput = true): void
    {
        $this->authorizeBoard();
        // A queued second click (or a replayed request) carries a token from an earlier load.
        if (! is_string($token) || $this->token === '' || ! hash_equals($this->token, $token)) {
            return;
        }
        $section = collect($this->sections)->firstWhere('type', $type);
        $spec = is_array($section) ? collect($section['actions'])->firstWhere('key', $action) : null;
        if (! is_array($spec)) {
            $this->notice = ['tone' => 'warning', 'text' => 'That action is no longer available here.'];
            $this->load(true);

            return;
        }

        $refs = array_values(array_intersect($spec['refs'], $this->selectedRefs()));
        if ($refs === []) {
            $this->notice = ['tone' => 'warning', 'text' => 'Select at least one issue that this applies to.'];

            return;
        }
        if (count($refs) > self::MAX_BULK) {
            $this->notice = ['tone' => 'warning', 'text' => 'Select '.self::MAX_BULK.' or fewer at a time.'];

            return;
        }

        $typed = $withInput === false ? [] : data_get($this->input, $section['type'].'.'.$spec['key']);
        [$values, $errors] = IssueActionPanel::validateFields($spec, is_array($typed) ? $typed : []);
        $this->fieldErrors = [];
        foreach ($errors as $key => $message) {
            $this->fieldErrors[$section['type'].'.'.$key] = $message;
        }
        if ($errors !== []) {
            $this->notice = ['tone' => 'danger', 'text' => 'Check the highlighted fields.'];

            return;
        }

        $this->token = Str::random(32);
        $tally = $this->perform($section['type'], $spec, $refs, $values);
        $this->notice = self::summary($spec['label'], $tally);
        if ($tally['done'] > 0) {
            app(DashboardReadModel::class)->forget($this->user());
        }
        if ($withInput !== false && $tally['done'] === count($refs) && is_array($this->input[$section['type']] ?? null)) {
            unset($this->input[$section['type']][$spec['key']]);
        }
        $this->load(true);
        // A skipped issue changed under the admin. It stays unselected, so repeating the action cannot overwrite that change unseen.
        $this->selected = array_values(array_diff($this->selected, $tally['skipped']));
    }

    public function render(): View
    {
        return view('livewire.dashboards.issue-group-board', ['selectedRefs' => $this->selectedRefs()]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $refs
     * @param  array<string, string>  $values
     * @return array{done: int, conflict: int, failed: int, message: ?string, skipped: list<string>}
     */
    private function perform(string $type, array $spec, array $refs, array $values): array
    {
        $source = app(IssueSourceRegistry::class)->get($type);
        $tally = ['done' => 0, 'conflict' => 0, 'failed' => 0, 'message' => null, 'skipped' => []];
        // Only text written for members: resolution notes and internal notes stay out of the summary.
        $note = ($values['member_message'] ?? '') !== '' ? $values['member_message'] : ($spec['key'] === 'close_no_action' ? ($values['reason'] ?? null) : null);

        app(InspectionNotificationBatch::class)->collapse($this->heading, $note, function () use ($source, $spec, $refs, $values, &$tally): void {
            foreach ($refs as $ref) {
                [, $id] = IssueSourceRegistry::parseRef($ref);
                try {
                    $result = $source->perform($this->user(), $id, $spec['key'], $values, $this->versions[$ref] ?? '');
                } catch (Throwable $exception) {
                    report($exception);
                    $result = null;
                }
                $outcome = $result instanceof ActionResult ? $result->outcome : 'failed';
                match ($outcome) {
                    'done' => $tally['done']++,
                    'conflict' => $tally['conflict']++,
                    default => $tally['failed']++,
                };
                if ($outcome !== 'done') {
                    $tally['skipped'][] = $ref;
                }
                if ($outcome !== 'done' && $outcome !== 'conflict') {
                    $tally['message'] ??= $result instanceof ActionResult ? $result->message : null;
                }
            }
        });

        return $tally;
    }

    /**
     * @param  array{done: int, conflict: int, failed: int, message: ?string, skipped: list<string>}  $tally
     * @return array{tone: string, text: string}
     */
    private static function summary(string $label, array $tally): array
    {
        $parts = [$label.': '.$tally['done'].' '.Str::plural('issue', $tally['done']).' updated.'];
        if ($tally['conflict'] > 0) {
            $parts[] = $tally['conflict'].' changed since this page loaded and '.($tally['conflict'] === 1 ? 'was' : 'were').' skipped. Check '.($tally['conflict'] === 1 ? 'it' : 'them').', then select again if this still applies.';
        }
        if ($tally['failed'] > 0) {
            $parts[] = $tally['failed'].' could not be updated.'.($tally['message'] !== null ? ' '.$tally['message'] : '');
        }

        return [
            'tone' => match (true) {
                $tally['done'] === 0 => 'danger',
                $tally['conflict'] + $tally['failed'] > 0 => 'warning',
                default => 'success',
            },
            'text' => implode(' ', $parts),
        ];
    }

    private function load(bool $fresh, bool $selectOpen = false): void
    {
        $snapshot = $this->snapshot($fresh);
        if ($this->keepsListAfterFailure($snapshot)) {
            return;
        }
        $this->warning = match (true) {
            $snapshot->failedSources !== [] => self::LOAD_FAILED,
            $snapshot->moreAvailable => 'There are more items than this list can hold, so the oldest may not be shown.',
            default => null,
        };

        $issues = $this->issuesIn($snapshot);
        $sections = [];
        $versions = [];
        // Actions follow a record's status, so one fresh check per status covers the rest. perform() still checks each record.
        $offered = [];
        foreach ($issues as $issue) {
            $versions[$issue->ref()] = $issue->version;
            $sections[$issue->type] ??= ['type' => $issue->type, 'label' => self::SECTION_LABELS[$issue->type] ?? Str::headline($issue->type), 'rows' => [], 'actions' => []];
            $sections[$issue->type]['rows'][] = self::row($issue);
            if (! $issue->isOpen()) {
                continue;
            }
            $offered[$issue->type.':'.$issue->status] ??= $this->bulkActions($issue);
            foreach ($offered[$issue->type.':'.$issue->status] as $action) {
                $sections[$issue->type]['actions'][$action['key']] ??= [...$action, 'refs' => []];
                $sections[$issue->type]['actions'][$action['key']]['refs'][] = $issue->ref();
            }
        }
        foreach ($sections as $type => $section) {
            $sections[$type]['actions'] = array_values($section['actions']);
        }

        $this->sections = array_values($sections);
        $this->versions = $versions;
        // Only apply() replaces the token: a poll must never invalidate a click on its way.
        $this->token = $issues === [] ? '' : ($this->token !== '' ? $this->token : Str::random(32));
        $this->describe($issues);

        $open = array_map(static fn (DashboardIssue $issue): string => $issue->ref(), array_filter($issues, static fn (DashboardIssue $issue): bool => $issue->isOpen()));
        $this->selected = $selectOpen ? array_values($open) : array_values(array_intersect($this->selectedRefs(), $open));
    }

    /** A source that could not be read must not make a good list disappear: the admin keeps it, with a warning. */
    private function keepsListAfterFailure(DashboardSnapshot $snapshot): bool
    {
        if ($snapshot->failedSources === [] || $this->sections === []) {
            return false;
        }
        $this->warning = self::LOAD_FAILED;

        return true;
    }

    private function snapshot(bool $fresh): DashboardSnapshot
    {
        $user = $this->user();
        $view = match (true) {
            str_starts_with($this->group, 'station:') && DashboardAccess::canViewOperationsBoards($user) => DashboardView::StationOperations,
            str_starts_with($this->group, 'apparatus:') && DashboardAccess::canViewOperationsBoards($user) => DashboardView::VehicleCheckout,
            default => DashboardView::Overview,
        };

        return app(DashboardReadModel::class)->load($user, $view, fresh: $fresh);
    }

    /** @return list<DashboardIssue> this group's rows, open first, from the sources this admin may see */
    private function issuesIn(DashboardSnapshot $snapshot): array
    {
        $issues = array_values(array_filter($snapshot->rows, fn (DashboardIssue $issue): bool => $issue->groupKey === $this->group));
        usort($issues, static fn (DashboardIssue $a, DashboardIssue $b): int => ($a->isOpen() ? 0 : 1) <=> ($b->isOpen() ? 0 : 1));

        return $issues;
    }

    /** @return list<array<string, mixed>> what this issue offers in bulk, from a fresh policy check */
    private function bulkActions(DashboardIssue $issue): array
    {
        try {
            $detail = app(IssueSourceRegistry::class)->get($issue->type)->detail($this->user(), $issue->id);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
        if (! $detail instanceof IssueDetail) {
            return [];
        }

        return array_values(array_filter(IssueActionPanel::presentActions($detail->actions), static fn (array $action): bool => $action['bulk'] !== null));
    }

    /** @param  list<DashboardIssue>  $issues */
    private function describe(array $issues): void
    {
        $lead = $issues[0] ?? null;
        if ($lead === null) {
            return;
        }
        $this->heading = (string) ($lead->subject ?? $lead->actorLabel ?? $lead->title);
        $image = DashboardImages::url($lead->imageKey, 96);
        $this->identity = [
            'image' => $image,
            'image2x' => $image === null ? null : DashboardImages::url($lead->imageKey, 192),
            'imageKind' => $image === null ? null : DashboardImages::kind($lead->imageKey),
            'icon' => match (true) {
                str_starts_with($this->group, 'station:') => 'heroicon-o-building-office',
                str_starts_with($this->group, 'member:') => 'heroicon-o-user',
                default => 'heroicon-o-truck',
            },
        ];
    }

    /** @return array<string, mixed> */
    private static function row(DashboardIssue $issue): array
    {
        return [
            'ref' => $issue->ref(),
            'title' => $issue->title,
            'summary' => $issue->summary,
            'severity' => $issue->severity,
            'severityLabel' => IssuePresenter::severityLabel($issue->severity),
            'statusLabel' => $issue->statusLabel,
            'stage' => $issue->stage,
            'open' => $issue->isOpen(),
            'actor' => $issue->actorLabel,
            'time' => IssuePresenter::time($issue->occurredAt),
            'timeIso' => $issue->occurredAt->toIso8601String(),
            'cta' => IssuePresenter::cta($issue->stage),
        ];
    }

    /** @return list<string> the client's selection, limited to open issues this page loaded */
    private function selectedRefs(): array
    {
        $open = [];
        foreach ($this->sections as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['open']) {
                    $open[] = $row['ref'];
                }
            }
        }

        return array_values(array_intersect(array_filter($this->selected, 'is_string'), $open));
    }

    private function authorizeBoard(): void
    {
        abort_unless(DashboardAccess::canEnterPanel(auth()->user()), 403);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
