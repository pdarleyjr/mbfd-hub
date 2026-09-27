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
            if ($component instanceof \App\Filament\Resources\ApparatusResource\Pages\ViewInspection) {
                $parent = $page::getResource()::getUrl('view', ['record' => $component->record]);
                $label = 'Back to apparatus';
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

        return ['url' => $url, 'label' => parse_url($url, PHP_URL_PATH) === parse_url($parent, PHP_URL_PATH) ? $label : 'Back to previous page'];
    }
}
