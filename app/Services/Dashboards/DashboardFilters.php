<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\OperationalDisplayWindow;

/**
 * Filter chips per dashboard. Every chip maps onto a DashboardQuery field the sources already
 * honour (tag, severity or stage), or on the Overview onto one lane (apparatus, stations or
 * personnel), and shows the exact count the sources return for its key.
 */
final class DashboardFilters
{
    public const ALL = 'all';

    /**
     * @return list<array{key: string, label: string, countKey: string, kind: string, optional: bool, icon: string, lane?: string}>
     */
    public static function chips(DashboardView $view): array
    {
        $all = ['key' => self::ALL, 'label' => 'All', 'countKey' => 'open', 'kind' => 'all', 'optional' => false, 'icon' => 'heroicon-m-squares-2x2'];
        $high = ['key' => 'high', 'label' => 'High priority', 'countKey' => 'high', 'kind' => 'severity', 'optional' => false, 'icon' => 'heroicon-m-exclamation-circle'];
        $awaiting = ['key' => 'awaiting_response', 'label' => 'Awaiting response', 'countKey' => 'awaiting_response', 'kind' => 'stage', 'optional' => false, 'icon' => 'heroicon-m-clock'];

        return match ($view) {
            DashboardView::Overview => [
                $all,
                self::lane('apparatus', 'Apparatus', 'heroicon-m-truck', DashboardSnapshot::LANE_VEHICLE),
                self::lane('stations', 'Stations', 'heroicon-m-building-office', DashboardSnapshot::LANE_STATION),
                self::lane('personnel', 'Personnel', 'heroicon-m-users', DashboardSnapshot::LANE_MESSAGES),
            ],
            DashboardView::VehicleCheckout => [
                $all,
                self::tag('rescue', 'Rescue', 'heroicon-m-truck'),
                self::tag('engine_puc', 'PUC / Engine', 'heroicon-m-truck'),
                self::tag('marine', 'Marine', 'heroicon-m-lifebuoy'),
                self::tag('ladder', 'Ladder', 'heroicon-m-truck', true),
                self::tag('special', 'Special units', 'heroicon-m-star', true),
                $high,
                $awaiting,
            ],
            DashboardView::StationOperations => [
                $all,
                self::tag('facilities', 'Facilities', 'heroicon-m-wrench-screwdriver'),
                self::tag('supplies', 'Supplies', 'heroicon-m-cube'),
                self::tag('access_it', 'Access / IT', 'heroicon-m-key'),
                $high,
                $awaiting,
            ],
        };
    }

    public static function isValid(DashboardView $view, string $key): bool
    {
        if ($key === self::ALL) {
            return true;
        }

        foreach (self::chips($view) as $chip) {
            if ($chip['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public static function query(DashboardView $view, string $key, OperationalDisplayWindow $window): DashboardQuery
    {
        foreach (self::chips($view) as $chip) {
            if ($chip['key'] !== $key) {
                continue;
            }

            return match ($chip['kind']) {
                'tag' => new DashboardQuery($window, false, [$chip['key']]),
                'severity' => new DashboardQuery($window, false, [], null, 'high'),
                'stage' => new DashboardQuery($window, false, [], 'awaiting_response'),
                default => new DashboardQuery($window),
            };
        }

        return new DashboardQuery($window);
    }

    /** The lane an Overview filter shows alone; null for "All" and on the other dashboards. */
    public static function laneOf(DashboardView $view, string $key): ?string
    {
        foreach (self::chips($view) as $chip) {
            if ($chip['key'] === $key) {
                return $chip['lane'] ?? null;
            }
        }

        return null;
    }

    /** @return array{key: string, label: string, countKey: string, kind: string, optional: bool, icon: string, lane: string} */
    private static function lane(string $key, string $label, string $icon, string $lane): array
    {
        return ['key' => $key, 'label' => $label, 'countKey' => 'open', 'kind' => 'lane', 'optional' => false, 'icon' => $icon, 'lane' => $lane];
    }

    /** @return array{key: string, label: string, countKey: string, kind: string, optional: bool, icon: string} */
    private static function tag(string $key, string $label, string $icon, bool $optional = false): array
    {
        return ['key' => $key, 'label' => $label, 'countKey' => $key, 'kind' => 'tag', 'optional' => $optional, 'icon' => $icon];
    }
}
