<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\PersonnelRequest;
use Filament\Pages\Page;

class MyRequestsPage extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'My work';

    protected static string $view = 'filament.employee.pages.my-requests';

    protected static ?string $title = 'My Requests';

    protected static ?string $navigationLabel = 'My Requests';

    protected static ?string $slug = 'my-requests';

    protected static ?int $navigationSort = 2;

    public function getViewData(): array
    {
        $employee = $this->authenticatedEmployee();

        return [
            // Requests for this member and requests this member (an officer) submitted for someone else: both are theirs to follow.
            'requests' => PersonnelRequest::query()
                ->where(fn ($mine) => $mine->where('beneficiary_employee_id', $employee->getKey())->orWhere('requester_employee_id', $employee->getKey()))
                ->with(['items', 'originatingStation'])
                ->latest()
                ->paginate(20),
            'legacyRequests' => $employee->equipmentRequests()->latest()->limit(20)->get(),
        ];
    }
}
