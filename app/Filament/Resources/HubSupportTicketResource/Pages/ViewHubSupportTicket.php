<?php

declare(strict_types=1);

namespace App\Filament\Resources\HubSupportTicketResource\Pages;

use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;
use App\Enums\HubSupportTicketStatus;
use App\Filament\Concerns\ArchivesRequests;
use App\Filament\Resources\HubSupportTicketResource;
use App\Models\HubSupportTicket;
use App\Models\User;
use App\Services\HubSupport\HubSupportTicketRecoveryService;
use App\Services\HubSupport\HubSupportTicketSideEffectService;
use App\Services\HubSupport\HubSupportTicketWorkflowService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewHubSupportTicket extends ViewRecord
{
    use ArchivesRequests;

    protected static string $resource = HubSupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->getArchiveActions(),
            Actions\Action::make('delete')
                ->label('Move to Trash')->icon('heroicon-o-trash')->color('danger')
                ->modalHeading('Move issue report to Trash?')
                ->modalDescription('The report leaves normal views. Its files and history remain recoverable by an administrator.')
                ->visible(fn (): bool => ! $this->ticket()->trashed() && (auth()->user()?->can('delete', $this->ticket()) ?? false))
                ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->maxLength(2000)])
                ->requiresConfirmation()->action(function (array $data): void {
                    $this->record = app(HubSupportTicketRecoveryService::class)->trash($this->ticket(), auth()->user(), $data['reason']);
                    Notification::make()->title('Issue report moved to Trash')->success()->send();
                }),
            Actions\Action::make('restore_trash')
                ->label('Restore from Trash')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (): bool => $this->ticket()->trashed() && (auth()->user()?->can('restore', $this->ticket()) ?? false))
                ->requiresConfirmation()->action(function (): void {
                    $this->record = app(HubSupportTicketRecoveryService::class)->restore($this->ticket(), auth()->user());
                    Notification::make()->title('Issue report restored')->success()->send();
                }),
            ...collect(HubSupportTicketStatus::cases())->filter(fn ($status): bool => $status !== HubSupportTicketStatus::New)
                ->map(fn ($status): Actions\Action => $this->transitionAction($status))->all(),
            $this->updateAction(),
            Actions\Action::make('retry_notifications')
                ->label('Retry failed notifications')->color('gray')
                ->visible(fn (): bool => $this->canManage() && \App\Models\HubSupportNotificationDelivery::query()
                    ->whereIn('hub_support_ticket_update_id', $this->ticket()->updates()->select('id'))
                    ->whereNull('delivered_at')->whereNull('cancelled_at')->where('attempts', '>=', 5)->exists())
                ->requiresConfirmation()->action(function (): void {
                    app(HubSupportTicketSideEffectService::class)->retryFailed($this->ticket(), auth()->user());
                    $this->record = $this->ticket()->refresh();
                    Notification::make()->title('Notification retry requested')->success()->send();
                }),
        ];
    }

    private function transitionAction(HubSupportTicketStatus $status): Actions\Action
    {
        return Actions\Action::make('transition_'.$status->value)
            ->label(match ($status) {
                HubSupportTicketStatus::Acknowledged => 'Acknowledge',
                HubSupportTicketStatus::InProgress => 'Start Work',
                HubSupportTicketStatus::WaitingForReporter => 'Request Information',
                HubSupportTicketStatus::Resolved => 'Resolve',
                HubSupportTicketStatus::Closed => 'Close',
                default => $status->memberLabel(),
            })
            ->visible(fn (): bool => $this->canManage() && $this->ticket()->status->canTransitionTo($status))
            ->form([
                ...$this->actionContext(),
                Forms\Components\Textarea::make('public_response')->label('Member-visible response')
                    ->required($status === HubSupportTicketStatus::WaitingForReporter)->maxLength(5000),
                Forms\Components\Textarea::make('internal_note')->label('Internal note')->maxLength(10000),
                Forms\Components\Textarea::make('resolution_summary')->label('Resolution summary')
                    ->required($status === HubSupportTicketStatus::Resolved)
                    ->visible($status === HubSupportTicketStatus::Resolved)->maxLength(10000),
            ])
            ->action(fn (array $data) => $this->apply($status, $data));
    }

    private function updateAction(): Actions\Action
    {
        return Actions\Action::make('update_report')
            ->label('Assign / Update')
            ->visible(fn (): bool => $this->canManage())
            ->form([
                ...$this->actionContext(),
                Forms\Components\Select::make('assigned_to_user_id')->label('Assigned To')
                    ->options(fn (): array => User::query()->where(function ($query): void {
                        $query->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'admin.support.manage'))
                            ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'));
                    })->orderBy('name')->get()->filter(fn (User $user): bool => $user->isAuthenticationAllowed()
                        && $user->hasCurrentAdminPanelEntitlement() && $user->can('update', $this->ticket()))
                        ->pluck('name', 'id')->all())
                    ->default($this->ticket()->assigned_to_user_id)->searchable(),
                Forms\Components\Select::make('category')->options(collect(HubSupportTicketCategory::cases())->mapWithKeys(fn ($category) => [$category->value => $category->label()])->all())
                    ->default($this->ticket()->category->value),
                Forms\Components\Select::make('impact')->options(collect(HubSupportTicketImpact::cases())->mapWithKeys(fn ($impact) => [$impact->value => $impact->label()])->all())
                    ->default($this->ticket()->impact->value),
                Forms\Components\Textarea::make('public_response')->label('Member-visible response')->maxLength(5000),
                Forms\Components\Textarea::make('internal_note')->label('Internal note')->maxLength(10000),
            ])
            ->action(fn (array $data) => $this->apply($this->ticket()->status, $data));
    }

    private function apply(HubSupportTicketStatus $status, array $data): void
    {
        abort_unless($this->canManage(), 403);
        $actor = auth()->user();
        assert($actor instanceof User);
        $this->record = app(HubSupportTicketWorkflowService::class)->transition($this->ticket(), $actor, $status, $data);
        Notification::make()->title('Issue report updated')->success()->send();
    }

    private function canManage(): bool
    {
        return ! $this->ticket()->trashed() && ! $this->ticket()->isArchived()
            && (auth()->user()?->can('update', $this->ticket()) ?? false);
    }

    private function actionContext(): array
    {
        return [
            Forms\Components\Hidden::make('client_action_id')->default(fn (): string => (string) \Illuminate\Support\Str::uuid()),
            Forms\Components\Hidden::make('expected_update_id')->default(fn (): int => (int) $this->ticket()->updates()->max('id')),
        ];
    }

    private function ticket(): HubSupportTicket
    {
        $record = $this->getRecord();
        assert($record instanceof HubSupportTicket);

        return $record;
    }
}
