<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\UniformEntitlementService;
use App\Services\PersonnelRequests\UniformOrderCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public ?string $submittedRequestNumber = null;

    public ?string $submittedRequestId = null;

    private ?array $entitlementContext = null;

    private function entitlementContext(): array
    {
        return $this->entitlementContext ??= app(UniformEntitlementService::class)->forEmployee($this->authenticatedEmployee());
    }

    public function mount(): void
    {
        $this->resetOrder();
    }

    private function resetOrder(): void
    {
        $context = $this->entitlementContext();
        $items = [];
        foreach (app(UniformOrderCatalog::class)->products() as $code => $product) {
            $items[$code] = [
                'item_code' => $code,
                'quantity' => 0,
                'metadata' => $context['recommended_variants'][$code] ?? [],
            ];
        }
        $this->data = ['items' => $items, 'member_note' => '', 'idempotency_key' => (string) Str::uuid()];
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        $this->resetValidation($property);
        if (! preg_match('/^data\.items\.([a-z_]+)\.(quantity|metadata\.[a-z_]+)$/', $property, $matches)) {
            return;
        }
        $code = $matches[1];
        if (in_array($this->data['items'][$code]['quantity'] ?? null, [0, '0'], true)) {
            $this->resetValidation('data.items.'.$code.'.*');

            return;
        }
        try {
            app(UniformEntitlementService::class)->normalizeItems([$code => $this->data['items'][$code]]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                if ('data.'.$key === $property) {
                    $this->addError($property, $messages[0]);
                }
            }
        }
    }

    public function submit(PersonnelRequestSubmissionService $submissions): void
    {
        $this->resetValidation();
        $this->validate([
            'data.items' => ['required', 'array'],
            'data.items.*' => ['array'],
            'data.items.*.item_code' => ['required', 'string'],
            'data.items.*.metadata' => ['present', 'array'],
            'data.member_note' => ['nullable', 'string', 'max:'.config('uniform_orders.note_max')],
            'data.idempotency_key' => ['required', 'string', 'max:100'],
            'data.items.*.quantity' => ['required', 'integer', 'min:0', 'max:'.config('uniform_orders.quantity_max')],
        ], [], ['data.member_note' => 'Notes for Support Services', 'data.items.*.quantity' => 'quantity']);

        $items = array_filter($this->data['items'], fn ($item): bool => is_array($item) && (int) $item['quantity'] > 0);
        try {
            $request = $submissions->submitUniform(
                $this->authenticatedEmployee(),
                $items,
                $this->data['idempotency_key'],
                ['member_note' => $this->data['member_note'] ?? ''],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
        }

        $this->submittedRequestNumber = $request->request_number;
        $this->submittedRequestId = $request->public_id;
        $this->resetOrder();
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
        $catalog = app(UniformOrderCatalog::class);
        $entitlements = app(UniformEntitlementService::class);
        $context = $this->entitlementContext();
        $products = $catalog->products();
        $categories = $catalog->categories();
        if ($context['marine']) {
            $categories = ['marine' => 'Your Marine Allocation'] + $categories;
        }
        $rows = $this->data['items'] ?? [];
        $selectedItems = array_filter(is_array($rows) ? $rows : [], fn ($item): bool => is_array($item) && is_string($item['item_code'] ?? null) && is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] > 0);

        return [
            'member' => $employee,
            'context' => $context,
            'products' => $products,
            'categories' => $categories,
            'selectedItems' => $selectedItems,
            'summary' => $entitlements->summarize($context, $selectedItems),
            'recentRequests' => $employee->personnelRequests()->where('type', 'uniform')->withCount('items')->latest()->limit(5)->get(),
        ];
    }
}
