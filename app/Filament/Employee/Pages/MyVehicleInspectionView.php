<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Filament\Support\HasNavigationParent;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Read-only detail of one Daily Checkout the signed-in member submitted.
 * There are no edit or delete actions: submitted evidence is immutable.
 */
class MyVehicleInspectionView extends Page implements HasNavigationParent
{
    use ResolvesCanonicalEmployee;

    protected static ?string $slug = 'my-vehicle-inspections/{inspection}';

    protected static string $view = 'filament.employee.pages.my-vehicle-inspection-view';

    protected static bool $shouldRegisterNavigation = false;

    public int $inspectionId;

    public function mount(int|string $inspection): void
    {
        $this->authenticatedEmployee();
        $this->inspectionId = (int) $this->authorizedInspection((int) $inspection)->getKey();
    }

    public function getTitle(): string
    {
        return 'Vehicle Inspection';
    }

    public static function navigationParentPath(): string
    {
        return parse_url(MyVehicleInspections::getUrl(panel: 'employee'), PHP_URL_PATH) ?: '/employee/my-vehicle-inspections';
    }

    public static function navigationParentLabel(): string
    {
        return 'Back to my inspections';
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            MyVehicleInspections::getUrl(panel: 'employee') => 'My Vehicle Inspections',
            'Inspection',
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('employee.vehicle-inspections.pdf', ['inspection' => $this->inspectionId])),
            Action::make('print')
                ->label('Open printable PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(route('employee.vehicle-inspections.pdf', ['inspection' => $this->inspectionId, 'inline' => 1]))
                ->openUrlInNewTab(),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        // Ownership is re-checked on every render, not only on mount.
        $inspection = $this->authorizedInspection($this->inspectionId);

        return [
            'detail' => app(EmployeeInspectionHistoryService::class)->detail($inspection),
            'inspectionId' => $this->inspectionId,
        ];
    }

    private function authorizedInspection(int $id): ApparatusInspection
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        $inspection = ApparatusInspection::query()->findOrFail($id);
        abort_unless($user->can('viewOwn', $inspection), 403);

        return $inspection;
    }
}
