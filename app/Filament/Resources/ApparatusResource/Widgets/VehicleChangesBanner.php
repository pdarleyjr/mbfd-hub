<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\Widgets;

use App\Filament\Resources\ApparatusChangeoutResource;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Support\Fleet\ChangeoutAlertText;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Says a crew changed trucks, above the Apparatus list and on the profile of both trucks. One query, for the unacknowledged
 * changes. Who may see it follows admin.fleet.view; acknowledging goes through the service, which checks again.
 */
class VehicleChangesBanner extends Widget
{
    public const ACKNOWLEDGED_EVENT = 'vehicle-change-acknowledged';

    /** The truck on a profile page; the whole fleet on the list. */
    #[Locked]
    public ?Model $record = null;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.resources.apparatus-resource.widgets.vehicle-changes-banner';

    protected int|string|array $columnSpan = 'full';

    private const SHOWN = 10;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', ApparatusChangeout::class) ?? false;
    }

    public function acknowledge(mixed $changeoutId): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        if (! is_numeric($changeoutId)) {
            return;
        }
        try {
            app(ApparatusChangeoutService::class)->acknowledge((int) $changeoutId, $actor);
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('You do not have permission to do this.')->body($exception->getMessage() ?: null)->send();

            return;
        }
        Notification::make()->success()->title('Vehicle change acknowledged')->send();
        $this->dispatch(self::ACKNOWLEDGED_EVENT);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $truck = $this->record instanceof Apparatus ? (int) $this->record->getKey() : null;
        $query = ApparatusChangeout::query()->unacknowledged()
            ->when($truck !== null, static fn ($scope) => $scope->involvingTruck($truck));

        return [
            'changes' => $query->orderByDesc('id')->limit(self::SHOWN)->get()->map(static fn (ApparatusChangeout $changeout): array => [
                'id' => (int) $changeout->getKey(),
                'line' => ChangeoutAlertText::bannerLine($changeout),
                'old_truck' => ChangeoutAlertText::oldTruckSentence($changeout),
                'review' => $changeout->reviewNotes(),
                'url' => ApparatusChangeoutResource::getUrl('view', ['record' => $changeout->getKey()]),
            ])->all(),
            'canAcknowledge' => auth()->user()?->can('acknowledge', ApparatusChangeout::class) ?? false,
        ];
    }
}
