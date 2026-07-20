<?php

namespace App\Filament\Employee\Pages;

use App\Models\Employee;
use App\Services\OperationalForms\FrocImportLimits;
use Filament\Pages\Page;

class OperationalForms extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string $view = 'filament.employee.pages.operational-forms';

    protected static ?string $title = 'Operational Forms';

    protected static ?string $navigationLabel = 'Forms';

    protected static ?string $slug = 'forms';

    protected static ?int $navigationSort = 1;

    public function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();

        return [
            'operationalFormsBootstrap' => [
                'employee' => [
                    'id' => $employee->getKey(),
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'rank' => $employee->rank,
                ],
                'endpoints' => [
                    'form_types' => route('employee.forms.api.form-types'),
                    'records' => route('employee.forms.api.records.index'),
                    'uploads' => route('employee.forms.api.uploads.store'),
                    'guide' => url('/documents/MBFD_Operational_Forms_User_Guide.pdf'),
                ],
                'csrf_token' => csrf_token(),
                'build' => config('app.version', 'development'),
                ...FrocImportLimits::bootstrap(),
            ],
        ];
    }
}
