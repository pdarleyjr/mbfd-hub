<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Services\PersonnelRequests\PersonnelCatalog;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class RequestEquipmentPage extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string $view = 'filament.employee.pages.request-equipment';

    protected static ?string $title = 'Request Uniforms';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Request Uniforms';

    protected static ?string $slug = 'request-equipment';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'items' => [['item_code' => null, 'size' => null, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Repeater::make('items')
                ->label('Uniform items')
                ->minItems(1)
                ->maxItems(10)
                ->addActionLabel('Add another uniform item')
                ->reorderable(false)
                ->schema([
                    Select::make('item_code')
                        ->label('Uniform item')
                        ->options(fn (): array => collect(app(PersonnelCatalog::class)->uniforms())->mapWithKeys(fn (array $item, string $code) => [$code => $item['label']])->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('size')
                        ->label('Size')
                        ->placeholder('Examples: L, 34x32, 10.5')
                        ->maxLength(30)
                        ->required(),
                    TextInput::make('quantity')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(1)
                        ->required(),
                ])
                ->columns(['sm' => 3]),
            Hidden::make('idempotency_key')->required(),
        ])->statePath('data');
    }

    public function submit(PersonnelRequestSubmissionService $submissions): void
    {
        $employee = $this->authenticatedEmployee();
        $data = $this->form->getState();
        $request = $submissions->submitUniform($employee, $data['items'], $data['idempotency_key']);

        $this->form->fill([
            'items' => [['item_code' => null, 'size' => null, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        Notification::make()
            ->title('Uniform request submitted')
            ->body("{$request->request_number} is now visible in My Requests.")
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('view')->url('/employee/my-requests/'.$request->public_id),
            ])
            ->send();
    }

    public function getViewData(): array
    {
        $employee = $this->authenticatedEmployee();

        return ['recentRequests' => $employee->personnelRequests()->where('type', 'uniform')->latest()->limit(5)->get()];
    }
}
