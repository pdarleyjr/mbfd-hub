<?php

declare(strict_types=1);

namespace App\Livewire\Personnel;

use App\Filament\Resources\EmployeeResource;
use App\Models\AccidentReport;
use App\Models\Employee;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Services\Activity\MemberActivityFeed;
use App\Services\Activity\MemberActivityItem;
use App\Services\Personnel\PersonnelDocumentService;
use App\Support\Personnel\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components as Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The profile's "Documents & records" tab. Lazy: nothing here is read until the tab is opened.
 *
 * Every list reads its own source and says where each item came from. Nothing is copied into new tables.
 * Each request re-checks that the viewer may still open this profile and each section's own permission.
 */
final class EmployeeRecordsPanel extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    private const ACTIVITY_PAGE = 10;

    private const ACTIVITY_MAX = 200;

    /** Activity keys from the accident report source (AccidentReportActivitySource). */
    private const ACCIDENT_ACTIVITY_PREFIX = 'accident_report:';

    /** Candidate locations of the Fuel PIN reader; the first that exists is used. */
    private const FUEL_PIN_DIRECTORIES = [
        'App\\Services\\Personnel\\FuelPinDirectory',
        'App\\Services\\Personnel\\FuelIds\\FuelPinDirectory',
    ];

    #[Locked]
    public Employee $record;

    #[Locked]
    public int $activityShown = self::ACTIVITY_PAGE;

    /** Only a flag is kept in the page state. The PIN itself is read and printed on the render that shows it. */
    #[Locked]
    public bool $fuelPinRevealed = false;

    public function mount(Employee $record): void
    {
        $this->record = $record;
        $this->authorizeProfile();
    }

    public function placeholder(): string
    {
        return '<div class="p-4 text-sm text-gray-500" role="status">Loading documents and records.</div>';
    }

    public function loadMoreActivity(): void
    {
        $this->authorizeProfile();
        $this->activityShown = min($this->activityShown + self::ACTIVITY_PAGE, self::ACTIVITY_MAX);
    }

    public function setDocumentVisibility(string $ulid, bool $visible): void
    {
        $actor = $this->authorizeProfile();
        abort_unless(PersonnelAccess::canManageDocuments($actor), 403);
        $document = PersonnelDocument::query()->where('ulid', $ulid)->where('employee_id', $this->record->getKey())->firstOrFail();
        app(PersonnelDocumentService::class)->setVisibility($document, $visible, $actor);
        Notification::make()->success()->title($visible ? "Shown in the member's My Documents" : "Hidden from the member's My Documents")->send();
    }

    public function revealFuelPin(): void
    {
        $actor = $this->authorizeProfile();
        abort_unless($this->canViewFuelPin($actor), 403);
        // The Fuel ID directory records who revealed which PIN; the PIN is then printed on this render only.
        $directory = $this->fuelDirectory();
        if ($directory !== null && method_exists($directory, 'currentRow') && method_exists($directory, 'reveal')) {
            $row = $directory->currentRow((int) $this->record->getKey());
            if ($row !== null) {
                $directory->reveal($actor, $row);
            }
        }
        $this->fuelPinRevealed = true;
    }

    public function hideFuelPin(): void
    {
        $this->fuelPinRevealed = false;
    }

    public function uploadDocumentsAction(): Action
    {
        return Action::make('uploadDocuments')->label('Upload document')->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => PersonnelAccess::canManageDocuments($this->actor()))
            ->modalHeading('Upload personnel documents')
            ->modalDescription(fn (): string => 'Files are stored privately on '.$this->record->name."'s record. Each file becomes its own document.")
            ->modalSubmitActionLabel('Upload')
            ->form([
                Forms\FileUpload::make('files')->label('Files')->multiple()->required()->maxFiles(10)
                    ->storeFiles(false)->maxSize((int) (PersonnelDocumentService::MAX_UPLOAD_BYTES / 1024))
                    ->helperText('PDF, image, Word, Excel or Outlook files, 25 MB or less each.'),
                Forms\Select::make('category')->label('Category')->options(PersonnelDocument::CATEGORIES)->required(),
                Forms\TextInput::make('title')->label('Title')->maxLength(200)
                    ->helperText('Leave blank to use each file name. With several files the title is numbered.'),
                Forms\Textarea::make('description')->label('Description')->maxLength(1000)->rows(2),
                Forms\Toggle::make('visible_to_employee')->label("Show in member's My Documents")->default(false),
                Forms\Checkbox::make('confirm')->label(fn (): string => 'These files belong to '.$this->record->name.' ('.$this->record->employee_id.').')
                    ->accepted()->required(),
            ])
            ->action(fn (array $data) => $this->storeUploads($data));
    }

    /** @param array<string, mixed> $data */
    private function storeUploads(array $data): void
    {
        $actor = $this->authorizeProfile();
        abort_unless(PersonnelAccess::canManageDocuments($actor), 403);
        $files = array_values(array_filter((array) ($data['files'] ?? []), fn ($file): bool => $file instanceof \Illuminate\Http\UploadedFile));
        if ($files === []) {
            throw ValidationException::withMessages(['mountedActionsData.0.files' => 'Choose at least one file.']);
        }
        $title = trim((string) ($data['title'] ?? ''));
        $service = app(PersonnelDocumentService::class);
        $stored = 0;
        $refused = [];
        // Each file is its own record, so one refused file never discards the others.
        foreach ($files as $index => $file) {
            try {
                $service->upload($file, $file->getClientOriginalName(), $this->record, $actor, [
                    'title' => $title === '' ? null : (count($files) > 1 ? $title.' ('.($index + 1).')' : $title),
                    'category' => $data['category'] ?? null,
                    'description' => $data['description'] ?? null,
                    'visible_to_employee' => (bool) ($data['visible_to_employee'] ?? false),
                ]);
                $stored++;
            } catch (ValidationException $exception) {
                $refused[] = $file->getClientOriginalName().': '.collect($exception->errors())->flatten()->first();
            }
        }
        if ($refused !== []) {
            Notification::make()->danger()->persistent()
                ->title($stored === 0 ? 'No documents were uploaded' : $stored.' uploaded, '.count($refused).' not uploaded')
                ->body(implode("\n", $refused))->send();

            return;
        }
        Notification::make()->success()->title($stored === 1 ? 'Document uploaded' : $stored.' documents uploaded')->send();
    }

    public function render(): View
    {
        $actor = $this->authorizeProfile();
        $showDocuments = PersonnelAccess::canViewDocuments($actor) || PersonnelAccess::canViewAccidents($actor);
        $activity = $this->activity();

        return view('livewire.personnel.employee-records-panel', [
            'showDocuments' => $showDocuments,
            'documents' => $showDocuments ? $this->documents($actor) : collect(),
            'canManageDocuments' => PersonnelAccess::canManageDocuments($actor),
            'showAccidents' => PersonnelAccess::canViewAccidents($actor),
            'accidents' => PersonnelAccess::canViewAccidents($actor) ? $this->accidents() : collect(),
            'activity' => array_slice($activity['items'], 0, $this->activityShown),
            'activityTotal' => count($activity['items']),
            'activityMessage' => $activity['message'],
            'showFuelPin' => $this->canViewFuelPin($actor),
            'fuelPin' => $this->fuelPinRevealed && $this->canViewFuelPin($actor) ? $this->fuelPin() : null,
            'fuelPinAvailable' => $this->fuelDirectory() !== null,
        ]);
    }

    /** @return Collection<int, PersonnelDocument> current versions this viewer may open, newest first */
    private function documents(User $actor): Collection
    {
        return PersonnelDocument::query()->where('employee_id', $this->record->getKey())->current()
            ->with(['uploader:id,name', 'events' => fn ($events) => $events->with('actor:id,name')->latest('id')->limit(5)])
            ->latest('id')->limit(200)->get()
            ->filter(fn (PersonnelDocument $document): bool => PersonnelAccess::canViewDocument($actor, $document))
            ->values();
    }

    /**
     * @return list<string>
     */
    private function rolesIn(AccidentReport $report, int $id): array
    {
        $roles = [];
        if ((int) $report->subject_employee_id === $id) {
            $roles[] = 'Employee involved';
        }
        if ((int) $report->reporter_employee_id === $id) {
            $roles[] = 'Reporter';
        }
        if ((int) $report->supervisor_employee_id === $id) {
            $roles[] = 'Supervisor';
        }

        return $roles;
    }

    /** @return Collection<int, array{report: AccidentReport, roles: list<string>, url: ?string}> */
    private function accidents(): Collection
    {
        $id = (int) $this->record->getKey();
        $rows = collect();
        foreach (AccidentReport::query()->involvingEmployee($id)->submitted()->latest('occurred_at')->limit(100)->get() as $report) {
            $rows->push([
                'report' => $report,
                'roles' => $this->rolesIn($report, $id),
                'url' => $this->accidentUrl($report),
            ]);
        }

        return $rows;
    }

    private function accidentUrl(AccidentReport $report): ?string
    {
        $resource = 'App\\Filament\\Resources\\AccidentReportResource';
        if (! class_exists($resource)) {
            return null;
        }
        try {
            return $resource::getUrl('view', ['record' => $report]);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{items: list<MemberActivityItem>, message: ?string} */
    private function activity(): array
    {
        $user = $this->record->user;
        if (! $user instanceof User) {
            return ['items' => [], 'message' => 'This member has no login account, so there is no member activity to show.'];
        }

        try {
            $items = app(MemberActivityFeed::class)->forPage($user, $this->record);
            // The member sees their own accident reports; an administrator needs the accident permission for them.
            if (! PersonnelAccess::canViewAccidents($this->actor())) {
                $items = array_values(array_filter($items, fn (MemberActivityItem $item): bool => ! str_starts_with($item->key, self::ACCIDENT_ACTIVITY_PREFIX)));
            }
        } catch (Throwable $exception) {
            report($exception);

            return ['items' => [], 'message' => 'Member activity could not be loaded. Try again in a moment.'];
        }

        return ['items' => $items, 'message' => $items === []
            ? 'Nothing in the last 180 days: no inspections, issues, requests, corrections or messages from this member.' : null];
    }

    /** A link an administrator can open; member-portal pages only open for the member, so those show their source only. */
    public static function adminLink(MemberActivityItem $item): ?string
    {
        $path = (string) parse_url($item->url, PHP_URL_PATH);

        return $path !== '' && ! str_starts_with($path, '/employee') ? $item->url : null;
    }

    private function canViewFuelPin(User $actor): bool
    {
        $access = 'App\\Services\\Personnel\\FuelIds\\FuelIdAccess';
        if (class_exists($access)) {
            return $access::canView($actor);
        }

        return $actor->isAuthenticationAllowed() && ($actor->hasRole('super_admin')
            || $actor->hasDirectWebPermission('personnel.fuel_ids.view') || $actor->hasDirectWebPermission('personnel.fuel_ids.manage'));
    }

    private function fuelDirectory(): ?object
    {
        foreach (self::FUEL_PIN_DIRECTORIES as $class) {
            if (class_exists($class)) {
                return app($class);
            }
        }

        return null;
    }

    private function fuelPin(): ?string
    {
        $directory = $this->fuelDirectory();
        if ($directory === null || ! method_exists($directory, 'currentFor')) {
            return null;
        }
        $current = $directory->currentFor($this->record);
        $pin = is_object($current) ? ($current->pin ?? null) : null;

        return is_string($pin) && $pin !== '' ? $pin : null;
    }

    private function actor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    private function authorizeProfile(): User
    {
        $actor = $this->actor();
        abort_unless($actor instanceof User && $actor->isAuthenticationAllowed() && EmployeeResource::canEdit($this->record), 403);

        return $actor;
    }
}
