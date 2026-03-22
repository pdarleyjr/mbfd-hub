<?php

namespace App\Filament\Employee\Pages;

use App\Models\Employee;
use App\Models\EmployeeEquipmentRequest;
use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RequestEquipmentPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static string $view = 'filament.employee.pages.request-equipment';
    protected static ?string $title = 'Request Equipment';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Request Equipment';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('requested_items')
                    ->label('Describe the items you are requesting')
                    ->placeholder("Example:\n- 2x T-Shirts (Size Large)\n- 1x Bunker Coat (replacement)\n- 1x Helmet liner")
                    ->required()
                    ->rows(6)
                    ->helperText('Be specific — include item type, size, quantity, and reason if applicable.'),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        /** @var Employee $employee */
        $employee = auth('employee')->user();

        EmployeeEquipmentRequest::create([
            'employee_portal_id' => $employee->id,
            'user_id'            => null, // not linked to users table
            'requested_items'    => $data['requested_items'],
            'status'             => 'Pending',
        ]);

        // Notify admin users via their Filament notifications (users table)
        $admins = User::role(['super_admin', 'admin', 'logistics_admin'])->get();
        foreach ($admins as $admin) {
            Notification::make()
                ->title('New Employee Equipment Request')
                ->body("{$employee->name} (ID: {$employee->employee_id}) submitted an equipment request.")
                ->icon('heroicon-o-shopping-cart')
                ->iconColor('warning')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Review Request')
                        ->url(route('filament.admin.resources.employee-equipment-requests.index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($admin);
        }

        $this->form->fill();

        Notification::make()
            ->title('Request submitted!')
            ->body('Your equipment request has been received.')
            ->success()
            ->send();
    }

    public function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();

        $active = EmployeeEquipmentRequest::where('employee_portal_id', $employee->id)
            ->where('is_archived', false)
            ->latest()
            ->get();

        $archived = EmployeeEquipmentRequest::where('employee_portal_id', $employee->id)
            ->where('is_archived', true)
            ->latest()
            ->take(20)
            ->get();

        // Combine for blade — pass both
        $history = $active;
        $user = $employee;
        return compact('history', 'archived', 'user');
    }
}
