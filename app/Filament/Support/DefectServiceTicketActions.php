<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use App\Models\User;
use App\Services\ApparatusDefectServiceTicketService;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Shared "Create Service Ticket" / "View ticket" actions for an equipment
 * issue. Works on table actions and page header actions alike: callers pass a
 * resolver that returns the issue for the action being rendered or run.
 */
final class DefectServiceTicketActions
{
    public const CREATE = 'create_service_ticket';

    public const VIEW = 'view_service_ticket';

    /**
     * @template TAction of object
     *
     * @param  TAction  $action  a Filament table or page Action
     * @param  Closure(object): ?ApparatusDefect  $resolveRecord  receives the action
     * @return TAction
     */
    public static function configureCreate(object $action, Closure $resolveRecord): object
    {
        return $action
            ->label('Create Service Ticket')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('primary')
            ->tooltip('Open a Fleet service ticket for this equipment issue')
            ->modalHeading('Create Service Ticket')
            ->modalDescription('Creating a ticket does not resolve this issue. The unit stays in its current status unless you choose otherwise below.')
            ->modalSubmitActionLabel('Create Service Ticket')
            ->visible(fn ($action): bool => ($defect = $resolveRecord($action)) instanceof ApparatusDefect
                && self::canCreateFor($defect))
            ->mountUsing(function ($action, Forms\ComponentContainer $form) use ($resolveRecord): void {
                $defect = $resolveRecord($action);
                abort_unless($defect instanceof ApparatusDefect, 404);
                $form->fill([
                    ...app(ApparatusDefectServiceTicketService::class)->defaults($defect),
                    'also_mark_out_of_service' => false,
                ]);
            })
            ->form([
                Forms\Components\TextInput::make('title')
                    ->label('Ticket title')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255),
                Forms\Components\Select::make('category')
                    ->options(ApparatusServiceTicketCategory::options())
                    ->required(),
                Forms\Components\Select::make('priority')
                    ->options(ApparatusServiceTicketPriority::options())
                    ->required(),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->minLength(10)
                    ->maxLength(10000)
                    ->rows(6),
                Forms\Components\Toggle::make('also_mark_out_of_service')
                    ->label('Also mark unit Out of Service')
                    ->helperText('Off by default. Turn on only if this issue makes the unit unfit for service.')
                    ->default(false),
            ])
            ->action(function (array $data, $action) use ($resolveRecord): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $defect = $resolveRecord($action);
                abort_unless($defect instanceof ApparatusDefect, 404);

                try {
                    $result = app(ApparatusDefectServiceTicketService::class)->create(
                        $defect,
                        $user,
                        $data,
                        (bool) ($data['also_mark_out_of_service'] ?? false),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Service ticket not created')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($result->created ? 'Service ticket created' : 'Service ticket already exists')
                    ->body("{$result->ticket->ticket_number}: {$result->ticket->title}")
                    ->send();
            });
    }

    /**
     * @template TAction of object
     *
     * @param  TAction  $action
     * @param  Closure(object): ?ApparatusDefect  $resolveRecord
     * @return TAction
     */
    public static function configureView(object $action, Closure $resolveRecord): object
    {
        return $action
            ->label('View ticket')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn ($action): bool => ($resolveRecord($action)?->service_ticket_id ?? null) !== null
                && (auth()->user()?->can('viewAny', ApparatusServiceTicket::class) ?? false))
            ->url(fn ($action): ?string => ($ticketId = $resolveRecord($action)?->service_ticket_id) === null
                ? null
                : ApparatusServiceTicketResource::getUrl('view', ['record' => $ticketId]));
    }

    public static function canCreateFor(ApparatusDefect $defect): bool
    {
        return ! $defect->resolved
            && $defect->status !== 'resolved'
            && $defect->service_ticket_id === null
            && (auth()->user()?->can('createFromDefect', ApparatusServiceTicket::class) ?? false);
    }
}
