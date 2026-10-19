<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\HubNavigation;
use Filament\Pages\Dashboard;
use Livewire\Livewire;

/**
 * Resolves the shared page navigation group (Back + Admin Home + Hub Home) for
 * every Filament panel page. Called from the AppServiceProvider PAGE_START
 * hook and rendered by resources/views/filament/partials/page-nav.blade.php.
 *
 * Back order is HubNavigation::backUrl(): validated return_to, the real
 * previous same-app page, then the logical parent chosen here. Back never
 * resolves to the current path.
 */
final class PageNav
{
    /** Panels that must not get the shared group (standalone reader UI). */
    private const EXCLUDED_PANELS = ['policy-library'];

    /**
     * @param  array<int, mixed>  $scopes
     * @return array{back: array{url: string, label: string}, adminHome: ?string, hubHome: string, panel: string}|null
     */
    public static function resolve(array $scopes): ?array
    {
        $panel = filament()->getCurrentPanel();
        $panelId = $panel?->getId();
        if ($panelId === null || in_array($panelId, self::EXCLUDED_PANELS, true) || ! auth('web')->check()) {
            return null;
        }

        return [
            'back' => $panelId === 'employee' ? self::employeeBack() : self::panelBack($scopes, $panel->getUrl()),
            'adminHome' => $panelId === 'admin' ? '/admin' : null,
            'hubHome' => '/',
            'panel' => $panelId,
        ];
    }

    /** @return array{url: string, label: string} */
    private static function employeeBack(): array
    {
        $parent = Livewire::current() instanceof \App\Filament\Employee\Pages\EmployeeDashboard ? '/' : '/employee/dashboard';
        $url = HubNavigation::backUrl($parent);
        $path = parse_url($url, PHP_URL_PATH);
        $label = match (true) {
            (bool) preg_match('#^/daily/stations/[0-9]+$#', (string) $path) => 'Back to Station',
            $path === $parent && $parent !== '/' => 'Back to Employee dashboard',
            default => 'Back',
        };

        return ['url' => $url, 'label' => $label];
    }

    /**
     * @param  array<int, mixed>  $scopes
     * @return array{url: string, label: string}
     */
    private static function panelBack(array $scopes, ?string $panelRoot): array
    {
        $contextual = ContextualBack::destination($scopes);
        if ($contextual !== null) {
            return $contextual;
        }

        $page = $scopes[0] ?? null;
        $isDashboard = is_string($page) && is_a($page, Dashboard::class, true);
        // Dashboards hang off the Hub; every other page hangs off its panel root.
        $parent = $isDashboard ? '/' : (HubNavigation::safePath(self::path($panelRoot)) ?? '/');

        return ['url' => HubNavigation::backUrl($parent), 'label' => 'Back'];
    }

    private static function path(?string $url): ?string
    {
        return $url === null ? null : (parse_url($url, PHP_URL_PATH) ?: '/');
    }
}
