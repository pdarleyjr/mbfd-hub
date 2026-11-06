<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Employee;
use App\Models\FuelIdRow;
use App\Models\FuelIdSource;
use App\Models\User;
use App\Services\Personnel\FuelIdImporter;
use App\Services\Personnel\FuelIds\FuelIdAccess;
use App\Services\Personnel\FuelIds\FuelIdImportException;
use App\Services\Personnel\FuelPinDirectory;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Fuel ID Numbers: the imported City fuel list, the employee to Fuel PIN mapping, and the rows an administrator
 * still has to decide. PINs are masked; each Show is audited. Every action re-checks access on the server.
 */
final class FuelIdNumbers extends Page
{
    use \App\Filament\Concerns\HasDatasetExportActions;

    protected static ?string $navigationGroup = 'Personnel';

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationLabel = 'Fuel ID Numbers';

    protected static ?string $title = 'Fuel ID Numbers';

    protected static ?string $slug = 'fuel-id-numbers';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.fuel-id-numbers';

    private const PER_PAGE = 50;

    private const STAGING_PREFIX = 'fuel-ids/staging/';

    public string $search = '';

    public int $mappingPage = 1;

    /** @var array<int, true> Row ids whose PIN this administrator chose to show (each one audited). */
    #[Locked]
    public array $revealed = [];

    #[Locked]
    public ?string $stagedPath = null;

    #[Locked]
    public ?string $stagedName = null;

    #[Locked]
    public ?string $stagedFormat = null;

    /** @var array<string, mixed>|null Dry-run summary of the staged file (counts and masked exceptions only). */
    #[Locked]
    public ?array $stagedSummary = null;

