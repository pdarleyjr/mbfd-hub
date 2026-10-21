<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PersonnelReconciliation as Report;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PersonnelReconciliation extends Page
{
    protected static ?string $navigationGroup = 'Personnel';

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Workbook Reconciliation';

    protected static ?string $title = 'Personnel Workbook Reconciliation';

    protected static ?int $navigationSort = 14;

    protected static string $view = 'filament.pages.personnel-reconciliation';

    #[Locked]
    public ?int $reportId = null;

    public string $classification = '';

    public string $search = '';

    public int $reportPage = 1;

    public static function canAccess(): bool
    {
        return PersonnelWorkbookReconciler::canView(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->reportId = Report::query()->latest('id')->value('id');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('directory')->label('Master directory')->color('gray')->url(fn (): string => MasterPersonnelDirectory::getUrl()),
            Action::make('upload')->label('Compare workbook')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => PersonnelWorkbookReconciler::canReconcile(auth()->user()))
                ->modalDescription('Creates a private discrepancy report. Employee profiles, account access and assignments remain unchanged.')
                ->form([FileUpload::make('workbook')->required()->storeFiles(false)->maxSize(5120)
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])])
                ->action(function (array $data): void {
                    abort_unless(PersonnelWorkbookReconciler::canReconcile(auth()->user()), 403);
                    $file = $data['workbook'];
                    abort_unless($file instanceof TemporaryUploadedFile, 422);
                    $report = app(PersonnelWorkbookReconciler::class)->store(auth()->user(), $file->getRealPath(), $file->getClientOriginalName());
                    $this->reportId = $report->id;
                    $this->reportPage = 1;
                    $this->classification = '';
                    Notification::make()->title('Comparison saved')->body('No personnel or account records were changed.')->success()->send();
                }),
            Action::make('export')->label('Export comparison CSV')->color('gray')
                ->visible(fn (): bool => $this->reportId !== null && PersonnelWorkbookReconciler::canExport(auth()->user()))
                ->action(fn (): StreamedResponse => $this->exportReport()),
        ];
    }

    public function report(): ?Report
    {
        abort_unless(self::canAccess(), 403);

        return $this->reportId === null ? null : Report::query()->findOrFail($this->reportId);
    }

    /** @return list<array<string,mixed>> */
    public function filteredRows(?Report $report): array
    {
        return array_values(array_filter($report->rows ?? [], fn (array $row): bool => ($this->classification === '' || $row['classification'] === $this->classification)
            && ($this->search === '' || str_contains(mb_strtolower($row['name'].' '.$row['employee_id']), mb_strtolower(mb_substr($this->search, 0, 255))))));
    }

    public function exportReport(): StreamedResponse
    {
        abort_unless(PersonnelWorkbookReconciler::canExport(auth()->user()), 403);
        $report = $this->report();
        abort_if($report === null, 404);
        $rows = $this->filteredRows($report);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['Employee ID', 'Name', 'Review category', 'Field', 'Workbook proposal', 'Hub value', 'Review notes'], ',', '"', '');
            foreach ($rows as $row) {
                $fields = $row['differences'] !== [] ? $row['differences'] : (empty($row['live']) ? array_keys($row['workbook']) : ['']);
                foreach ($fields as $field) {
                    $values = [$row['employee_id'], $row['name'], $row['classification'], $field, $row['workbook'][$field] ?? '', $row['live'][$field] ?? '', implode(' | ', [...$row['flags'], $row['qa_note']])];
                    fputcsv($stream, array_map(fn ($value): string => preg_match('/^[\s\x00-\x1f]*[=+\-@＝＋－＠]/u', (string) $value) || preg_match('/^[\t\r\n]/', (string) $value) ? "'".$value : (string) $value, $values), ',', '"', '');
                }
            }
            fclose($stream);
        }, 'MBFD-Personnel-Reconciliation-'.$report->created_at->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
