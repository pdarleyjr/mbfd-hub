<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Activity\OperationsActivityEvent;
use App\Services\Activity\OperationsActivityFeed;
use App\Services\Activity\OperationsActivityPreferences;
use App\Services\Dashboards\DashboardAccess;
use App\Services\Dashboards\IssuePresenter;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Activity history: the Admin Overview's Latest activity card, but 30 days deep, searchable and paged (50 a page).
 * Same sources, same per-domain visibility and the same links as the card; read-only, nothing here changes a record.
 * Reached from "View all activity" on the Overview.
 */
class OperationsActivity extends Page
{
    use WithPagination;

    public const PER_PAGE = 50;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Activity history';

    protected static ?string $title = 'Activity history';

    protected static ?string $slug = 'activity-history';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.admin.pages.operations-activity';

    protected ?string $maxContentWidth = MaxWidth::SevenExtraLarge->value;

    #[Url(as: 'area')]
    public string $area = 'all';

    #[Url(as: 'show')]
    public string $kind = OperationsActivityFeed::KIND_ALL;

    #[Url(as: 'q')]
    public string $search = '';

    public string $sort = OperationsActivityPreferences::ACTIVITY_LATEST;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && DashboardAccess::canEnterPanel($user)
            && app(OperationsActivityFeed::class)->canSeeAny($user);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->area = OperationsActivityFeed::normalizeFilter($this->area);
        $this->kind = OperationsActivityFeed::normalizeKind($this->kind);
        $this->sort = app(OperationsActivityPreferences::class)->activitySort(auth()->user());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArea(): void
    {
        $this->area = OperationsActivityFeed::normalizeFilter($this->area);
        $this->resetPage();
    }

    public function updatedKind(): void
    {
        $this->kind = OperationsActivityFeed::normalizeKind($this->kind);
        $this->resetPage();
    }

    /** The order select; saved like the Overview's, and the default removes the stored row. */
    public function updatedSort(): void
    {
        $this->sort = OperationsActivityPreferences::normalizeActivity($this->sort);
        app(OperationsActivityPreferences::class)->saveActivitySort(auth()->user(), $this->sort);
        $this->resetPage();
    }

    /** One page of events plus the display text each row needs. */
    public function paginator(): LengthAwarePaginator
    {
        abort_unless(self::canAccess(), 403);
        $user = auth()->user();
        $events = OperationsActivityFeed::arranged(
            app(OperationsActivityFeed::class)->history($user, $this->area, $this->search, $this->kind),
            $this->sort,
        );
        $total = count($events);
        $page = min(max(1, $this->getPage()), max(1, (int) ceil($total / self::PER_PAGE)));
        $now = CarbonImmutable::now();

        $rows = array_map(static fn (OperationsActivityEvent $event): array => [
            'event' => $event,
            'time' => IssuePresenter::time($event->at, $now),
            'timeIso' => $event->at->toIso8601String(),
        ], array_slice($events, ($page - 1) * self::PER_PAGE, self::PER_PAGE));

        return new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page, ['path' => url()->current()]);
    }
}
