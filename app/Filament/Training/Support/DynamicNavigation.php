<?php

namespace App\Filament\Training\Support;

use App\Models\ExternalNavItem;
use Filament\Navigation\NavigationItem;

class DynamicNavigation
{
    /**
     * Get dynamic navigation items for the Training panel.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $items = ExternalNavItem::query()
            ->active()
            ->forDivision('training')
            ->forUser($user)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return $items->map(function (ExternalNavItem $item) {
            $url = $item->open_in_new_tab
                ? $item->url
                : route('filament.training.pages.external-viewer', ['slug' => $item->slug]);

            return NavigationItem::make($item->label)
                ->group('Training Data')
                ->icon('heroicon-o-table-cells')
                ->sort($item->sort_order)
                ->url($url, shouldOpenInNewTab: $item->open_in_new_tab);
        })->toArray();
    }
}
