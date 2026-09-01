<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Models\Apparatus;
use App\Models\Station;
use App\Services\ApparatusServiceTicketWorkflowService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** @property Form $form */
class ApparatusServiceRequestPage extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.employee.pages.apparatus-service-request';

    protected static ?string $title = 'Apparatus Service Request';

    protected static ?string $navigationLabel = 'Apparatus Service';

    protected static ?string $slug = 'apparatus-service-request';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public bool $stationLocked = false;

    public function mount(): void
    {
        $stationId = filter_var(request()->query('station_id'), FILTER_VALIDATE_INT) ?: null;
        if ($stationId && Station::query()->whereKey($stationId)->where('is_active', true)->exists()) {
            $this->stationLocked = true;
        } else {
            $stationId = null;
        }

        $apparatusId = filter_var(request()->query('apparatus_id'), FILTER_VALIDATE_INT) ?: null;
        if ($apparatusId !== null) {
            $apparatus = Apparatus::query()->find($apparatusId);
            if ($apparatus === null || ($stationId !== null && (int) $apparatus->station_id !== (int) $stationId)) {
                $apparatusId = null;
            } else {
                $stationId ??= $apparatus->station_id;
            }
        }

        $this->form->fill([
            'station_id' => $stationId,
            'apparatus_id' => $apparatusId,
            'category' => 'repair_mechanical',
            'priority' => 'routine',
            'client_submission_id' => (string) Str::uuid(),
        ]);
    }

    public function form(Form $form): Form
    {
        $employee = $this->authenticatedEmployee();

        return $form->schema([
            Placeholder::make('employee_identity')
                ->label('Requesting employee')
                ->content("{$employee->rank} — {$employee->name} — {$employee->employee_id}"),
            Select::make('station_id')
                ->label('Station')
                ->options(fn (): array => Station::query()
                    ->where('is_active', true)
                    ->orderBy('station_number')
                    ->pluck('station_number', 'id')
                    ->map(fn ($number): string => "Station {$number}")
                    ->all())
                ->disabled(fn (): bool => $this->stationLocked)
                ->dehydrated()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('apparatus_id', null))
                ->required(),
            Select::make('apparatus_id')
                ->label('Apparatus')
                ->options(fn (Get $get): array => Apparatus::query()
                    ->when($get('station_id'), fn ($query, $stationId) => $query->where('station_id', $stationId))
                    ->whereHas('station', fn ($query) => $query->where('is_active', true))
                    ->orderBy('designation')
                    ->get()
                    ->mapWithKeys(fn (Apparatus $apparatus): array => [
                        $apparatus->id => trim(($apparatus->designation ?: $apparatus->name).' · '.($apparatus->vehicle_number ?: 'No vehicle number')),
                    ])->all())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('category')
                ->options(ApparatusServiceTicketCategory::options())
                ->required(),
            Select::make('priority')
                ->options(ApparatusServiceTicketPriority::options())
                ->helperText('Urgent means the issue needs immediate Fleet review. It does not automatically change the unit operational status.')
                ->required(),
            TextInput::make('title')
                ->label('Short issue summary')
                ->placeholder('Example: Air leak at rear brake chamber')
                ->minLength(5)
                ->maxLength(255)
                ->required()
                ->columnSpanFull(),
            Textarea::make('description')
                ->label('What did you observe?')
                ->placeholder('Describe the symptom, when it occurs, and any immediate operational impact. Do not include private medical or personnel information.')
                ->rows(6)
                ->minLength(10)
                ->maxLength(10000)
                ->required()
                ->columnSpanFull(),
            Placeholder::make('operational_status_notice')
                ->hiddenLabel()
                ->content(new HtmlString('<div class="ast-employee-notice"><strong>This request does not change apparatus status.</strong><p>If the unit must be taken out of service, contact the appropriate officer and Fleet immediately through established operations.</p></div>'))
                ->columnSpanFull(),
            Hidden::make('client_submission_id')->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('data');
    }

    public function submit(ApparatusServiceTicketWorkflowService $workflow): void
    {
        $data = $this->form->getState();
        $employee = $this->authenticatedEmployee();
        $apparatus = Apparatus::query()
            ->whereKey($data['apparatus_id'])
            ->where('station_id', $data['station_id'])
            ->whereHas('station', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();

        $result = $workflow->submitFromEmployee($employee, $apparatus, $data);

        Notification::make()
            ->title($result->created ? 'Apparatus service request submitted' : 'Request already received')
            ->body("{$result->ticket->ticket_number} is available to Fleet and station personnel.")
            ->success()
            ->send();

        $this->redirect(EmployeeDashboard::getUrl(panel: 'employee'));
    }
}
