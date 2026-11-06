<?php

declare(strict_types=1);

namespace App\Livewire\Personnel;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Support\PersonnelTableSchema;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\User;
use App\Services\Personnel\PersonnelTableService;
use Filament\Forms\Components as Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The profile's "Additional details" tab: master-directory columns an administrator placed on profiles.
 * Values live in the master table's entries and every save goes through PersonnelTableService::setCell, so the
 * directory, its versioning and its history stay the one record. Required fields are enforced here.
 *
 * @property Form $form
 */
final class EmployeeProfileFieldsPanel extends Component implements HasForms
{
    use InteractsWithForms;

    #[Locked]
    public Employee $record;

    #[Locked]
    public int $entryVersion = 0;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Whether any profile field exists that this viewer may see. One query; used for the tab's visibility. */
    public static function hasFieldsFor(mixed $actor): bool
    {
        if (! $actor instanceof User) {
            return false;
        }
        $service = app(PersonnelTableService::class);
        $table = $service->masterTable();
        if ($table === null || ! $service->canViewDirectory($actor, refreshActor: false)) {
            return false;
        }

        return self::columnsFor($actor, $table) !== [];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function columnsFor(User $actor, PersonnelTable $table): array
    {
        $service = app(PersonnelTableService::class);
        $sections = [];
        foreach (PersonnelTableService::profileSections($table) as $section => $columns) {
            $visible = array_values(array_filter($columns, fn (array $column): bool => $service->canSeeColumn($actor, $table, $column)));
            if ($visible !== []) {
                $sections[$section] = $visible;
            }
        }

        return $sections;
    }

    public function mount(Employee $record): void
    {
        $this->record = $record;
        $this->authorizeView();
        $this->fillFromEntry();
    }

    public function placeholder(): string
    {
        return '<div class="p-4 text-sm text-gray-500" role="status">Loading additional details.</div>';
    }

    public function form(Form $form): Form
    {
        $actor = $this->actor();
        $table = $this->table();
        $editable = $actor instanceof User && $table !== null && $this->canEdit($actor, $table);
        $sections = $actor instanceof User && $table !== null ? self::columnsFor($actor, $table) : [];

        return $form->statePath('data')->schema(array_map(
            fn (string $section, array $columns): Forms\Section => Forms\Section::make($section)->columns(2)->schema(array_map(
                fn (array $column): Forms\Component => $this->input($column, $editable), $columns,
            )),
            array_keys($sections), $sections,
        ));
    }

    public function save(): void
    {
        $actor = $this->authorizeView();
        $table = $this->table();
        abort_unless($table !== null && $this->canEdit($actor, $table), 403);
        $state = $this->form->getState();
        $values = $state['values'] ?? [];
        $columns = array_merge(...array_values(self::columnsFor($actor, $table)) ?: [[]]);
        $missing = [];
        foreach ($columns as $column) {
            if (($column['required'] ?? false) && $column['type'] !== 'checkbox' && blank($values[$column['id']] ?? null)) {
                $missing['data.values.'.$column['id']] = $column['label'].' is required.';
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $service = app(PersonnelTableService::class);
        $current = $table->entries()->where('employee_id', $this->record->getKey())->first()->values ?? [];
        try {
            $version = DB::transaction(function () use ($service, $actor, $table, $columns, $values, $current): int {
                $version = $this->entryVersion;
                foreach ($columns as $column) {
                    $id = $column['id'];
                    $value = $values[$id] ?? ($column['type'] === 'checkbox' ? false : null);
                    if ($value === '' || $value === []) {
                        $value = $column['type'] === 'multiselect' ? [] : null;
                    }
                    // Only changed fields are written, so the history shows what this save actually changed.
                    if ($this->sameValue($current[$id] ?? null, $value, $column['type'])) {
                        continue;
                    }
                    $version = (int) $service->setCell($actor, $table, $this->record, $id, $value, $version)->version;
                }

                return $version;
            }, 3);
        } catch (ValidationException $exception) {
            $this->fillFromEntry();
            throw ValidationException::withMessages(['data.conflict' => collect($exception->errors())->flatten()->first()
                ?? 'These details changed. Your changes were not saved. Review the current values and try again.']);
        }
        $this->entryVersion = $version;
        $this->fillFromEntry();
        Notification::make()->success()->title('Additional details saved')->send();
    }

    public function render(): View
    {
        $actor = $this->authorizeView();
        $table = $this->table();

        return view('livewire.personnel.employee-profile-fields-panel', [
            'editable' => $table !== null && $this->canEdit($actor, $table),
            'hasFields' => $table !== null && self::columnsFor($actor, $table) !== [],
        ]);
    }

    private function input(array $column, bool $editable): Forms\Component
    {
        $input = $column['type'] === 'link'
            ? Forms\TextInput::make('values.'.$column['id'])->label($column['label'])->url()->maxLength(2000)->rule('url:http,https')
                ->helperText(fn (mixed $state): ?HtmlString => ($link = PersonnelTableService::safeLink($state)) === null ? null
                    : new HtmlString('<a href="'.e($link).'" target="_blank" rel="noopener noreferrer" class="underline">Open link</a>'))
            : PersonnelTableSchema::trackingInput($column);
        if (($column['required'] ?? false) && $column['type'] !== 'checkbox') {
            $input->required();
        }

        return $input->disabled(! $editable);
    }

    private function sameValue(mixed $before, mixed $after, string $type): bool
    {
        return match ($type) {
            'checkbox' => (bool) $before === (bool) $after,
            'number' => ($before === null || $before === '') ? ($after === null || $after === '') : ($after !== null && $after !== '' && (float) $before === (float) $after),
            'multiselect' => array_values((array) $before) === array_values((array) $after),
            default => (string) ($before ?? '') === (string) ($after ?? ''),
        };
    }

    private function fillFromEntry(): void
    {
        $table = $this->table();
        $entry = $table?->entries()->where('employee_id', $this->record->getKey())->first();
        $this->entryVersion = (int) ($entry->version ?? 0);
        $actor = $this->actor();
        $values = [];
        foreach ($actor instanceof User && $table !== null ? self::columnsFor($actor, $table) : [] as $columns) {
            foreach ($columns as $column) {
                $values[$column['id']] = $entry?->values[$column['id']] ?? ($column['type'] === 'checkbox' ? false : ($column['type'] === 'multiselect' ? [] : null));
            }
        }
        $this->form->fill(['values' => $values]);
    }

    private function table(): ?PersonnelTable
    {
        return app(PersonnelTableService::class)->masterTable();
    }

    private function canEdit(User $actor, PersonnelTable $table): bool
    {
        return app(PersonnelTableService::class)->canManage($actor, $table);
    }

    private function actor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    private function authorizeView(): User
    {
        $actor = $this->actor();
        abort_unless($actor instanceof User && $actor->isAuthenticationAllowed() && EmployeeResource::canEdit($this->record)
            && app(PersonnelTableService::class)->canViewDirectory($actor), 403);

        return $actor;
    }
}
