<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\PersonnelRequest;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\UniformAllowanceService;
use App\Services\PersonnelRequests\UniformEntitlementService;
use App\Services\PersonnelRequests\UniformOrderCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * @property-read array{can_order:bool,reason:string} $jacketEligibility
 * @property-read Collection<int, PersonnelRequest> $recentUniformRequests
 * @property-read array<string, int> $allowanceUsage
 */
class RequestEquipmentPage extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Request';

    protected static string $view = 'filament.employee.pages.request-equipment';

    protected static ?string $title = 'Request Uniforms';

    protected static ?int $navigationSort = 1;

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

    private function orderingProducts(): array
    {
        return array_diff_key(app(UniformOrderCatalog::class)->products(), array_flip([
            'uniform_shirt', 'work_boots', 'class_a_coat',
        ]));
    }

    public function mount(): void
    {
        $this->resetOrder();
    }

    private function resetOrder(): void
    {
        $context = $this->entitlementContext();
        $items = [];
        foreach ($this->orderingProducts() as $code => $product) {
            $items[$code] = [
                'item_code' => $code,
                'quantity' => 0,
                'metadata' => array_replace(
                    array_fill_keys(array_column($product['fields'], 'key'), ''),
                    $product['variant'] ?? [],
                    $context['recommended_variants'][$code] ?? [],
                ),
            ];
        }
        $this->data = ['items' => $items, 'member_note' => '', 'allowance_justification' => '', 'idempotency_key' => (string) Str::uuid()];
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
            'data.allowance_justification' => ['nullable', 'string', 'max:'.config('uniform_orders.allowance_justification_max')],
            'data.idempotency_key' => ['required', 'string', 'max:100'],
            'data.items.*.quantity' => ['required', 'integer', 'min:0', 'max:'.config('uniform_orders.quantity_max')],
        ], [], ['data.member_note' => 'Notes for Support Services', 'data.allowance_justification' => 'reason', 'data.items.*.quantity' => 'quantity']);

        $items = array_filter($this->data['items'], fn ($item): bool => is_array($item) && (int) $item['quantity'] > 0);
        // Only items this page offers may be ordered here; the others go through Support Services.
        $unavailable = array_diff_key($items, $this->orderingProducts());
        if ($unavailable !== []) {
            throw ValidationException::withMessages([
                'data.items' => 'Some selected items cannot be ordered here. Remove them or contact Support Services.',
            ]);
        }
        try {
            $request = $submissions->submitUniform(
                $this->authenticatedEmployee(),
                $items,
                $this->data['idempotency_key'],
                [
                    'member_note' => $this->data['member_note'] ?? '',
                    'allowance_justification' => $this->data['allowance_justification'] ?? null,
                ],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
        }

        $this->submittedRequestNumber = $request->request_number;
        $this->submittedRequestId = $request->public_id;
        unset($this->jacketEligibility, $this->recentUniformRequests, $this->allowanceUsage);
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
        $products = $this->orderingProducts();
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
            'allowance' => app(UniformAllowanceService::class)->assess($context, $this->allowanceUsage, $selectedItems),
            'jacketEligibility' => $this->jacketEligibility,
            'recentRequests' => $this->recentUniformRequests,
        ];
    }

    /**
     * Quantity steppers and size fields re-render the page on every change. These
     * lookups do not depend on the selection, so they are read once per visit and
     * refreshed after a submission. The jacket limit is still enforced at submit.
     *
     * @return array{can_order:bool,reason:string}
     */
    #[Computed(persist: true, seconds: 300)]
    public function jacketEligibility(): array
    {
        return app(UniformEntitlementService::class)->jacketEligibility($this->authenticatedEmployee());
    }

    /**
     * Year-to-date usage for the remaining-allowance display. Submission recomputes
     * it on the server under a lock, so this cached copy is display-only.
     *
     * @return array<string, int>
     */
    #[Computed(persist: true, seconds: 300)]
    public function allowanceUsage(): array
    {
        return app(UniformAllowanceService::class)->usedByGroup($this->authenticatedEmployee());
    }

    /** @return Collection<int, PersonnelRequest> */
    #[Computed(persist: true, seconds: 300)]
    public function recentUniformRequests(): Collection
    {
        return $this->authenticatedEmployee()->personnelRequests()->where('type', 'uniform')->withCount('items')->latest()->limit(5)->get();
    }
}
