<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\HubNavigation;
use Filament\Pages\Dashboard;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Livewire\Livewire;

final class ContextualBack
{
    /** @return array{url: string, label: string}|null */
    public static function destination(array $scopes): ?array
    {
        $page = $scopes[0] ?? null;
        if (! is_string($page) || is_a($page, Dashboard::class, true)
            || is_a($page, ListRecords::class, true)
            || str_contains($page, 'InboundEmail') || str_contains($page, 'OutboundEmail')
            || in_array($page, [\App\Filament\Pages\ComposeEmail::class, \App\Filament\Pages\CommunicationsUsage::class], true)
            || filament()->getCurrentPanel()?->getId() === 'employee') {
            return null;
        }

        if (is_a($page, ResourcePage::class, true)) {
            $parent = $page::getResource()::getUrl('index');
            $label = 'Back to '.$page::getResource()::getPluralModelLabel();
            $component = Livewire::current();
            // The canonical inspection page is reached from the apparatus profile, a station, the
            // dashboard or the Daily Checkouts list; HubNavigation returns to whichever one the user came from.
            if ($component instanceof \App\Filament\Resources\InspectionResource\Pages\ViewInspection) {
                $label = 'Back to Daily Checkouts';
            }
        } else {
            $parentPage = match ($page) {
                \App\Filament\Workgroup\Pages\SurveyFormPage::class,
                \App\Filament\Workgroup\Pages\SurveyResultsPage::class => \App\Filament\Workgroup\Pages\Surveys::class,
                \App\Filament\Workgroup\Pages\EvaluationFormPage::class => \App\Filament\Workgroup\Pages\Evaluations::class,
                default => null,
            };
            if ($parentPage === null) {
                return null;
            }
            $parent = $parentPage::getUrl();
            $label = 'Back to '.strtolower($parentPage::getNavigationLabel());
        }

        $url = HubNavigation::backUrl($parent);
        $path = parse_url($url, PHP_URL_PATH);

        // A detail opened from the admin dashboard carries return_to=/admin: say so, rather than the generic label.
        return ['url' => $url, 'label' => match (true) {
            $path === parse_url($parent, PHP_URL_PATH) => $label,
            $path === '/admin' => 'Back to Admin',
            default => 'Back to previous page',
        }];
    }
}
