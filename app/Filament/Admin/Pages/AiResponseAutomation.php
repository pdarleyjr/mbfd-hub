<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\TriesAMessage;
use App\Models\AiAutomation\AiAutomationSwitchEvent;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\User;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\Admin\OverviewNumbers;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\BacklogService;
use App\Services\AiAutomation\Model\AiBudgetGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

/**
 * Overview: the one place to turn automatic replies ON or OFF and see how they are doing. Equipment Priorities,
 * Knowledge & Instructions, Response Messages and Activity & History sit beside it in the same menu group.
 */
class AiResponseAutomation extends Page
{
    use \App\Filament\Concerns\RegistersSupportAutomationNavigation;
    use TriesAMessage;

    protected static ?string $navigationGroup = AdminLabels::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Support Services Automation';

    protected static ?string $slug = 'ai-response-automation';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.admin.pages.ai-response-automation';

    protected ?string $maxContentWidth = MaxWidth::SevenExtraLarge->value;

    public static function canAccess(): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    public function turnOffAction(): Action
    {
        // Deliberately one tap, no confirmation: stopping must always be instant.
        return Action::make('turnOff')
            ->label('Turn OFF')
            ->color('danger')
            ->size('xl')
            ->authorize(fn (): bool => self::canAccess())
            ->action(function (): void {
                $result = app(AiAutomationSwitch::class)->turnOff($this->actor());
                Notification::make()
                    ->title('Automatic replies are OFF')
                    ->body($result['suppressed'] > 0 ? "Stopped {$result['suppressed']} replies that were waiting to go out." : null)
                    ->success()
                    ->send();
            });
    }

    public function turnOnAction(): Action
    {
        return Action::make('turnOn')
            ->label('Turn ON')
            ->color('success')
            ->size('xl')
            ->requiresConfirmation()
            ->modalHeading('Turn automatic replies ON?')
            ->modalDescription('New vehicle checkouts, station requests and Support Services messages will get a short reply from Support Services. Older ones are only answered when you press Process Pending Backlog.')
            ->modalSubmitActionLabel('Turn ON')
            ->authorize(fn (): bool => self::canAccess())
            ->disabled(fn (): bool => app(AiAutomationSwitch::class)->hardDisabled())
            ->action(function (): void {
                app(AiAutomationSwitch::class)->turnOn($this->actor());
                Notification::make()->title('Automatic replies are ON')->success()->send();
            });
    }

    public function previewBacklogAction(): Action
    {
        return Action::make('previewBacklog')
            ->label('Preview Pending Backlog')
            ->color('gray')
            ->authorize(fn (): bool => self::canAccess())
            ->disabled(fn (): bool => $this->latestRun()?->state === AiBacklogRun::STATE_PREVIEWING)
            ->action(function (): void {
                app(BacklogService::class)->requestPreview($this->actor());
                Notification::make()->title('Counting the backlog')->body('This takes a moment. Nothing is sent.')->success()->send();
            });
    }

    public function processBacklogAction(): Action
    {
        return Action::make('processBacklog')
            ->label(fn (): string => $this->latestRun()?->state === AiBacklogRun::STATE_PAUSED ? 'Resume Pending Backlog' : 'Process Pending Backlog')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Process the pending backlog?')
            ->modalDescription(function (): string {
                $count = (int) ($this->latestRun()?->preview['would_send'] ?? 0);

                return "Up to {$count} older submissions get one short reply each, in small batches. No email and no push notifications. Turning automatic replies OFF stops it immediately.";
            })
            ->modalSubmitActionLabel('Process')
            ->authorize(fn (): bool => self::canAccess())
            ->disabled(fn (): bool => ! app(AiAutomationSwitch::class)->isOn()
                || ! in_array($this->latestRun()?->state, [AiBacklogRun::STATE_PREVIEWED, AiBacklogRun::STATE_PAUSED], true))
            ->action(function (): void {
                $run = $this->latestRun();
                $started = $run !== null && app(BacklogService::class)->start($run);
                Notification::make()
                    ->title($started ? 'Backlog processing started' : 'Backlog could not start')
                    ->body($started ? null : 'Turn automatic replies ON and preview the backlog first.')
                    ->color($started ? 'success' : 'warning')
                    ->send();
            });
    }

    public function pauseBacklogAction(): Action
    {
        return Action::make('pauseBacklog')
            ->label('Pause')
            ->color('gray')
            ->authorize(fn (): bool => self::canAccess())
            ->visible(fn (): bool => $this->latestRun()?->state === AiBacklogRun::STATE_RUNNING)
            ->action(function (): void {
                $run = $this->latestRun();
                if ($run !== null) {
                    app(BacklogService::class)->pause($run);
                }
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $switch = app(AiAutomationSwitch::class);
        $numbers = app(OverviewNumbers::class);

        return [
            'isOn' => $switch->isOn(),
            'hardDisabled' => $switch->hardDisabled(),
            'today' => $numbers->today(),
            'levels' => $numbers->openLevelCounts(),
            'week' => $numbers->lastSevenDays(),
            'usage' => app(AiBudgetGuard::class)->usageSummary(),
            'modelEnabled' => (bool) config('ai_automation.model.enabled'),
            'run' => $this->latestRun(),
            'switchEvents' => AiAutomationSwitchEvent::query()->latest('id')->limit(8)->get(),
        ];
    }

    private function latestRun(): ?AiBacklogRun
    {
        return app(BacklogService::class)->latest();
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
