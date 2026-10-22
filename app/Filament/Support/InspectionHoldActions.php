<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Apparatus;
use App\Models\ApparatusInspectionException;
use App\Models\User;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\Inspections\InspectionHold;
use App\Support\Fleet\InspectionReviewers;
use Closure;
use Filament\Actions\MountableAction as Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * The four reviewer decisions on a meter rollback hold. Each one is a call to
 * ApparatusInspectionExceptionService::reconcile with the inspection page id (so a
 * forged exception id from another inspection is "not found") and the authoritative
 * meter value the reviewer was looking at when the modal opened. If the fleet value
 * moved in the meantime the service refuses and the reviewer sees its message.
 */
final class InspectionHoldActions
{
    public const ACCEPT = 'acceptMeterReading';

    public const CORRECT = 'enterVerifiedReading';

    public const CLARIFY = 'requestMeterClarification';

    public const KEEP = 'keepCurrentReading';

    /** @var array<string, string> */
    public const LABELS = [
        self::ACCEPT => 'Accept reported reading',
        self::CORRECT => 'Enter verified reading',
        self::CLARIFY => 'Ask the member to clarify',
        self::KEEP => 'Keep current reading',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    /** @param Closure(Action): ?ApparatusInspectionException $holdOf */
    public static function accept(Action $action, Closure $holdOf, int $inspectionId): Action
    {
        return self::base($action, $holdOf)
            ->label(self::LABELS[self::ACCEPT])
            ->icon('heroicon-o-check-circle')
            ->modalHeading('Accept the reported reading')
            ->modalDescription('The vehicle meter becomes the value the member reported. Because that is lower than the current reading, it lowers the baseline every later inspection is compared with. The decision is recorded in the review history.')
            ->modalSubmitActionLabel('Accept and lower the meter')
            ->mountUsing(fn (Forms\ComponentContainer $form, Action $action) => $form->fill(self::startingState($action, $holdOf)))
            ->form([
                Forms\Components\Hidden::make('expected_current_value'),
                Forms\Components\Toggle::make('confirm_meter_reset')
                    ->label('I confirm the vehicle meter was reset or replaced')
                    ->helperText('Required. Without it the reading is not accepted.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm the meter was reset or replaced to accept a lower reading.']),
                self::reason('Why is the reported reading correct?'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $holdOf, $inspectionId, [...$data, 'action' => 'accept_submitted'], 'Reading accepted'));
    }

    /** @param Closure(Action): ?ApparatusInspectionException $holdOf */
    public static function correct(Action $action, Closure $holdOf, int $inspectionId): Action
    {
        return self::base($action, $holdOf)
            ->label(self::LABELS[self::CORRECT])
            ->icon('heroicon-o-pencil-square')
            ->modalHeading('Enter the verified reading')
            ->modalDescription('Use the value you confirmed against the vehicle. A value lower than the current reading also needs the reset confirmation below.')
            ->modalSubmitActionLabel('Save verified reading')
            ->mountUsing(fn (Forms\ComponentContainer $form, Action $action) => $form->fill(self::startingState($action, $holdOf)))
            ->form([
                Forms\Components\Hidden::make('expected_current_value'),
                Forms\Components\TextInput::make('value')->label('Verified reading')->numeric()->minValue(0)->required(),
                Forms\Components\Toggle::make('confirm_meter_reset')
                    ->label('The vehicle meter was reset or replaced')
                    ->helperText('Only needed when the verified reading is lower than the current one. It lowers the baseline for later inspections.'),
                self::reason('How was the reading verified?'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $holdOf, $inspectionId, [...$data, 'action' => 'correct'], 'Verified reading saved'));
    }

    /** @param Closure(Action): ?ApparatusInspectionException $holdOf */
    public static function clarify(Action $action, Closure $holdOf, int $inspectionId): Action
    {
        return self::base($action, $holdOf)
            ->label(self::LABELS[self::CLARIFY])
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->modalHeading('Ask the member to clarify the reading')
            ->modalDescription('The hold stays open. The member gets your question and can answer from their inspection.')
            ->modalSubmitActionLabel('Send question')
            ->mountUsing(fn (Forms\ComponentContainer $form, Action $action) => $form->fill(self::startingState($action, $holdOf)))
            ->form([
                Forms\Components\Hidden::make('expected_current_value'),
                self::reason('What do you need to know?', 'Your question to the member'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $holdOf, $inspectionId, [...$data, 'action' => 'request_revision'], 'Question sent to the member'));
    }

    /** @param Closure(Action): ?ApparatusInspectionException $holdOf */
    public static function keep(Action $action, Closure $holdOf, int $inspectionId): Action
    {
        return self::base($action, $holdOf)
            ->label(self::LABELS[self::KEEP])
            ->icon('heroicon-o-x-circle')
            ->modalHeading('Keep the current reading')
            ->modalDescription('The vehicle keeps its current meter reading and the reported value is rejected. Nothing else in the inspection changes.')
            ->modalSubmitActionLabel('Keep current reading')
            ->mountUsing(fn (Forms\ComponentContainer $form, Action $action) => $form->fill(self::startingState($action, $holdOf)))
            ->form([
                Forms\Components\Hidden::make('expected_current_value'),
                self::reason('Why is the reported value rejected?'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, $holdOf, $inspectionId, [...$data, 'action' => 'dismiss'], 'Current reading kept'));
    }

    // ---------------------------------------------------------------- internals

    /** @param Closure(Action): ?ApparatusInspectionException $holdOf */
    private static function base(Action $action, Closure $holdOf): Action
    {
        return $action
            ->color('gray')
            ->visible(function (Action $action) use ($holdOf): bool {
                $hold = $holdOf($action);
                $user = auth()->user();

                return $hold instanceof ApparatusInspectionException
                    && InspectionHold::isMeter($hold)
                    && ! in_array($hold->status, InspectionHold::CLOSED_STATUSES, true)
                    && $user instanceof User
                    && InspectionReviewers::canReview($user);
            });
    }

    private static function reason(string $helper, string $label = 'Reason for the decision'): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('reason')
            ->label($label)
            ->helperText($helper)
            ->required()
            ->rows(3)
            ->maxLength(2000);
    }

    /**
     * The fleet's authoritative reading at the moment the modal opens. The service compares it with
     * the live value on submit, so a decision made against a stale screen is refused.
     *
     * @param  Closure(Action): ?ApparatusInspectionException  $holdOf
     * @return array<string, mixed>
     */
    private static function startingState(Action $action, Closure $holdOf): array
    {
        $hold = $holdOf($action);
        abort_unless($hold instanceof ApparatusInspectionException, 404);
        $apparatus = Apparatus::query()->find($hold->apparatus_id);
        $current = $apparatus?->getAttribute($hold->field === 'miles' ? 'current_miles' : 'current_engine_hours');

        return ['expected_current_value' => $current === null ? null : (float) $current];
    }

    /**
     * @param  Closure(Action): ?ApparatusInspectionException  $holdOf
     * @param  array<string, mixed>  $data
     */
    private static function run(Action $action, Closure $holdOf, int $inspectionId, array $data, string $success): void
    {
        $hold = $holdOf($action);
        abort_unless($hold instanceof ApparatusInspectionException, 404);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            app(ApparatusInspectionExceptionService::class)->reconcile((int) $hold->getKey(), $user, $data, $inspectionId);
        } catch (ValidationException $exception) {
            Notification::make()->danger()
                ->title('That decision was not saved')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))
                ->send();
            $action->halt();

            return;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('You do not have permission to do this.')->send();

            return;
        } catch (ModelNotFoundException) {
            Notification::make()->danger()->title('That reading is no longer available for review.')->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
