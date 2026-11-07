<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\MediaControlMedia;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\StationOperationsDashboard;
use App\Filament\Pages\VehicleCheckoutDashboard;
use App\Filament\Resources\ApparatusChangeoutResource;
use App\Filament\Resources\ApparatusInspectionExceptionResource;
use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\CapitalProjectResource;
use App\Filament\Resources\ChecklistTemplateResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Filament\Resources\RoomAssetResource;
use App\Filament\Resources\StationInspectionResource;
use App\Filament\Resources\StationRequestResource;
use App\Filament\Resources\StationResource;
use App\Filament\Resources\StationSupplyRequestResource;
use App\Filament\Resources\Under25kProjectResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Tests\TestCase;

/** The admin menu: truck work under Fleet, station work under Stations, Media Control under Programs. */
final class AdminNavigationGroupsTest extends TestCase
{
    public function test_vehicle_work_sits_in_fleet(): void
    {
        foreach ([
            ApparatusResource::class,
            InspectionResource::class,
            ApparatusInspectionExceptionResource::class,
            ApparatusServiceTicketResource::class,
            ApparatusOperationalAlertResource::class,
            ApparatusChangeoutResource::class,
            InventoryCorrectionResource::class,
            ChecklistTemplateResource::class,
        ] as $item) {
            self::assertSame('Fleet', $item::getNavigationGroup(), $item);
        }
        self::assertGreaterThan(InventoryCorrectionResource::getNavigationSort(), ChecklistTemplateResource::getNavigationSort(), 'Checklist templates follow Inventory corrections.');
    }

    public function test_station_work_and_station_projects_sit_in_stations(): void
    {
        foreach ([
            StationResource::class,
            StationRequestResource::class,
            StationSupplyRequestResource::class,
            StationInspectionResource::class,
            RoomAssetResource::class,
            CapitalProjectResource::class,
            Under25kProjectResource::class,
        ] as $item) {
            self::assertSame('Stations', $item::getNavigationGroup(), $item);
        }
        self::assertGreaterThan(StationResource::getNavigationSort(), CapitalProjectResource::getNavigationSort(), 'Stations lead the group; projects follow.');
    }

    public function test_media_control_sits_in_programs_and_the_dashboards_stay_together(): void
    {
        self::assertSame('Programs', MediaControlMedia::getNavigationGroup());
        foreach ([Dashboard::class, VehicleCheckoutDashboard::class, StationOperationsDashboard::class] as $page) {
            self::assertSame('Operations', $page::getNavigationGroup(), $page);
        }
    }

    public function test_stations_group_sits_next_to_fleet_after_ems(): void
    {
        $labels = array_values(array_map(
            static fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup ? (string) $group->getLabel() : $group,
            Filament::getPanel('admin')->getNavigationGroups(),
        ));

        $fleet = array_search('Fleet', $labels, true);
        self::assertIsInt($fleet);
        // EMS keeps its place right after Fleet (EmsAdminAccessTest); Stations follows it.
        self::assertSame(['Fleet', 'EMS', 'Stations'], array_slice($labels, $fleet, 3));
    }
}
