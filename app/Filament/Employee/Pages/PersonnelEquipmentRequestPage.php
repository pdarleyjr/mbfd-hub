<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Models\Employee;
use App\Models\Station;
use App\Services\PersonnelRequests\OfficerAuthorizationService;
use App\Services\PersonnelRequests\PersonnelCatalog;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** @property Form $form */
class PersonnelEquipmentRequestPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string $view = 'filament.employee.pages.personnel-equipment-request';

    protected static ?string $title = 'Personnel Equipment Request';

    protected static ?string $navigationLabel = 'Officer PPE Request';

    protected static ?string $slug = 'personnel-equipment-request';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public bool $stationLocked = false;

    public static function shouldRegisterNavigation(): bool
    {
        $employee = auth('employee')->user();

        return $employee instanceof Employee && app(OfficerAuthorizationService::class)->isAuthorized($employee);
    }

    public function mount(): void
    {
        /** @var Employee $officer */
        $officer = auth('employee')->user();
        abort_unless(app(OfficerAuthorizationService::class)->isAuthorized($officer), 403);

        $stationId = filter_var(request()->query('station_id'), FILTER_VALIDATE_INT) ?: null;
        if ($stationId && Station::query()->whereKey($stationId)->where('is_active', true)->exists()) {
            $this->stationLocked = true;
        } else {
            $stationId = null;
        }

        $this->form->fill([
            'originating_station_id' => $stationId,
            'items' => [['item_code' => null, 'reason' => null, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'signature' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        /** @var Employee $officer */
        $officer = auth('employee')->user();
        $catalog = app(PersonnelCatalog::class);

        return $form->schema([
            Wizard::make([
                Step::make('Officer & member')
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        Placeholder::make('officer_identity')
                            ->label('Authenticated officer')
                            ->content("{$officer->rank} — {$officer->name} — {$officer->employee_id}"),
                        Select::make('originating_station_id')
                            ->label('Originating station')
                            ->options(fn (): array => Station::query()->where('is_active', true)->orderBy('station_number')->get()->mapWithKeys(fn (Station $station) => [$station->id => "Station {$station->station_number}"])->all())
                            ->disabled(fn (): bool => $this->stationLocked)
                            ->dehydrated()
                            ->required(),
                        Select::make('beneficiary_employee_id')
                            ->label('Member receiving the equipment')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Employee::query()
                                ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('employee_id', 'like', "%{$search}%")->orWhere('rank', 'like', "%{$search}%"))
                                ->orderBy('name')->limit(30)->get()
                                ->mapWithKeys(fn (Employee $employee) => [$employee->id => "{$employee->rank} — {$employee->name} — {$employee->employee_id}"])->all())
                            ->getOptionLabelUsing(function ($value): ?string {
                                $employee = Employee::query()->find($value);

                                return $employee ? "{$employee->rank} — {$employee->name} — {$employee->employee_id}" : null;
                            })
                            ->helperText('Search by name, rank, or employee ID. The full roster is never preloaded.')
                            ->required(),
                    ])->columns(2),
                Step::make('Equipment items')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Repeater::make('items')
                            ->label('Personally issued firefighting equipment')
                            ->minItems(1)
                            ->maxItems(12)
                            ->addActionLabel('Add another item')
                            ->reorderable(false)
                            ->schema([
                                Select::make('item_code')->label('Equipment')->options($catalog->equipment())->required()->live(),
                                Select::make('reason')->options($catalog->equipmentReasons())->required(),
                                TextInput::make('quantity')->numeric()->minValue(1)->maxValue(10)->default(1)->required(),
                                TextInput::make('other_description')
                                    ->label('Describe other equipment')
                                    ->visible(fn (\Filament\Forms\Get $get): bool => $get('item_code') === 'other')
                                    ->required(fn (\Filament\Forms\Get $get): bool => $get('item_code') === 'other')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ])->columns(['sm' => 3]),
                        Placeholder::make('police_report_notice')
                            ->hiddenLabel()
                            ->content(new HtmlString('<div class="ppe-notice"><strong>Police report guidance</strong><p>A police report may be required depending on the circumstances of a lost or stolen item. If a police report is obtained or requested, forward or provide it to Support Services. A case number is not automatically required when you submit.</p></div>')),
                    ]),
                Step::make('Review & sign')
                    ->icon('heroicon-o-pencil-square')
                    ->schema([
                        Placeholder::make('review')
                            ->label('Certification')
                            ->content(fn (): HtmlString => $this->reviewSummary()),
                        ViewField::make('signature')
                            ->label('Officer signature')
                            ->view('filament.forms.components.signature-pad')
                            ->required(),
                        Hidden::make('idempotency_key')->required(),
                    ]),
            ])->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(PersonnelRequestSubmissionService $submissions): void
    {
        $data = $this->form->getState();
        /** @var Employee $officer */
        $officer = auth('employee')->user();
        $beneficiary = Employee::query()->findOrFail($data['beneficiary_employee_id']);
        $station = Station::query()->where('is_active', true)->findOrFail($data['originating_station_id']);
        $request = $submissions->submitEquipment($officer, $beneficiary, $station, $data['items'], $data['signature'], $data['idempotency_key']);

        Notification::make()
            ->title('Personnel equipment request submitted')
            ->body("{$request->request_number} is assigned to {$request->beneficiary_name} and is ready for Support Services review.")
            ->success()
            ->send();

        $this->redirect(EmployeeDashboard::getUrl(panel: 'employee'));
    }

    private function reviewSummary(): HtmlString
    {
        /** @var Employee $officer */
        $officer = auth('employee')->user();
        $beneficiary = Employee::query()->find($this->data['beneficiary_employee_id'] ?? null);
        $station = Station::query()->find($this->data['originating_station_id'] ?? null);
        $catalog = app(PersonnelCatalog::class);
        $reasons = $catalog->equipmentReasons();
        $items = collect($this->data['items'] ?? [])->map(function (array $item) use ($catalog, $reasons): string {
            $code = (string) ($item['item_code'] ?? '');
            $name = $code === 'other'
                ? trim((string) ($item['other_description'] ?? ''))
                : $catalog->equipmentLabel($code);
            $reason = $reasons[(string) ($item['reason'] ?? '')] ?? 'Reason not selected';
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            return '<li><strong>'.e($name ?: 'Equipment not selected').'</strong><span>Quantity '.e((string) $quantity).' · '.e($reason).'</span></li>';
        })->implode('');

        return new HtmlString(
            '<div class="ppe-review">'
            .'<div><span>Member</span><strong>'.e($beneficiary ? "{$beneficiary->rank} — {$beneficiary->name} — {$beneficiary->employee_id}" : 'Not selected').'</strong></div>'
            .'<div><span>Station</span><strong>'.e($station ? "Station {$station->station_number}" : 'Not selected').'</strong></div>'
            .'<div><span>Requesting officer</span><strong>'.e("{$officer->rank} — {$officer->name} — {$officer->employee_id}").'</strong></div>'
            .'<div class="ppe-review-items"><span>Replacement items</span><ul>'.($items ?: '<li><strong>No items selected</strong></li>').'</ul></div>'
            .'<p>Confirm these details, then sign below. Your authenticated employee record and signed time will be permanently recorded.</p>'
            .'</div>'
        );
    }
}
