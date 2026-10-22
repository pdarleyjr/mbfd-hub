<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\User;
use App\Services\CommandBoard\CommandBoardPinAdministration;
use App\Services\CommandBoard\CommandBoardPinChange;
use App\Services\CommandBoard\CommandBoardPinStatus;
use App\Services\CommandBoard\CommandBoardRequestFailed;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Sets and changes the Command Board (cmd.mbfdhub.com) command PIN.
 *
 * The Command Board is the system of record. This page reads its status and
 * sends it a bcrypt hash; the Hub never stores, shows, or logs the PIN.
 */
class CommandBoardPin extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationLabel = 'Command Board PIN';

    protected static ?string $title = 'Command Board PIN';

    protected static ?string $slug = 'command-board-pin';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 80;

    protected static string $view = 'filament.admin.pages.command-board-pin';

    private const TIMEZONE = 'America/New_York';

    private const AUDIT_FAILED_NOTE = 'The Hub could not write its audit record for this change. The failure was logged; let a Hub administrator know so the change can be recorded.';

    #[Locked]
    public bool $linkConfigured = false;

    /** @var array{configured: bool, source: string, source_label: string, pin_version: int, updated_at: string|null, updated_by: string|null}|null */
    #[Locked]
    public ?array $status = null;

    #[Locked]
    public ?string $statusError = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(CommandBoardPinAdministration::class)->canAdminister($user);
    }

    /**
     * Retired from navigation: the Command Board no longer asks for a PIN; Hub
     * sign-in with 300 Command Access admits members. The page stays reachable
     * for authorized administrators only as the rollback control while the
     * Board's CMD_PIN_REQUIRED switch exists.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->loadStatus();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->setPinAction(), $this->useCameraPinAction(), $this->refreshStatusAction()];
    }

    public function setPinAction(): Action
    {
        return Action::make('setPin')
            ->label('Set a new PIN')
            ->icon('heroicon-o-key')
            ->authorize(fn (): bool => static::canAccess())
            ->disabled(fn (): bool => ! $this->administration()->isLinkConfigured())
            ->tooltip(fn (): ?string => $this->administration()->isLinkConfigured() ? null : CommandBoardRequestFailed::notConfigured()->getMessage())
            ->modalHeading('Set a new Command Board PIN')
            ->modalDescription('Members will need the new PIN the next time the Command Board asks for it. The 300 camera PIN does not change.')
            ->modalSubmitActionLabel('Set PIN')
            ->form([
                TextInput::make('pin')
                    ->label('New PIN')
                    ->password()
                    ->required()
                    ->regex(CommandBoardPinAdministration::PIN_PATTERN)
                    ->validationMessages(['regex' => 'The PIN must be 4 to 8 digits.'])
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 8])
                    ->helperText('4 to 8 digits.'),
                TextInput::make('pin_confirmation')
                    ->label('Confirm new PIN')
                    ->password()
                    ->required()
                    ->same('pin')
                    ->validationMessages(['same' => 'The two PINs do not match.'])
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 8]),
                ...$this->reauthenticationFields(),
            ])
            ->action(function (array $data, Action $action): void {
                $this->changePin($action, fn (User $actor): CommandBoardPinChange => $this->administration()->setNewPin(
                    $actor, (string) $data['pin'], (string) $data['current_password'], (string) $data['reason'],
                ), 'Command Board PIN updated', 'Members will need the new PIN the next time the Command Board asks for it.');
            });
    }

    public function useCameraPinAction(): Action
    {
        return Action::make('useCameraPin')
            ->label('Use the 300 camera PIN')
            ->icon('heroicon-o-video-camera')
            ->color('gray')
            ->authorize(fn (): bool => static::canAccess())
            ->disabled(fn (): bool => $this->cameraPinBlockedReason() !== null)
            ->tooltip(fn (): ?string => $this->cameraPinBlockedReason())
            ->modalHeading('Use the 300 camera PIN?')
            ->modalDescription('This copies the current 300 camera PIN to the Command Board once, right now. After that the two PINs are separate: changing either one later never changes the other.')
            ->modalSubmitActionLabel('Copy the 300 camera PIN')
            ->form($this->reauthenticationFields())
            ->action(function (array $data, Action $action): void {
                $this->changePin($action, fn (User $actor): CommandBoardPinChange => $this->administration()->copyCameraPin(
                    $actor, (string) $data['current_password'], (string) $data['reason'],
                ), 'Command Board PIN now matches the 300 camera PIN', 'This was a one-time copy. Changing either PIN later will not change the other.');
            });
    }

    public function refreshStatusAction(): Action
    {
        return Action::make('refreshStatus')
            ->label('Refresh status')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->authorize(fn (): bool => static::canAccess())
            ->action(function (): void {
                $this->authorizeAccess();
                $this->loadStatus();
            });
    }

    public function statusInfolist(Infolist $infolist): Infolist
    {
        $hasStatus = $this->status !== null;

        return $infolist
            ->state($this->infolistState())
            ->schema([
                Section::make('Current status')
                    ->description('Read from the Command Board each time this page loads. The Hub never stores or shows the PIN.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('connection')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->icon('heroicon-o-exclamation-triangle')
                            ->color('warning')
                            ->visible(! $hasStatus),
                        TextEntry::make('configured')
                            ->label('PIN configured')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Yes' ? 'success' : 'danger')
                            ->visible($hasStatus),
                        TextEntry::make('source')->label('Source')->visible($hasStatus),
                        TextEntry::make('updated_at')->label('Last updated')->visible($hasStatus),
                        TextEntry::make('updated_by')->label('Updated by')->visible($hasStatus),
                    ]),
                Section::make('How this works')
                    ->schema([
                        TextEntry::make('how_pin')->hiddenLabel(),
                        TextEntry::make('how_camera')->hiddenLabel(),
                        TextEntry::make('camera_unavailable')
                            ->label('300 camera PIN')
                            ->color('warning')
                            ->visible($this->administration()->cameraPinUnavailableReason() !== null),
                    ]),
            ]);
    }

    /** @return array<string, string|null> */
    private function infolistState(): array
    {
        $status = $this->status;
        $updatedAt = $status['updated_at'] ?? null;

        return [
            'connection' => $this->statusError ?? 'Command Board status is not available.',
            'configured' => ($status['configured'] ?? false) ? 'Yes' : 'No',
            'source' => $status['source_label'] ?? null,
            'updated_at' => $updatedAt === null ? 'Not recorded' : CarbonImmutable::parse($updatedAt)->setTimezone(self::TIMEZONE)->format('M j, Y g:i A T'),
            'updated_by' => $status['updated_by'] ?? 'Not recorded',
            'how_pin' => 'After signing in with their Hub account, members enter this PIN to open the Command Board. "Set a new PIN" sends the Command Board a one-way bcrypt hash of the PIN, never the PIN itself, and the Hub audit log records who changed it and why.',
            'how_camera' => '"Use the 300 camera PIN" makes the Command Board PIN the same as the current 300 camera (Morning Lineup) PIN. It is a one-time copy at the moment you click it: the two PINs stay independent afterwards, so changing either one never changes the other.',
            'camera_unavailable' => $this->administration()->cameraPinUnavailableReason(),
        ];
    }

    /**
     * @param  Closure(User): CommandBoardPinChange  $change
     */
    private function changePin(Action $action, Closure $change, string $successTitle, string $successBody): void
    {
        $actor = $this->authorizeAccess();
        try {
            $result = $change($actor);
        } catch (CurrentPasswordMismatch) {
            throw ValidationException::withMessages([$this->actionField('current_password') => 'Your current password is incorrect.']);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->showValidationFailure($exception);
            $action->halt();

            return;
        } catch (CommandBoardRequestFailed $exception) {
            Notification::make()
                ->danger()
                ->title(in_array($exception->kind, [CommandBoardRequestFailed::TIMED_OUT, CommandBoardRequestFailed::UNEXPECTED, CommandBoardRequestFailed::INVALID_RESPONSE], true)
                    ? 'Could not confirm the Command Board PIN change'
                    : 'Command Board PIN not changed')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
            $action->halt();

            return;
        }

        $this->applyStatus($result->status);
        $notification = Notification::make()
            ->success()
            ->title($successTitle)
            ->body($result->audited ? $successBody : $successBody.' '.self::AUDIT_FAILED_NOTE);
        if (! $result->audited) {
            $notification->persistent();
        }
        $notification->send();
    }

    /** Field errors go back onto the modal form; anything else becomes a notification. */
    private function showValidationFailure(ValidationException $exception): void
    {
        $fields = [];
        foreach ($exception->errors() as $key => $messages) {
            if (in_array($key, ['pin', 'reason', 'current_password'], true)) {
                $fields[$this->actionField($key)] = $messages;
            } else {
                Notification::make()->danger()->title('Command Board PIN not changed')->body((string) ($messages[0] ?? ''))->persistent()->send();
            }
        }
        if ($fields !== []) {
            throw ValidationException::withMessages($fields);
        }
    }

    private function actionField(string $field): string
    {
        return $this->getMountedActionForm()?->getStatePath().'.'.$field;
    }

    /** @return array<\Filament\Forms\Components\Component> */
    private function reauthenticationFields(): array
    {
        return [
            TextInput::make('current_password')
                ->label('Your current Hub password')
                ->password()
                ->autocomplete('current-password')
                ->required(),
            Textarea::make('reason')
                ->label('Reason for this change')
                ->required()
                ->maxLength(CommandBoardPinAdministration::REASON_MAX_LENGTH),
        ];
    }

    private function cameraPinBlockedReason(): ?string
    {
        if (! $this->administration()->isLinkConfigured()) {
            return CommandBoardRequestFailed::notConfigured()->getMessage();
        }

        return $this->administration()->cameraPinUnavailableReason();
    }

    private function loadStatus(): void
    {
        $this->linkConfigured = $this->administration()->isLinkConfigured();
        if (! $this->linkConfigured) {
            $this->status = null;
            $this->statusError = CommandBoardRequestFailed::notConfigured()->getMessage();

            return;
        }

        try {
            $this->applyStatus($this->administration()->status());
        } catch (CommandBoardRequestFailed $exception) {
            $this->status = null;
            $this->statusError = $exception->getMessage();
        }
    }

    private function applyStatus(CommandBoardPinStatus $status): void
    {
        $this->status = $status->toArray();
        $this->statusError = null;
    }

    private function authorizeAccess(): User
    {
        abort_unless(static::canAccess(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function administration(): CommandBoardPinAdministration
    {
        return app(CommandBoardPinAdministration::class);
    }
}