    public static function canAccess(): bool
    {
        return FuelIdAccess::canView(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(FuelIdAccess::canView($this->actor()), 403);
    }

    public function updatedSearch(): void
    {
        $this->mappingPage = 1;
    }

    public function goToMappingPage(int $page): void
    {
        $this->mappingPage = max(1, $page);
    }

    public function reveal(int $rowId): void
    {
        $actor = $this->actor();
        abort_unless(FuelIdAccess::canView($actor), 403);
        $row = FuelIdRow::query()->whereKey($rowId)
            ->where(fn ($query) => $query->whereNotNull('current_employee_id')->orWhereIn('match_status', FuelIdRow::OPEN_EXCEPTIONS))
            ->firstOrFail();
        app(FuelPinDirectory::class)->reveal($actor, $row);
        $this->revealed[$row->id] = true;
    }

    public function hide(int $rowId): void
    {
        unset($this->revealed[$rowId]);
    }

    public function isRevealed(int $rowId): bool
    {
        return isset($this->revealed[$rowId]) && FuelIdAccess::canView($this->actor());
    }

    public function canManage(): bool
    {
        return FuelIdAccess::canManage($this->actor());
    }

    public function latestSource(): ?FuelIdSource
    {
        abort_unless(FuelIdAccess::canView($this->actor()), 403);

        return app(FuelPinDirectory::class)->latestSource();
    }

    public function mappings(): LengthAwarePaginator
    {
        abort_unless(FuelIdAccess::canView($this->actor()), 403);

        return app(FuelPinDirectory::class)->mappingQuery($this->search)
            ->paginate(self::PER_PAGE, ['*'], 'mappingPage', $this->mappingPage);
    }

    /** @return Collection<int, FuelIdRow> */
    public function exceptions(): Collection
    {
        abort_unless(FuelIdAccess::canView($this->actor()), 403);

        return app(FuelPinDirectory::class)->exceptionsQuery()->limit(500)->get();
    }

    /** Export for Fuel ID managers: who has a PIN on file and the open exceptions. PINs themselves are never exported. */
    public function canExportData(User $actor): bool
    {
        return FuelIdAccess::canManage($actor);
    }

    protected function exportDataset(User $actor): \App\Services\Exports\ExportDataset
    {
        abort_unless((string) auth()->id() === (string) $actor->getKey() && $this->canExportData($actor), 403);
        $rows = app(FuelPinDirectory::class)->mappingQuery('')->limit(2000)->get()
            ->map(fn (FuelIdRow $row): array => [
                $row->employee?->name, $row->employee?->employee_id, $row->source_employee_number,
                trim($row->last_name.', '.$row->first_name, ', '), $row->match_status, 'On file', $row->department,
            ])->all();

        return \App\Services\Exports\ExportDataset::fromRows($actor, 'Fuel ID Numbers (PINs not included)',
            ['Hub employee', 'Hub Employee ID', 'List Employee ID', 'Name on list', 'Status', 'Fuel PIN', 'Department'],
            $rows, 'Current fuel ID mapping; PINs are never exported');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->datasetExportAction(),
            Action::make('import')->label('Replace source / import')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => $this->canManage())
                ->modalHeading('Replace source / import')
                ->modalDescription('Upload the City fuel list as a PDF, or a CSV with the same columns. You will see what the import would do before anything is saved.')
                ->modalSubmitActionLabel('Check file')
                ->form([
                    FileUpload::make('file')->label('Fuel list (PDF or CSV)')->required()->storeFiles(false)
                        ->maxSize((int) config('fuel-ids.max_upload_kb', 10240))
                        ->acceptedFileTypes(['application/pdf', 'text/csv', 'text/plain', 'application/vnd.ms-excel']),
                ])
                ->action(fn (array $data) => $this->stage($data['file'] ?? null)),
        ];
    }

    public function confirmImportAction(): Action
    {
        return Action::make('confirmImport')->label('Import this file')->color('primary')
            ->visible(fn (): bool => $this->canManage() && $this->stagedPath !== null)
            ->requiresConfirmation()
            ->modalDescription('Matched PINs replace each employee\'s current PIN. Exceptions are not assigned to anyone until resolved.')
            ->action(fn () => $this->confirmImport());
    }

    public function cancelImportAction(): Action
    {
        return Action::make('cancelImport')->label('Cancel')->color('gray')
            ->visible(fn (): bool => $this->canManage() && $this->stagedPath !== null)
            ->action(fn () => $this->clearStaged());
    }

    public function resolveAction(): Action
    {
        return Action::make('resolve')->label('Resolve')->icon('heroicon-o-user-plus')->size('sm')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading('Assign this Fuel PIN')
            ->modalDescription('The PIN becomes the chosen employee\'s current Fuel PIN. Their previous PIN row is kept as superseded.')
            ->fillForm(fn (array $arguments): array => [
                'employee_id' => FuelIdRow::query()->whereKey($arguments['row'] ?? 0)->value('candidate_employee_id'),
            ])
            ->form([
                Select::make('employee_id')->label('Employee')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->employeeOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => $this->employeeLabel((int) $value)),
                Textarea::make('note')->label('Note')->required()->maxLength(1000)->rows(3)
                    ->helperText('Explain how you confirmed this person. The note is kept with the record.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $actor = $this->actor();
                abort_unless(FuelIdAccess::canManage($actor), 403);
                $row = $this->openException($arguments);
                $employee = Employee::query()->findOrFail((int) ($data['employee_id'] ?? 0));
                try {
                    app(FuelPinDirectory::class)->resolve($actor, $row, $employee, (string) ($data['note'] ?? ''));
                } catch (FuelIdImportException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Fuel PIN assigned')->success()->send();
            });
    }

    public function dismissAction(): Action
    {
        return Action::make('dismiss')->label('Dismiss')->icon('heroicon-o-x-mark')->color('gray')->size('sm')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading('Dismiss this row')
            ->modalDescription('The row stays on file but is not assigned to anyone.')
            ->form([Textarea::make('note')->label('Note')->required()->maxLength(1000)->rows(3)])
            ->action(function (array $arguments, array $data): void {
                $actor = $this->actor();
                abort_unless(FuelIdAccess::canManage($actor), 403);
                $row = $this->openException($arguments);
                try {
                    app(FuelPinDirectory::class)->dismiss($actor, $row, (string) ($data['note'] ?? ''));
                } catch (FuelIdImportException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Row dismissed')->success()->send();
            });
    }

    private function stage(mixed $file): void
    {
        abort_unless($this->canManage(), 403);
        abort_unless($file instanceof TemporaryUploadedFile, 422);
        $this->clearStaged();
        $name = $file->getClientOriginalName();
        try {
            $plan = app(FuelIdImporter::class)->plan($file->getRealPath(), 'auto', $name);
        } catch (FuelIdImportException $exception) {
            Notification::make()->title('The file could not be imported')->body($exception->getMessage())->danger()->send();

            return;
        }
        if ($plan->alreadyImported) {
            Notification::make()->title('This file has already been imported')->body('Nothing changed.')->info()->send();

            return;
        }
        $path = self::STAGING_PREFIX.Str::uuid()->toString().'.'.$plan->format;
        $stream = fopen($file->getRealPath(), 'rb');
        $stored = $stream !== false && Storage::disk($this->disk())->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (! $stored) {
            Notification::make()->title('The file could not be staged')->danger()->send();

            return;
        }
        $this->stagedPath = $path;
        $this->stagedName = Str::limit($name, 250, '');
        $this->stagedFormat = $plan->format;
        $this->stagedSummary = $plan->summary();
    }

    private function confirmImport(): void
    {
        $actor = $this->actor();
        abort_unless(FuelIdAccess::canManage($actor), 403);
        $path = $this->stagedPath;
        abort_unless(is_string($path) && str_starts_with($path, self::STAGING_PREFIX) && ! str_contains($path, '..'), 422);
        $disk = Storage::disk($this->disk());
        abort_unless($disk->exists($path), 410);
        $local = tempnam(sys_get_temp_dir(), 'fuel-ids-');
        try {
            $input = $disk->readStream($path);
            $output = $local !== false ? fopen($local, 'wb') : false;
            abort_unless(is_resource($input) && is_resource($output), 500);
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            $result = app(FuelIdImporter::class)->import($local, $actor, (string) $this->stagedFormat, $this->stagedName);
        } catch (FuelIdImportException $exception) {
            Notification::make()->title('The file could not be imported')->body($exception->getMessage())->danger()->send();

            return;
        } finally {
            if (is_string($local)) {
                @unlink($local);
            }
        }
        $this->clearStaged();
        $this->revealed = [];
        Notification::make()
            ->title($result->imported ? 'Fuel list imported' : 'This file has already been imported')
            ->body($result->imported ? "{$result->plan->counts()[FuelIdRow::MATCHED]} PINs matched. ".count($result->plan->exceptions()).' rows need review.' : 'Nothing changed.')
            ->success()->send();
    }

    private function clearStaged(): void
    {
        if (is_string($this->stagedPath) && str_starts_with($this->stagedPath, self::STAGING_PREFIX)) {
            Storage::disk($this->disk())->delete($this->stagedPath);
        }
        $this->stagedPath = $this->stagedName = $this->stagedFormat = null;
        $this->stagedSummary = null;
    }

    /** @param array<string, mixed> $arguments */
    private function openException(array $arguments): FuelIdRow
    {
        return FuelIdRow::query()->whereKey((int) ($arguments['row'] ?? 0))
            ->whereIn('match_status', FuelIdRow::OPEN_EXCEPTIONS)->firstOrFail();
    }

    /** @return array<int, string> */
    private function employeeOptions(string $search): array
    {
        abort_unless($this->canManage(), 403);
        $like = '%'.mb_strtolower(addcslashes(trim(mb_substr($search, 0, 100)), '%_\\')).'%';

        return Employee::query()->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(employee_id) LIKE ?', [$like]))
            ->orderBy('name')->limit(25)->get(['id', 'name', 'employee_id', 'roster_status'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => $this->formatEmployee($employee)])->all();
    }

    private function employeeLabel(int $id): ?string
    {
        $employee = Employee::query()->find($id, ['id', 'name', 'employee_id', 'roster_status']);

        return $employee instanceof Employee ? $this->formatEmployee($employee) : null;
    }

    private function formatEmployee(Employee $employee): string
    {
        return trim(($employee->name ?: 'Unnamed').' · ID '.($employee->employee_id ?: 'none').($employee->roster_status === 'departed' ? ' · Departed' : ''));
    }

    private function disk(): string
    {
        return (string) config('filesystems.private', 'local');
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
