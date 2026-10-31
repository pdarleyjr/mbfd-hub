<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersOperationsDashboard;
use App\Livewire\Dashboards\IssueGroupBoard;
use App\Services\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardView;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Everything open on one apparatus, station or member, reached from a combined dashboard row: act on
 * all of it at once, or open one issue. Not in the menu; it is always about something specific.
 */
class IssueGroupPage extends Page
{
    use RendersOperationsDashboard;

    protected static string $view = 'filament.dashboards.pages.issue-group';

    protected static ?string $slug = 'dashboards/item';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Open issues';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    #[Locked]
    public string $group = '';

    public static function dashboardView(): DashboardView
    {
        return DashboardView::Overview;
    }

    public static function canAccess(): bool
    {
        return DashboardAccess::canEnterPanel(auth()->user());
    }

    public function mount(): void
    {
        $group = request()->query('group');
        abort_unless(is_string($group) && IssueGroupBoard::isValidKey($group), 404);
        $this->group = $group;
    }

    /** The board has its own heading naming the unit. */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return $this->dashboardBodyAttributes();
    }
}
