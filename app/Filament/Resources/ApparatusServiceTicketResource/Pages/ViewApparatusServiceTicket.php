<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusServiceTicketResource\Pages;

use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Models\ApparatusServiceTicket;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewApparatusServiceTicket extends ViewRecord
{
    protected static string $resource = ApparatusServiceTicketResource::class;

    protected function getHeaderActions(): array
    {
        $workflowActions = collect([
            ApparatusServiceTicketStatus::Acknowledged,
            ApparatusServiceTicketStatus::Scheduled,
            ApparatusServiceTicketStatus::InProgress,
            ApparatusServiceTicketStatus::WaitingForParts,
            ApparatusServiceTicketStatus::Completed,
            ApparatusServiceTicketStatus::Cancelled,
        ])->map(fn (ApparatusServiceTicketStatus $status): Actions\Action => $this->transitionAction($status))->all();

        return [...$workflowActions, $this->operationalStatusAction()];
    }

    private function transitionAction(ApparatusServiceTicketStatus $status): Actions\Action
    {
        return Actions\Action::make('transition_'.$status->value)
            ->label(match ($status) {
                ApparatusServiceTicketStatus::Acknowledged => 'Acknowledge',
                ApparatusServiceTicketStatus::Scheduled => 'Schedule',
                ApparatusServiceTicketStatus::InProgress => 'Start Work',
                ApparatusServiceTicketStatus::WaitingForParts => 'Wait for Parts',
                ApparatusServiceTicketStatus::Completed => 'Complete',
                ApparatusServiceTicketStatus::Cancelled => 'Cancel',
                default => $status->label(),
            })
            ->icon(match ($status) {
                ApparatusServiceTicketStatus::Completed => 'heroicon-o-check-circle',
                ApparatusServiceTicketStatus::Cancelled => 'heroicon-o-x-circle',
                ApparatusServiceTicketStatus::Scheduled => 'heroicon-o-calendar-days',
                default => 'heroicon-o-arrow-path-rounded-square',
            })
            ->color($status->color())
            ->visible(fn (): bool => ApparatusServiceTicketStatus::from($this->ticket()->status)->canTransitionTo($status))
            ->modalHeading("{$status->label()} {$this->ticket()->ticket_number}")
            ->form([
                Forms\Components\DateTimePicker::make('scheduled_for')
                    ->label('Scheduled For')
                    ->default($this->ticket()->scheduled_for)
                    ->required($status === ApparatusServiceTicketStatus::Scheduled)
                    ->visible($status === ApparatusServiceTicketStatus::Scheduled),
                Forms\Components\TextInput::make('service_type')
                    ->label('Service Type')
                    ->default($this->ticket()->service_type)
                    ->required($status === ApparatusServiceTicketStatus::Scheduled)
                    ->visible($status === ApparatusServiceTicketStatus::Scheduled)
                    ->maxLength(255),
                Forms\Components\TextInput::make('scheduled_location')
                    ->label('Service Location')
                    ->default($this->ticket()->scheduled_location)
                    ->required($status === ApparatusServiceTicketStatus::Scheduled)
                    ->visible($status === ApparatusServiceTicketStatus::Scheduled)
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make('expected_return_at')
                    ->label('Expected Return')
                    ->default($this->ticket()->expected_return_at)
                    ->afterOrEqual('scheduled_for')
                    ->visible($status === ApparatusServiceTicketStatus::Scheduled),
                Forms\Components\Select::make('assigned_to_user_id')
                    ->label('Assigned To')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default($this->ticket()->assigned_to_user_id)
                    ->searchable(),
                Forms\Components\TextInput::make('assigned_vendor')
                    ->default($this->ticket()->assigned_vendor)
                    ->maxLength(255),
                Forms\Components\Textarea::make('public_note')
                    ->label('Public Update')
                    ->helperText('Visible to station staff. Do not include employee, vendor contact, or mechanic-only details.')
                    ->rows(3)
                    ->required($status === ApparatusServiceTicketStatus::Scheduled),
                Forms\Components\Textarea::make('internal_note')
                    ->label('Internal Note')
                    ->helperText('Visible only to authorized Fleet and administrators.')
                    ->rows(3),
                Forms\Components\Textarea::make('status_detail')->label('Internal Status Detail')->rows(2),
                Forms\Components\Textarea::make('resolution_summary')
                    ->label('Resolution Summary')
                    ->visible($status === ApparatusServiceTicketStatus::Completed)
                    ->required($status === ApparatusServiceTicketStatus::Completed)
                    ->rows(3),
                Forms\Components\TextInput::make('completed_engine_hours')
                    ->label('Completed Engine Hours')
                    ->numeric()
                    ->minValue(0)
                    ->visible($status === ApparatusServiceTicketStatus::Completed),
                Forms\Components\TextInput::make('completed_miles')
                    ->label('Completed Mileage')
                    ->numeric()
                    ->minValue(0)
                    ->visible($status === ApparatusServiceTicketStatus::Completed),
            ])
            ->requiresConfirmation($status === ApparatusServiceTicketStatus::Cancelled)
            ->action(function (array $data) use ($status): void {
                /** @var User $actor */
                $actor = auth()->user();
                $this->record = app(ApparatusServiceTicketWorkflowService::class)
                    ->transition($this->ticket(), $actor, $status, $data);
                Notification::make()->title("Ticket marked {$status->label()}")->success()->send();
            });
    }

    private function operationalStatusAction(): Actions\Action
    {
        return Actions\Action::make('change_unit_operational_status')
            ->label('Change Unit Status')
            ->icon('heroicon-o-truck')
            ->color('gray')
            ->form([
                Forms\Components\Select::make('status')
                    ->label('Official Apparatus Status')
                    ->options([
                        'In Service' => 'In Service',
                        'Out of Service' => 'Out of Service',
                        'Maintenance' => 'Maintenance',
                        'Available' => 'Available',
                        'Reserve' => 'Reserve',
                    ])
                    ->default(fn (): ?string => $this->ticket()->apparatus?->status)
                    ->required(),
                Forms\Components\Textarea::make('public_note')
                    ->label('Station-facing Status Message')
                    ->helperText('Visible in the station and checkout service notice.')
                    ->required()
                    ->maxLength(5000),
                Forms\Components\Textarea::make('internal_note')
                    ->label('Internal Reason')
                    ->maxLength(10000),
            ])
            ->action(function (array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                $ticket = $this->ticket();
                app(ApparatusServiceTicketWorkflowService::class)->changeOperationalStatus(
                    $ticket->apparatus,
                    $actor,
                    $data['status'],
                    $ticket,
                    $data['public_note'],
                    $data['internal_note'] ?? null,
                );
                $this->record = $ticket->fresh();
                Notification::make()->title("Unit marked {$data['status']}")->success()->send();
            });
    }

    private function ticket(): ApparatusServiceTicket
    {
        $record = $this->getRecord();
        assert($record instanceof ApparatusServiceTicket);

        return $record;
    }
}
