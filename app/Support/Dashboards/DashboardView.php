<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

/**
 * The four linked admin dashboards, in pager order. Routes (Filament admin panel):
 *   Overview          -> App\Filament\Pages\Dashboard                  /admin                                (1/4)
 *   VehicleCheckout   -> App\Filament\Pages\VehicleCheckoutDashboard   /admin/dashboards/vehicle-checkout    (2/4)
 *   StationOperations -> App\Filament\Pages\StationOperationsDashboard /admin/dashboards/station-operations  (3/4)
 *   MemberRequests    -> App\Filament\Pages\MemberRequestsDashboard    /admin/dashboards/member-requests     (4/4)
 */
enum DashboardView: string
{
    case Overview = 'overview';
    case VehicleCheckout = 'vehicle-checkout';
    case StationOperations = 'station-operations';
    case MemberRequests = 'member-requests';

    public function position(): int
    {
        return match ($this) {
            self::Overview => 1,
            self::VehicleCheckout => 2,
            self::StationOperations => 3,
            self::MemberRequests => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Admin Overview',
            self::VehicleCheckout => 'Vehicle Checkout',
            self::StationOperations => 'Station Operations',
            self::MemberRequests => 'Member Requests',
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::Overview => null,
            self::VehicleCheckout => self::Overview,
            self::StationOperations => self::VehicleCheckout,
            self::MemberRequests => self::StationOperations,
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Overview => self::VehicleCheckout,
            self::VehicleCheckout => self::StationOperations,
            self::StationOperations => self::MemberRequests,
            self::MemberRequests => null,
        };
    }
}
