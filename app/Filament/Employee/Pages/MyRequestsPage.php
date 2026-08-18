<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Models\Employee;
use Filament\Pages\Page;

class MyRequestsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string $view = 'filament.employee.pages.my-requests';

    protected static ?string $title = 'My Requests';

    protected static ?string $navigationLabel = 'My Requests';

    protected static ?string $slug = 'my-requests';

    protected static ?int $navigationSort = 2;

    public function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();

        return [
            'requests' => $employee->personnelRequests()->with(['items', 'originatingStation'])->latest()->paginate(20),
            'legacyRequests' => $employee->equipmentRequests()->latest()->limit(20)->get(),
        ];
    }
}
