<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersOperationsDashboard;
use App\Services\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardView;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\View\View;

/**
 * Dashboard 4 of 4: what members have asked for and are waiting on. Uniform orders, PPE and
 * equipment requests, medical supply requests and narcotics checks, one row per member.
 */
class MemberRequestsDashboard extends Page
{
    use RendersOperationsDashboard;

    protected static string $view = 'filament.dashboards.pages.board';

    protected static ?string $slug = 'dashboards/member-requests';

    protected static ?string $navigationLabel = 'Member Requests';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $title = 'Member Requests Dashboard';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    public static function dashboardView(): DashboardView
    {
        return DashboardView::MemberRequests;
    }

    public static function canAccess(): bool
    {
        return DashboardAccess::canViewMemberRequests(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'Uniform orders, PPE and equipment requests, medical supply requests and narcotics checks, one row per member.';
    }

    public function getHeader(): ?View
    {
        return $this->dashboardHeader();
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return $this->dashboardBodyAttributes();
    }
}
