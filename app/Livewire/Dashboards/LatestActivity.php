<?php

declare(strict_types=1);

namespace App\Livewire\Dashboards;

use App\Filament\Admin\Pages\OperationsActivity;
use App\Models\User;
use App\Services\Activity\OperationsActivityEvent;
use App\Services\Activity\OperationsActivityFeed;
use App\Services\Activity\OperationsActivityPreferences;
use App\Services\Dashboards\DashboardAccess;
use App\Services\Dashboards\IssuePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * "Latest activity" card on the Admin Overview, under the All / Apparatus / Stations / Personnel chips.
 *
 * A separate child of DashboardBoard so the board's own query budget is untouched. The chip arrives as a reactive
 * property. It polls every 30 s while visible, but a poll is skipped while someone has focus inside the list, so a row
 * never moves under a cursor or a screen reader. Rows are links; nothing in here writes. Never rendered in wall
 * display mode: only the board includes it.
 */
final class LatestActivity extends Component
{
    /** Rows shown, and how many recent events are read so "Needs action" still has something to show. */
    public const SHOWN = 12;

    public const POOL = 40;

    #[Reactive]
    public string $filter = 'all';

    public string $kind = OperationsActivityFeed::KIND_ALL;

    /** Set by the browser while focus is inside the list; the next poll then leaves the list alone. */
    public bool $paused = false;

    public function mount(): void
    {
        abort_unless(DashboardAccess::canEnterPanel(auth()->user()), 403);
    }

    public function setKind(string $kind): void
    {
        $this->kind = OperationsActivityFeed::normalizeKind($kind);
    }

    /** The 30 s poll. Does nothing (and sends no new HTML) while someone is reading or tabbing through the list. */
    public function tick(): void
    {
        if ($this->paused) {
            $this->skipRender();
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User && DashboardAccess::canEnterPanel($user), 403);

        $pool = app(OperationsActivityFeed::class)->latest($user, $this->filter, self::POOL);
        $sort = app(OperationsActivityPreferences::class)->activitySort($user);
        $shown = array_slice(OperationsActivityFeed::arranged(OperationsActivityFeed::onlyKind($pool, $this->kind), $sort), 0, self::SHOWN);
        $now = CarbonImmutable::now();

        return view('livewire.dashboards.latest-activity', [
            'rows' => array_map(static fn (OperationsActivityEvent $event): array => [
                'event' => $event,
                'time' => IssuePresenter::time($event->at, $now),
                'timeIso' => $event->at->toIso8601String(),
            ], $shown),
            'needsAction' => count(array_filter($pool, static fn (OperationsActivityEvent $event): bool => $event->actionRequired)),
            'historyUrl' => OperationsActivity::canAccess() ? OperationsActivity::getUrl(['area' => OperationsActivityFeed::normalizeFilter($this->filter)], panel: 'admin') : null,
            'updatedAt' => $now,
        ]);
    }
}
