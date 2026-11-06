<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\Widgets;

use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use App\Services\Fleet\FrontlineCoverage;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * A front-line unit with no In Service truck, or a unit named on the wrong truck record: the same check the
 * daily-checkout:apply-approved-policy deploy gate makes, shown where Fleet can fix it. On a profile page, only the
 * problems about that truck. One query for the fleet.
 */
class FrontlineCoverageBanner extends Widget
{
    /** The truck on a profile page; the whole fleet on the list. */
    #[Locked]
    public ?Model $record = null;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.resources.apparatus-resource.widgets.frontline-coverage-banner';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Apparatus::class) ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $truck = $this->record instanceof Apparatus ? (int) $this->record->getKey() : null;
        $problems = collect(app(FrontlineCoverage::class)->problems())
            ->filter(static fn (array $problem): bool => $truck === null || $problem['apparatus_id'] === $truck)
            ->map(static fn (array $problem): array => [
                ...$problem,
                'url' => $problem['apparatus_id'] !== null ? ApparatusResource::getUrl('view', ['record' => $problem['apparatus_id']]) : null,
            ])
            ->values()
            ->all();

        return ['problems' => $problems, 'onProfile' => $truck !== null];
    }
}
