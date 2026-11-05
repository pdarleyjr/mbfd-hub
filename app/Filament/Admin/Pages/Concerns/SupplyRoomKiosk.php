<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Services\Ems\Kiosk\KioskDevices;
use App\Services\Ems\Kiosk\KioskReport;
use App\Services\Ems\Kiosk\KioskSearchLearning;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;

/**
 * The "Supply room screen" tab of Admin > Medical Supply Room (docs/ems/supply-room-kiosk.md section 7): pair and
 * revoke screens, approve search words, and the list of withdrawals. Pairing and revoking need inventory edit
 * (Captain 5 or any administrator); the services check again.
 */
trait SupplyRoomKiosk
{
    /** @return array<string, mixed> */
    private function screenData(): array
    {
        return app(KioskReport::class)->overview(150) + ['canManageScreens' => $this->emsCan('inventory_edit')];
    }

    public function pairScreenAction(): Action
    {
        return Action::make('pairScreen')->label('Pair supply room screen')->icon('heroicon-o-device-tablet')->color('primary')
            ->visible(fn (): bool => $this->emsCan('inventory_edit'))
            ->modalHeading('Pair a supply room screen')
            ->modalDescription('This makes a pairing code that works once, for 30 minutes. On the tablet that stays in the locked supply room, open www.mbfdhub.com/supply-kiosk and type the code. Members then take supplies on it without signing in.')
            ->modalSubmitActionLabel('Make pairing code')
            ->form([TextInput::make('name')->label('Screen name')->default('Supply room screen')->required()->maxLength(80)])
            ->action(function (array $data): void {
                $user = $this->emsActor('inventory_edit');
                $pairing = app(KioskDevices::class)->createPairing($user, (string) ($data['name'] ?? ''));
                Notification::make()->success()->persistent()->title('Pairing code: '.$pairing['code'])
                    ->body('On the supply room tablet, open '.url('/supply-kiosk').' and type this code. It works once, for 30 minutes. If you are on the tablet now, use Open pairing page instead.')
                    ->actions([NotificationAction::make('open')->label('Open pairing page')->url($pairing['url'])->button()])
                    ->send();
            });
    }

    public function revokeScreenAction(): Action
    {
        return Action::make('revokeScreen')->label('Revoke')->icon('heroicon-o-no-symbol')->color('danger')->size('sm')->outlined()
            ->visible(fn (): bool => $this->emsCan('inventory_edit'))
            ->requiresConfirmation()
            ->modalHeading('Revoke this screen?')
            ->modalDescription('The screen stops working at once and shows "not paired". Withdrawals already taken stay. Pair a screen again with a new link.')
            ->modalSubmitActionLabel('Revoke screen')
            ->action(function (array $arguments): void {
                app(KioskDevices::class)->revoke((int) ($arguments['screen'] ?? 0), $this->emsActor('inventory_edit'));
                Notification::make()->success()->title('Screen revoked')->send();
            });
    }

    public function searchWordsAction(): Action
    {
        return Action::make('searchWords')->label('Search words to approve')->icon('heroicon-o-magnifying-glass')->color('gray')->size('sm')
            ->visible(fn (): bool => $this->emsCan('inventory_edit'))
            ->modalHeading('Search words members used')
            ->modalDescription('Members typed these words and then picked the item shown, at least three times. Approve the ones that should find that item from now on; dismiss the rest. Nothing is added to search without approval.')
            ->modalSubmitActionLabel('Save')
            ->form(function (): array {
                $options = collect(app(KioskSearchLearning::class)->suggestions())
                    ->mapWithKeys(fn (array $s): array => [(string) $s['id'] => "\"{$s['term']}\" finds {$s['item_name']} (picked {$s['picks']} times)"])->all();

                return [
                    CheckboxList::make('approve')->label('Approve')->options($options)->columns(1),
                    CheckboxList::make('dismiss')->label('Dismiss')->options($options)->columns(1),
                ];
            })
            ->action(function (array $data): void {
                $user = $this->emsActor('inventory_edit');
                $approve = array_map('intval', (array) ($data['approve'] ?? []));
                $dismiss = array_map('intval', (array) ($data['dismiss'] ?? []));
                app(KioskSearchLearning::class)->decide($approve, $dismiss, $user);
                Notification::make()->success()->title('Search words saved')->body(count($approve).' approved, '.count(array_diff($dismiss, $approve)).' dismissed.')->send();
            });
    }
}
