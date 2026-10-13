<?php

namespace Tests\Unit;

use App\Filament\Employee\Pages\ApparatusServiceRequestPage;
use App\Filament\Employee\Pages\EmployeeDashboard;
use App\Filament\Employee\Pages\MyEquipmentPage;
use App\Filament\Employee\Pages\MyRequestsPage;
use App\Filament\Employee\Pages\OperationalForms;
use App\Filament\Employee\Pages\PersonnelEquipmentRequestPage;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Filament\Employee\Pages\VideoConferencing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmployeeNavigationGroupsTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, int}>
     */
    public static function destinations(): array
    {
        return [
            'Home' => [EmployeeDashboard::class, 'My work', 0],
            'Forms' => [OperationalForms::class, 'My work', 1],
            'My Requests' => [MyRequestsPage::class, 'My work', 2],
            'My Equipment' => [MyEquipmentPage::class, 'My work', 3],
            'Request Uniforms' => [RequestEquipmentPage::class, 'Request', 1],
            'Apparatus Service' => [ApparatusServiceRequestPage::class, 'Request', 2],
            'Officer PPE Request' => [PersonnelEquipmentRequestPage::class, 'Request', 3],
            'Video Conferencing' => [VideoConferencing::class, 'Request', 4],
        ];
    }

    #[DataProvider('destinations')]
    public function test_destination_sits_in_its_group_at_its_position(string $page, string $group, int $sort): void
    {
        $this->assertSame($group, $page::getNavigationGroup());
        $this->assertSame($sort, $page::getNavigationSort());
    }
}
