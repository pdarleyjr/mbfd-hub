<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Filament\Support\HasNavigationParent;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionProjection;
use App\Services\Inventory\InventoryCorrectionService;
use App\Support\Fleet\InventoryCorrectionAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * One inventory correction the signed-in member reported: what they proposed, Logistics'
 * questions and decision, what was applied, and a reply box. Internal notes never appear.
 */
class MyInventoryCorrectionView extends Page implements HasNavigationParent
{
    use ResolvesCanonicalEmployee;

    protected static ?string $slug = 'my-inventory-corrections/{correction}';

    protected static string $view = 'filament.employee.pages.my-inventory-correction-view';

    protected static bool $shouldRegisterNavigation = false;

    public int $correctionId;

    public string $reply = '';

    /** Server-issued idempotency key for the reply being sent; the browser cannot change it. */
    #[Locked]
    public ?string $replyKey = null;

    public function mount(int|string $correction): void
    {
        $this->authenticatedEmployee();
        $this->correctionId = (int) $this->authorizedCorrection((int) $correction)->getKey();
    }

    public function sendReply(): void
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);
        $this->authorizedCorrection($this->correctionId);
        $this->validate(['reply' => ['required', 'string', 'max:'.InventoryCorrectionService::MAX_MESSAGE]], [], ['reply' => 'message']);
        if (! RateLimiter::attempt('inventory-correction-message:'.$user->getKey(), 20, static fn (): bool => true, 60)) {
            throw ValidationException::withMessages(['reply' => 'You are sending messages too quickly. Wait a minute and try again.']);
        }

        // One key per message: a double tap or a retry stores the reply once.
        $this->replyKey ??= (string) Str::uuid();
        try {
            app(InventoryCorrectionService::class)->postMessage($this->correctionId, $user, $this->reply, ApparatusInventoryCorrectionEvent::VISIBILITY_MEMBER, $this->replyKey);
        } catch (AuthorizationException) {
            abort(403);
        }
        $this->reply = '';
        $this->replyKey = null;
        Notification::make()->success()->title('Reply sent to Logistics')->send();
    }

    public function getTitle(): string
    {
        return 'Inventory correction';
    }

    public static function navigationParentPath(): string
    {
        return (parse_url(MyVehicleInspections::getUrl(panel: 'employee'), PHP_URL_PATH) ?: '/employee/my-vehicle-inspections').'#inventory-corrections';
    }

    public static function navigationParentLabel(): string
    {
        return 'Back to my inspections';
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            MyVehicleInspections::getUrl(panel: 'employee') => 'My Vehicle Inspections',
            'Inventory correction',
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        // Ownership is re-checked on every render.
        $correction = $this->authorizedCorrection($this->correctionId);

        return ['detail' => app(InventoryCorrectionProjection::class)->memberDetail($correction)];
    }

    private function authorizedCorrection(int $id): ApparatusInventoryCorrection
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);
        // Someone else's correction is indistinguishable from one that does not exist.
        $correction = ApparatusInventoryCorrection::query()->find($id);
        abort_unless($correction instanceof ApparatusInventoryCorrection && InventoryCorrectionAccess::owns($user, $correction), 404);

        return $correction;
    }
}
