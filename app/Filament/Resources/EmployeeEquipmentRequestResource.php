<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeEquipmentRequestResource\Pages;
use App\Models\Employee;
use App\Models\EmployeeEquipmentRequest;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

use App\Filament\Concerns\EnterpriseTable;
class EmployeeEquipmentRequestResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = EmployeeEquipmentRequest::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Inventory & Logistics';
    protected static ?string $navigationLabel = 'Employee Gear Requests';
    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('user_id')
                    ->label('Employee')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Textarea::make('requested_items')
                    ->label('Requested Items')
                    ->required()
                    ->rows(4),
                Select::make('status')
                    ->options(EmployeeEquipmentRequest::STATUSES)
                    ->default('Pending')
                    ->required(),
                Textarea::make('admin_notes')
                    ->label('Admin Notes')
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee.employee_id')
                    ->label('Employee ID')
                    ->searchable(),
                TextColumn::make('requested_items')
                    ->label('Request')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->requested_items),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Completed'        => 'success',
                        'Ready for Pickup' => 'info',
                        'Ordered'          => 'primary',
                        'Pending'          => 'warning',
                        'Declined'         => 'danger',
                        default            => 'gray',
                    }),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('is_archived')
                    ->label('Archived')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Archived' : 'Active')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(EmployeeEquipmentRequest::STATUSES),
                TernaryFilter::make('is_archived')
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Archived only')
                    ->falseLabel('Active only')
                    ->queries(
                        true: fn ($query) => $query->where('is_archived', true),
                        false: fn ($query) => $query->where('is_archived', false),
                        blank: fn ($query) => $query->where('is_archived', false),
                    ),
            ])
            ->actions([
                Action::make('mark_ordered')
                    ->label('Mark Ordered')
                    ->icon('heroicon-o-truck')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Mark this request as Ordered — notify the employee their items have been ordered.')
                    ->hidden(fn ($record) => !in_array($record->status, ['Pending']))
                    ->action(fn ($record) => self::updateStatus($record, 'Ordered')),

                Action::make('mark_ready')
                    ->label('Ready for Pickup')
                    ->icon('heroicon-o-bell-alert')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Mark this request as Ready for Pickup — notify the employee to collect their items.')
                    ->hidden(fn ($record) => !in_array($record->status, ['Ordered']))
                    ->action(fn ($record) => self::updateStatus($record, 'Ready for Pickup')),

                Action::make('mark_completed')
                    ->label('Complete')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Mark as Completed and archive — the employee has received all items.')
                    ->hidden(fn ($record) => !in_array($record->status, ['Ready for Pickup']))
                    ->action(fn ($record) => self::updateStatus($record, 'Completed', null, true)),

                Action::make('decline')
                    ->label('Decline')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Decline Request')
                    ->form([
                        Select::make('reason')
                            ->label('Reason for declining')
                            ->options(EmployeeEquipmentRequest::DECLINE_REASONS)
                            ->required(),
                        Textarea::make('admin_notes')
                            ->label('Additional notes (optional)')
                            ->rows(2),
                    ])
                    ->hidden(fn ($record) => in_array($record->status, ['Completed', 'Declined']))
                    ->action(function ($record, array $data) {
                        self::updateStatus($record, 'Declined', $data['admin_notes'] ?? null, true, $data['reason']);
                    }),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => !$record->is_archived)
                    ->action(fn ($record) => $record->update(['status' => 'Pending', 'is_archived' => false, 'reason' => null])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeEquipmentRequests::route('/'),
            'view'  => Pages\ViewEmployeeEquipmentRequest::route('/{record}'),
        ];
    }

    private static function updateStatus(
        EmployeeEquipmentRequest $record,
        string $status,
        ?string $notes = null,
        bool $archive = false,
        ?string $reason = null
    ): void
    {
        $record->update([
            'status'      => $status,
            'admin_notes' => $notes ?? $record->admin_notes,
            'reason'      => $reason ?? $record->reason,
            'is_archived' => $archive,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        // Notify the Employee
        $employee = $record->employee;
        if ($employee) {
            $messages = [
                'Ordered'          => 'Your equipment request has been processed — items have been ordered.',
                'Ready for Pickup' => '🎉 Your equipment is ready for pickup at the station!',
                'Completed'        => 'Your equipment request has been completed and archived.',
                'Declined'         => 'Your equipment request was declined.' . ($reason ? " Reason: {$reason}." : '') . ($notes ? " Note: {$notes}" : ''),
            ];

            Notification::make()
                ->title("Request {$status}")
                ->body($messages[$status] ?? "Your request status is now: {$status}")
                ->icon('heroicon-o-shopping-cart')
                ->iconColor(match ($status) {
                    'Completed', 'Ready for Pickup' => 'success',
                    'Ordered'          => 'info',
                    'Declined'         => 'danger',
                    default            => 'warning',
                })
                ->sendToDatabase($employee);
        }

        Notification::make()
            ->title("Request updated to: {$status}")
            ->success()
            ->send();
    }
}
