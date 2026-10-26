<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Captain5\Captain5ItemClassification;
use App\Models\Employee;
use App\Models\User;
use App\Services\Captain5\Captain5AccessExplainer;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\MedicalScopeReview;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Captain5Access;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Administrator surface for the Captain 5 EMS Operations Center: who can use it, access
 * overrides (automatic, allow, deny), acting Captain 5 and 300 requests, and the medical scope review.
 *
 * Every decision is made by Captain5Access / Captain5AdministrationService /
 * Captain5ActingService, which authorize again and write the history and audit rows. This
 * page only gathers input; hiding a control here is cosmetic.
 */
final class Captain5Operations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $navigationLabel = 'Captain 5 Operations';

    protected static ?string $title = 'Captain 5 Operations';

    protected static ?string $slug = 'captain5-operations';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.admin.pages.captain5-operations';

    private const SEARCH_MIN_LENGTH = 2;

    private const HISTORY_LIMIT = 60;

    private const DECIDED_REQUESTS_LIMIT = 20;

    /** Member lookup text for "Who has access". */
    public string $memberSearch = '';

    #[Locked]
    public ?int $lookupEmployeeId = null;

    #[Locked]
    public bool $showOverrideHistory = false;

    /** @var array<string, mixed>|null per-request memo of the medical scope review */
    private ?array $reviewMemo = null;

    /** Per-request memo of canAccess() for the many row actions (never persisted between requests). */
    private ?bool $configureMemo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(Captain5Access::class)->canConfigure($user);
    }

    /** canAccess() once per request, so each row's action does not repeat the permission queries. */
    private function canConfigureThisRequest(): bool
    {
        return $this->configureMemo ??= self::canAccess();
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /** Runs on every Livewire request, so each update and action is re-authorized. */
    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function chooseMember(int $employeeId): void
    {
        $this->authorizeAccess();
        $employee = Employee::query()->find($employeeId);
        abort_unless($employee instanceof Employee, 404);
        $this->lookupEmployeeId = (int) $employee->getKey();
        $this->memberSearch = '';
    }

    public function clearMember(): void
    {
        $this->authorizeAccess();
        $this->lookupEmployeeId = null;
    }

    public function toggleOverrideHistory(): void
    {
        $this->authorizeAccess();
        $this->showOverrideHistory = ! $this->showOverrideHistory;
    }

    public function setAccessAction(): Action
    {
        return $this->accessAction('setAccess', 'Set Captain 5 access');
    }

    public function changeAccessAction(): Action
    {
        return $this->accessAction('changeAccess', 'Change')->color('gray')->outlined()->size('sm');
    }

    public function approveActingAction(): Action
    {
        return Action::make('approveActing')
            ->label('Approve')
            ->color('success')
            ->size('sm')
            ->authorize(fn (): bool => $this->canConfigureThisRequest())
            ->modalHeading(fn (array $arguments): string => 'Approve '.($this->actingRequestSummary($arguments) ?? 'this request').'?')
            ->modalDescription(function (array $arguments): string {
                $label = $this->actingRequestRoleLabel($arguments);

                return "Only the {$label} name shown for the shift changes. Nobody gains or loses access. If someone else is shown as {$label} now, this replaces them.";
            })
            ->modalSubmitActionLabel('Approve')
            ->form([
                Textarea::make('note')->label('Note (optional)')->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                // Role null: either role may be decided here. The service reads the role from the request row.
                $this->decideActing($action, $arguments, ['note'], function (User $actor, int $requestId) use ($data): void {
                    app(Captain5ActingService::class)->approve($actor, $requestId, (string) ($data['note'] ?? ''), null);
                }, 'Acting {role} request approved', 'The {role} name shown for the shift is updated.');
            });
    }

    public function rejectActingAction(): Action
    {
        return Action::make('rejectActing')
            ->label('Not approved')
            ->color('danger')
            ->outlined()
            ->size('sm')
            ->authorize(fn (): bool => $this->canConfigureThisRequest())
            ->modalHeading(fn (array $arguments): string => 'Not approve '.($this->actingRequestSummary($arguments) ?? 'this request').'?')
            ->modalDescription('The member is told it was not approved and sees your note. The request stays in the record.')
            ->modalSubmitActionLabel('Not approved')
            ->form([
                Textarea::make('note')->label('Why is it not approved?')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->decideActing($action, $arguments, ['note'], function (User $actor, int $requestId) use ($data): void {
                    app(Captain5ActingService::class)->reject($actor, $requestId, (string) ($data['note'] ?? ''), null);
                }, 'Acting {role} request not approved', 'The member is told, with your note.');
            });
    }

    public function classifyItemAction(): Action
    {
        return $this->classifyAction('classifyItem', 'Classify');
    }

    public function changeClassificationAction(): Action
    {
        return $this->classifyAction('changeClassification', 'Change')->color('gray')->outlined()->size('sm');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->authorizeAccess();
        $timezone = (string) config('captain5.timezone', \App\Support\HubTimezone::NAME);
        $review = $this->review();

        return [
            'lookup' => $this->lookupData(),
            'overrides' => $this->overrideRows($timezone),
            'overrideHistory' => $this->showOverrideHistory ? $this->overrideHistoryRows($timezone) : [],
            'acting' => $this->actingData($timezone),
            'pendingItems' => $review['pending'],
            'decidedItems' => $review['decided'],
            'unmatchedOpenIssues' => $review['unmatched_open_issues'],
        ];
    }

    /** One form for "Set access" and "Change": the same rules, the same service. */
    private function accessAction(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($name === 'setAccess' ? 'heroicon-o-shield-check' : null)
            ->authorize(fn (): bool => $this->canConfigureThisRequest())
            ->modalHeading('Set Captain 5 access')
            ->modalDescription('Automatic follows rank, seat and administrator access. Allow and Deny override that for one member. The change is recorded with your reason.')
            ->modalSubmitActionLabel('Save access')
            ->fillForm(function (array $arguments): array {
                $employeeId = isset($arguments['employee']) ? (int) $arguments['employee'] : null;

                return [
                    'employee_profile_id' => $employeeId,
                    'choice' => $employeeId !== null ? app(Captain5AccessExplainer::class)->currentChoice($employeeId) : null,
                ];
            })
            ->form([
                $this->memberSelect('employee_profile_id', 'Member')->live(),
                Radio::make('choice')->label('Captain 5 access')->required()->live()
                    ->options(fn (Get $get): array => $this->choiceOptions($this->intOrNull($get('employee_profile_id'))))
                    ->descriptions(Captain5AccessExplainer::CHOICE_DESCRIPTIONS),
                Checkbox::make('confirm_deny')
                    ->label('I understand this member loses all Captain 5 access, including access from their rank or seat.')
                    ->visible(fn (Get $get): bool => $get('choice') === 'deny')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm that you want to deny this member.']),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $this->perform($action, ['employee_profile_id', 'choice', 'reason'], function (User $actor) use ($data): void {
                    $employeeId = (int) ($data['employee_profile_id'] ?? 0);
                    $choice = (string) ($data['choice'] ?? '');
                    $this->guardAccessChange($actor, $employeeId, $choice, (bool) ($data['confirm_deny'] ?? false));
                    app(Captain5AdministrationService::class)->setOverride($actor, $employeeId, $choice, (string) ($data['reason'] ?? ''));
                }, 'Captain 5 access saved', 'The change and your reason are in the history below.');
            });
    }

    private function classifyAction(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->authorize(fn (): bool => $this->canConfigureThisRequest())
            ->modalHeading(fn (array $arguments): string => 'Classify '.($this->reviewItem($arguments)['item_name'] ?? 'checklist item'))
            ->modalDescription(fn (array $arguments): string => ($this->reviewItem($arguments)['checklist_label'] ?? '')
                .' checklist. Medical items are handled by Captain 5; everything else stays with Fleet. Captain 5 sees nothing from this item until you decide.')
            ->modalSubmitActionLabel('Save classification')
            ->fillForm(fn (array $arguments): array => ['classification' => $this->reviewItem($arguments)['classification'] ?? null])
            ->form([
                Radio::make('classification')->label('This item is')->required()
                    ->options([
                        Captain5ItemClassification::MEDICAL => 'Medical: Captain 5 sees and handles its issues',
                        Captain5ItemClassification::NOT_MEDICAL => 'Not medical: stays with Fleet only',
                    ]),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $item = $this->reviewItem($arguments);
                if ($item === null) {
                    Notification::make()->danger()->title('That checklist item is not in the review list')
                        ->body('Refresh the page. Only Engine and Ladder items waiting for a decision, or already decided, can be classified.')->send();
                    $action->halt();

                    return;
                }
                $this->perform($action, ['classification', 'reason'], function (User $actor) use ($data, $item): void {
                    app(Captain5AdministrationService::class)->classify(
                        $actor, $item['checklist_type'], $item['item_key'], $item['item_name'],
                        (string) ($data['classification'] ?? ''), (string) ($data['reason'] ?? ''),
                    );
                }, 'Classification saved', 'Captain 5 only sees this item\'s issues if you chose Medical.');
                $this->reviewMemo = null;
            });
    }

    /**
     * Run a service call as the signed-in administrator. Service validation errors return to the
     * modal fields; refused authorization is a 403; success is announced.
     *
     * @param  list<string>  $fields  form fields that can show a service validation error
     * @param  Closure(User): void  $work
     */
    private function perform(Action $action, array $fields, Closure $work, string $successTitle, ?string $successBody = null): void
    {
        $actor = $this->authorizeAccess();
        try {
            $work($actor);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                if (in_array($key, $fields, true)) {
                    $errors[$this->getMountedActionForm()?->getStatePath().'.'.$key] = $messages;
                } else {
                    Notification::make()->danger()->title('Nothing was changed')->body((string) ($messages[0] ?? ''))->send();
                    $action->halt();

                    return;
                }
            }
            throw ValidationException::withMessages($errors);
        }

        $notification = Notification::make()->success()->title($successTitle);
        if ($successBody !== null) {
            $notification->body($successBody);
        }
        $notification->send();
    }

    /** Page-level rules the service does not enforce. */
    private function guardAccessChange(User $actor, int $employeeId, string $choice, bool $denyConfirmed): void
    {
        if ($actor->employee_profile_id !== null && (int) $actor->employee_profile_id === $employeeId) {
            throw ValidationException::withMessages(['employee_profile_id' => 'Ask another Captain 5 administrator to change your own access.']);
        }
        if ($choice === 'deny') {
            if (! $denyConfirmed) {
                throw ValidationException::withMessages(['choice' => 'Confirm that you want to deny this member.']);
            }
            if ($this->isSuperAdmin($employeeId)) {
                throw ValidationException::withMessages(['choice' => 'Super administrators are protected recovery accounts and cannot be denied.']);
            }
        }
    }

    /** @return array<string, string> */
    private function choiceOptions(?int $employeeId): array
    {
        $options = Captain5AccessExplainer::CHOICE_LABELS;
        if ($employeeId !== null && $this->isSuperAdmin($employeeId)) {
            unset($options['deny']);
        }

        return $options;
    }

    private function isSuperAdmin(int $employeeId): bool
    {
        return User::query()->where('employee_profile_id', $employeeId)->get()
            ->contains(fn (User $user): bool => $user->hasRole('super_admin'));
    }

    private function memberSelect(string $name, string $label): Select
    {
        return Select::make($name)->label($label)->required()->searchable()
            ->placeholder('Search by name or employee ID')
            ->getSearchResultsUsing(fn (string $search): array => $this->employeeOptions($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => ($employee = Employee::query()->find((int) $value)) instanceof Employee ? $this->employeeLabel($employee) : null);
    }

    /** @return array<int, string> */
    private function employeeOptions(string $search): array
    {
        return $this->searchEmployees($search, 20)->mapWithKeys(fn (Employee $employee): array => [(int) $employee->getKey() => $this->employeeLabel($employee)])->all();
    }

    /** @return Collection<int, Employee> */
    private function searchEmployees(string $search, int $limit): Collection
    {
        $term = mb_strtolower(trim(str_replace(['%', '_', '\\'], '', $search)));
        if (mb_strlen($term) < self::SEARCH_MIN_LENGTH) {
            return collect();
        }
        $like = '%'.$term.'%';

        return Employee::query()
            ->where(fn ($query) => $query->whereNull('roster_status')->orWhere('roster_status', '!=', 'departed'))
            ->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(employee_id) LIKE ?', [$like]))
            ->orderBy('name')->limit($limit)->get();
    }

    private function employeeLabel(Employee $employee): string
    {
        return trim(($employee->display_name ?: $employee->name).($employee->rank ? ' · '.$employee->rank : ''));
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function lookupData(): array
    {
        $results = $this->searchEmployees($this->memberSearch, 8)
            ->map(fn (Employee $employee): array => ['id' => (int) $employee->getKey(), 'label' => $this->employeeLabel($employee)])->all();
        $employee = $this->lookupEmployeeId !== null ? Employee::query()->with('user')->find($this->lookupEmployeeId) : null;
        if (! $employee instanceof Employee) {
            return ['member' => null, 'results' => $results, 'searching' => mb_strlen(trim($this->memberSearch)) >= self::SEARCH_MIN_LENGTH];
        }

        return [
            'member' => ['id' => (int) $employee->getKey(), 'name' => $this->employeeLabel($employee)],
            'explained' => app(Captain5AccessExplainer::class)->explain($employee->user, $employee),
            'results' => $results,
            'searching' => mb_strlen(trim($this->memberSearch)) >= self::SEARCH_MIN_LENGTH,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function overrideRows(string $timezone): array
    {
        return Captain5AccessOverride::query()->current()->with(['employee:id,name,display_name,rank', 'setBy:id,name'])
            ->orderByDesc('id')->get()
            ->map(fn (Captain5AccessOverride $row): array => [
                'employee_id' => (int) $row->employee_profile_id,
                'member' => $this->memberName($row->employee),
                'rank' => (string) ($row->employee->rank ?? ''),
                'choice' => Captain5AccessExplainer::choiceFor($row),
                'label' => Captain5AccessExplainer::CHOICE_NAMES[Captain5AccessExplainer::choiceFor($row)],
                'reason' => (string) $row->reason,
                'set_by' => (string) ($row->setBy->name ?? 'A former administrator'),
                'at' => $this->format($row->created_at, $timezone),
            ])->values()->all();
    }

    /** Every change, newest first, including the ones that were replaced or returned to automatic. @return list<array<string, mixed>> */
    private function overrideHistoryRows(string $timezone): array
    {
        $rows = Captain5AccessOverride::query()->with(['employee:id,name,display_name,rank', 'setBy:id,name'])
            ->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();
        $ended = User::query()->whereIn('id', $rows->pluck('superseded_by_user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (Captain5AccessOverride $row): array => [
            'member' => $this->memberName($row->employee),
            'label' => Captain5AccessExplainer::CHOICE_NAMES[Captain5AccessExplainer::choiceFor($row)],
            'reason' => (string) $row->reason,
            'set_by' => (string) ($row->setBy->name ?? 'A former administrator'),
            'at' => $this->format($row->created_at, $timezone),
            'ended' => $row->superseded_at !== null
                ? 'Ended '.$this->format($row->superseded_at, $timezone).' by '.($ended[$row->superseded_by_user_id] ?? 'a former administrator')
                : 'In effect',
        ])->values()->all();
    }

    /**
     * Who is shown as the shift's Captain 5 and 300 now, the requests of both roles waiting for a
     * decision (one list), and the last decided ones (one list). Display information only: none of
     * it changes who can use the page.
     *
     * @return array{shown: list<array<string, mixed>>, pending: list<array<string, mixed>>, decided: list<array<string, mixed>>}
     */
    private function actingData(string $timezone): array
    {
        $actor = auth()->user();
        $isSuperAdmin = $actor instanceof User && $actor->hasRole('super_admin');

        $pending = Captain5ActingRequest::query()->pending()
            ->with(['employee:id,name,display_name,rank', 'requester:id,name'])
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Captain5ActingRequest $row): array => $this->requestRow($row, $timezone) + [
                'own' => $actor instanceof User && ! $isSuperAdmin && (int) $row->requested_by_user_id === (int) $actor->getKey(),
                'passed' => $row->kind === Captain5ActingRequest::KIND_ACT && $row->ends_at !== null && $row->ends_at->lessThanOrEqualTo(now()),
            ])->values()->all();

        $decided = Captain5ActingRequest::query()->whereIn('status', [Captain5ActingRequest::APPROVED, Captain5ActingRequest::REJECTED])
            ->with(['employee:id,name,display_name,rank', 'requester:id,name', 'decidedBy:id,name'])
            ->orderByDesc('decided_at')->orderByDesc('id')->limit(self::DECIDED_REQUESTS_LIMIT)->get()
            ->map(fn (Captain5ActingRequest $row): array => $this->requestRow($row, $timezone) + [
                'approved' => $row->status === Captain5ActingRequest::APPROVED,
                'status' => $row->status === Captain5ActingRequest::APPROVED ? 'Approved' : 'Not approved',
                'state' => $this->decidedState($row, $timezone),
                'decided_by' => (string) ($row->decidedBy->name ?? 'A former administrator'),
                'decided_at' => $this->format($row->decided_at, $timezone),
                'note' => (string) $row->decision_note,
            ])->values()->all();

        $roster = app(Captain5DutyRoster::class);

        return [
            'shown' => [
                $this->shownBlock(Captain5ActingRequest::ROLE_CAPTAIN_5, $roster->onDuty(), $timezone),
                $this->shownBlock(Captain5ActingRequest::ROLE_COMMAND_300, $roster->command300OnDuty(), $timezone),
            ],
            'pending' => $pending,
            'decided' => $decided,
        ];
    }

    /**
     * One "Shown now" block for a shift role.
     *
     * @return array<string, mixed>
     */
    private function shownBlock(string $role, OnDutyCaptain5 $onDuty, string $timezone): array
    {
        $label = Captain5ActingRequest::roleLabel($role);

        return [
            'role' => $role,
            'label' => $label,
            'date' => $onDuty->dutyDate->format('l, M j'),
            'shift' => $onDuty->shiftLetter,
            'name' => $onDuty->employee !== null ? $this->memberName($onDuty->employee) : null,
            'rank' => (string) ($onDuty->employee->rank ?? ''),
            'source' => match ($onDuty->source) {
                OnDutyCaptain5::SOURCE_ACTING => "Acting {$label} (approved request)",
                OnDutyCaptain5::SOURCE_BID => "{$label} bid seat",
                default => 'Not set',
            },
            'until' => $onDuty->source === OnDutyCaptain5::SOURCE_ACTING ? $this->format($onDuty->actingRequest?->ends_at, $timezone) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function requestRow(Captain5ActingRequest $row, string $timezone): array
    {
        $isEnd = $row->kind === Captain5ActingRequest::KIND_END;

        return [
            'id' => (int) $row->getKey(),
            'kind' => $row->kind,
            'role' => $row->role,
            'role_label' => Captain5ActingRequest::roleLabel((string) $row->role),
            'requester' => (string) ($row->requester->name ?? 'A former member'),
            'member' => $isEnd ? null : $this->memberName($row->employee),
            'rank' => $isEnd ? '' : (string) ($row->employee->rank ?? ''),
            'shift' => $row->shift_letter,
            'duration' => match ($row->duration) {
                '12h' => '12 hours',
                '24h' => '24 hours',
                'custom' => 'Custom time',
                default => null,
            },
            'ends_at' => $this->format($row->ends_at, $timezone),
            'requested_at' => $this->format($row->created_at, $timezone),
        ];
    }

    /** Where a decided request stands now: showing, ended early, or expired. */
    private function decidedState(Captain5ActingRequest $row, string $timezone): string
    {
        if ($row->status !== Captain5ActingRequest::APPROVED || $row->kind === Captain5ActingRequest::KIND_END) {
            return '';
        }
        if ($row->ended_at !== null) {
            return 'Ended early '.$this->format($row->ended_at, $timezone);
        }

        return $row->ends_at !== null && $row->ends_at->lessThanOrEqualTo(now())
            ? 'Expired '.$this->format($row->ends_at, $timezone)
            : 'Showing now';
    }

    /**
     * Run a decision on one acting request as the signed-in administrator. A request the
     * administrator made themselves is refused with a plain message before the service is called.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $fields
     * @param  Closure(User, int): void  $work
     */
    private function decideActing(Action $action, array $arguments, array $fields, Closure $work, string $successTitle, string $successBody): void
    {
        $actor = $this->authorizeAccess();
        $requestId = (int) ($arguments['request'] ?? 0);
        $request = Captain5ActingRequest::query()->find($requestId);
        if (! $request instanceof Captain5ActingRequest) {
            Notification::make()->danger()->title('That request is no longer here')->body('Refresh the page to see the current requests.')->send();
            $action->halt();

            return;
        }
        if ((int) $request->requested_by_user_id === (int) $actor->getKey() && ! $actor->hasRole('super_admin')) {
            Notification::make()->danger()->title('Nothing was changed')->body('Another administrator approves your own request.')->send();
            $action->halt();

            return;
        }

        $label = Captain5ActingRequest::roleLabel((string) $request->role);
        $this->perform(
            $action, $fields, fn (User $user) => $work($user, $requestId),
            str_replace('{role}', $label, $successTitle), str_replace('{role}', $label, $successBody),
        );
    }

    /**
     * What the decision modals name: "acting 300 for Name" or "ending the acting 300 display";
     * null when the request is not found.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function actingRequestSummary(array $arguments): ?string
    {
        $request = Captain5ActingRequest::query()->with(['employee:id,name,display_name', 'requester:id,name'])->find((int) ($arguments['request'] ?? 0));
        if (! $request instanceof Captain5ActingRequest) {
            return null;
        }
        $label = Captain5ActingRequest::roleLabel((string) $request->role);

        return $request->kind === Captain5ActingRequest::KIND_END
            ? "ending the acting {$label} display (asked by ".($request->requester->name ?? 'a member').')'
            : "acting {$label} for ".$this->memberName($request->employee);
    }

    /** Role name for the modal copy; reads only the role column. @param  array<string, mixed>  $arguments */
    private function actingRequestRoleLabel(array $arguments): string
    {
        $role = Captain5ActingRequest::query()->whereKey((int) ($arguments['request'] ?? 0))->value('role');

        return Captain5ActingRequest::roleLabel((string) $role);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{checklist_type: string, checklist_label: string, item_key: string, item_name: string, classification: string|null}|null
     */
    private function reviewItem(array $arguments): ?array
    {
        $type = (string) ($arguments['type'] ?? '');
        $key = (string) ($arguments['key'] ?? '');
        $review = $this->review();
        foreach ($review['pending'] as $row) {
            if ($row['checklist_type'] === $type && $row['item_key'] === $key) {
                return $row + ['classification' => null];
            }
        }
        foreach ($review['decided'] as $row) {
            if ($row['checklist_type'] === $type && $row['item_key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{pending: list<array<string, mixed>>, decided: list<array<string, mixed>>, unmatched_open_issues: int} */
    private function review(): array
    {
        return $this->reviewMemo ??= app(MedicalScopeReview::class)->build();
    }

    private function memberName(?Employee $employee): string
    {
        return $employee instanceof Employee ? trim((string) ($employee->display_name ?: $employee->name)) : 'Former member';
    }

    private function format(mixed $moment, string $timezone): string
    {
        return $moment === null ? '' : CarbonImmutable::parse($moment)->setTimezone($timezone)->format('M j, Y g:i A');
    }

    private function authorizeAccess(): User
    {
        abort_unless(self::canAccess(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
