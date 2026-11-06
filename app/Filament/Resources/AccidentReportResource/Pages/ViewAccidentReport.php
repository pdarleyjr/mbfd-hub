<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccidentReportResource\Pages;

use App\Filament\Resources\AccidentReportResource;
use App\Jobs\GenerateAccidentReportPdf;
use App\Models\AccidentReport;
use App\Models\User;
use App\Services\Accidents\AccidentReportSchema;
use App\Services\Accidents\AccidentReportService;
use App\Support\Personnel\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * One accident report for authorized administrators: every official field, the finished PDF, who did what and
 * when, and the follow-up actions. Opening the page records nothing as resolved.
 */
class ViewAccidentReport extends Page
{
    protected static string $resource = AccidentReportResource::class;

    protected static string $view = 'filament.accident-reports.view';

    #[Locked]
    public string $reportUlid;

    public function mount(string $record): void
    {
        abort_unless(AccidentReportResource::canViewAny(), 403);
        $report = AccidentReport::query()->submitted()->where('ulid', $record)->firstOrFail();
        $this->reportUlid = $report->ulid;
    }

    public function report(): AccidentReport
    {
        return AccidentReport::query()->with(['subject', 'reporter', 'supervisor', 'vehicle.apparatus', 'document', 'events.actor', 'acknowledgedBy'])
            ->where('ulid', $this->reportUlid)->firstOrFail();
    }

    public function getTitle(): string|Htmlable
    {
        $report = $this->report();

        return $report->typeLabel().' '.$report->reference;
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::SevenExtraLarge;
    }

    protected function getViewData(): array
    {
        $report = $this->report();
        $sections = [];
        if ($report->report_type === AccidentReport::TYPE_VEHICLE) {
            $sections['Driver\'s part (page 2)'] = $this->rows(AccidentReportSchema::DRIVER_FIELDS, $report->driver_data ?? []);
            $sections['Supervisor\'s part (page 1)'] = $report->supervisor_completed_at
                ? $this->rows(AccidentReportSchema::VEHICLE_SUPERVISOR_FIELDS, $report->supervisor_data ?? [])
                : [];
        } else {
            $sections['Supervisor\'s Report of Accident'] = $this->rows(AccidentReportSchema::SUPERVISOR_REPORT_FIELDS, $report->driver_data ?? []);
        }

        return ['report' => $report, 'sections' => $sections, 'canManage' => PersonnelAccess::canManageAccidents($this->actor())];
    }

    /** @return list<array{label: string, value: string, long: bool}> */
    private function rows(array $fields, array $data): array
    {
        $rows = [];
        foreach ($fields as $key => $kind) {
            $rows[] = [
                'label' => AccidentReportSchema::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'value' => AccidentReportSchema::displayValue($key, $data[$key] ?? null),
                'long' => str_starts_with($kind, 'text:') && (int) substr($kind, 5) > 600,
            ];
        }

        return $rows;
    }

    protected function getHeaderActions(): array
    {
        $service = fn (): AccidentReportService => app(AccidentReportService::class);
        $report = fn (): AccidentReport => $this->report();
        $manage = fn (): bool => PersonnelAccess::canManageAccidents($this->actor());

        return [
            Action::make('acknowledge')->label('Acknowledge')->icon('heroicon-o-check-circle')->color('danger')
                ->visible(fn (): bool => PersonnelAccess::canAcknowledgeAccidents($this->actor()) && $report()->alert_acknowledged_at === null)
                ->requiresConfirmation()
                ->modalDescription('Records that you have seen this accident report. The accident stays open until it is resolved.')
                ->action(function () use ($service, $report): void {
                    $service()->acknowledge($this->actor(), $report());
                    Notification::make()->title('Acknowledged')->success()->send();
                }),
            Action::make('resolve')->label('Resolve')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (): bool => $manage() && $report()->resolved_at === null)
                ->form([Textarea::make('note')->label('How was it addressed?')->required()->maxLength(1000)->rows(3)])
                ->action(function (array $data) use ($service, $report): void {
                    $service()->resolve($this->actor(), $report(), $data['note']);
                    Notification::make()->title('Accident resolved')->success()->send();
                }),
            ActionGroup::make([
                Action::make('followUp')->label(fn (): string => $report()->follow_up_required ? 'Clear follow-up' : 'Mark follow-up required')
                    ->icon('heroicon-o-flag')
                    ->visible($manage)
                    ->form(fn (): array => $report()->follow_up_required ? [] : [Textarea::make('note')->label('What follow-up is needed?')->required()->maxLength(1000)])
                    ->action(function (array $data) use ($service, $report): void {
                        $current = $report();
                        $service()->setFollowUp($this->actor(), $current, ! $current->follow_up_required, $data['note'] ?? null);
                        Notification::make()->title('Saved')->success()->send();
                    }),
                Action::make('amend')->label('Correct an entry')->icon('heroicon-o-pencil-square')
                    ->visible($manage)
                    ->form(fn (): array => [
                        Select::make('field')->label('Field')->required()->searchable()->options(fn (): array => $this->amendableFields()),
                        TextInput::make('value')->label('Corrected value')->required()->maxLength(8000)
                            ->helperText('Dates as YYYY-MM-DD, times as HH:MM, yes/no answers as yes, no or na.'),
                        Textarea::make('reason')->label('Reason for the correction')->required()->maxLength(1000),
                    ])
                    ->action(function (array $data) use ($service, $report): void {
                        [$section, $key] = explode('.', (string) $data['field'], 2);
                        $service()->amend($this->actor(), $report(), $section, [$key => $data['value']], $data['reason']);
                        Notification::make()->title('Correction saved')->body('The earlier value is kept in the history. A new PDF version is being produced.')->success()->send();
                    }),
                Action::make('regenerate')->label('Produce PDF again')->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => $manage() && $report()->completed_at !== null && $report()->pdf_revision !== $report()->revision)
                    ->action(function () use ($report): void {
                        GenerateAccidentReportPdf::dispatch((int) $report()->getKey());
                        Notification::make()->title('PDF requested')->success()->send();
                    }),
                Action::make('reopen')->label('Reopen')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (): bool => $manage() && $report()->resolved_at !== null)
                    ->requiresConfirmation()
                    ->action(function () use ($service, $report): void {
                        $service()->reopen($this->actor(), $report());
                    }),
            ])->label('More')->button()->color('gray'),
        ];
    }

    /** @return array<string, string> */
    private function amendableFields(): array
    {
        $report = $this->report();
        $options = [];
        $sets = $report->report_type === AccidentReport::TYPE_VEHICLE
            ? ['driver' => AccidentReportSchema::DRIVER_FIELDS] + ($report->supervisor_completed_at ? ['supervisor' => AccidentReportSchema::VEHICLE_SUPERVISOR_FIELDS] : [])
            : ['driver' => AccidentReportSchema::SUPERVISOR_REPORT_FIELDS];
        foreach ($sets as $section => $fields) {
            foreach ($fields as $key => $kind) {
                if (in_array($kind, ['causes', 'actions'], true)) {
                    continue;
                }
                $options[$section.'.'.$key] = AccidentReportSchema::LABELS[$key];
            }
        }

        return $options;
    }

    private function actor(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
