<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\HasPageTableExportActions;
use App\Models\AiAutomation\AiAutomationSwitchEvent;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\User;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\BacklogService;
use App\Services\AiAutomation\Finding;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\Model\AiBudgetGuard;
use App\Services\AiAutomation\ResponseComposer;
use App\Services\Exports\ExportableTable;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * The one admin place for AI Response automation: the master ON/OFF switch, a few numbers, the historical
 * backlog and an activity log. Knowledge Library and Response Playbooks sit beside it in the same group.
 */
class AiResponseAutomation extends Page implements ExportableTable, HasTable
{
    use HasPageTableExportActions;
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'AI Response Automation';

    protected static ?string $navigationLabel = 'AI Automations';

    protected static ?string $title = 'AI Response Automation';

    protected static ?string $slug = 'ai-response-automation';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.admin.pages.ai-response-automation';

    protected ?string $maxContentWidth = MaxWidth::SevenExtraLarge->value;

    public string $testArea = 'vehicle';

    public string $testItem = '';

    public string $testCondition = 'missing';

    public string $testText = '';

    public ?string $testResult = null;

    public ?string $testReason = null;

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
                    ->title('AI automations are OFF')
                    ->body($result['suppressed'] > 0 ? "Stopped {$result['suppressed']} queued responses." : null)
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
            ->modalHeading('Turn AI automations ON?')
            ->modalDescription('New submissions in the three areas will get a short AI Response. Older submissions are only answered when you press Process Pending Backlog.')
            ->modalSubmitActionLabel('Turn ON')
            ->authorize(fn (): bool => self::canAccess())
            ->disabled(fn (): bool => app(AiAutomationSwitch::class)->hardDisabled())
            ->action(function (): void {
                app(AiAutomationSwitch::class)->turnOn($this->actor());
                Notification::make()->title('AI automations are ON')->success()->send();
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

                return "Up to {$count} older submissions get one short AI Response each, in small batches. No email and no push notifications. Turning AI automations OFF stops it immediately.";
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
                    ->body($started ? null : 'Turn AI automations ON and preview the backlog first.')
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

    /** Synthetic preview only: nothing is written, nothing is sent, no model is called. */
    public function runTestResponse(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->validate([
            'testArea' => ['required', 'in:vehicle,station,support'],
            'testItem' => ['nullable', 'string', 'max:120'],
            'testCondition' => ['required', 'in:missing,damaged,other'],
            'testText' => ['nullable', 'string', 'max:600'],
        ]);

        $facts = match ($this->testArea) {
            'vehicle' => new IntakeFacts('vehicle', 'inspection_findings', null,
                [new Finding($this->testItem !== '' ? $this->testItem : 'Item', $this->testCondition, false, true, $this->testText ?: null)]),
            'station' => new IntakeFacts('station', 'station_request', null, [], $this->testText ?: null, null, false, null, true),
            default => new IntakeFacts('support', 'support_message', null, [], $this->testText ?: null),
        };

        $reply = app(ResponseComposer::class)->compose($facts, CarbonImmutable::now(), false);
        $this->testResult = $reply->text;
        $this->testReason = Str::headline($reply->decision).' · '.$reply->riskTier.' · '.implode(', ', $reply->reasonCodes);
    }

    public function table(Table $table): Table
    {
        return self::withExportActions($table
            ->query(AiIntakeEvent::query())
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('domain')->label('Area')->formatStateUsing(fn (string $state): string => match ($state) {
                    'vehicle' => 'Vehicle',
                    'station' => 'Station request',
                    'support' => 'Support Services',
                    default => $state,
                }),
                TextColumn::make('source_type')->label('Record')
                    ->formatStateUsing(fn (AiIntakeEvent $record): string => Str::headline((string) $record->source_type).' #'.$record->source_id),
                TextColumn::make('origin')->badge()->color(fn (string $state): string => $state === 'backlog' ? 'warning' : 'gray'),
                TextColumn::make('state')->badge()->color(fn (string $state): string => match ($state) {
                    'sent' => 'success',
                    'failed' => 'danger',
                    'cancelled' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('reply_text')->label('AI Response')->wrap()->placeholder('Nothing sent')->limit(140),
                TextColumn::make('risk_tier')->label('Risk')->badge()->color(fn (?string $state): string => match ($state) {
                    'critical' => 'danger',
                    'elevated' => 'warning',
                    default => 'gray',
                })->placeholder('-'),
                TextColumn::make('reason_codes')->label('Why')
                    ->formatStateUsing(fn (AiIntakeEvent $record): string => collect($record->reason_codes ?? [])
                        ->map(fn (string $code): string => Str::of($code)->replace('_', ' ')->toString())
                        ->push($record->last_error ? Str::of($record->last_error)->replace('_', ' ')->toString() : null)
                        ->filter()->implode(', '))
                    ->wrap()->toggleable(),
                TextColumn::make('template_key')->label('Playbook')
                    ->formatStateUsing(fn (AiIntakeEvent $record): string => $record->template_key
                        ? Str::headline((string) $record->template_key).' v'.(int) $record->template_version
                        : ($record->knowledge_refs ? 'Knowledge Library' : '-'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('needs_human')->label('Staff action')
                    ->formatStateUsing(fn (): string => 'Still with staff')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->options([
                    'sent' => 'Sent', 'skipped' => 'Skipped', 'cancelled' => 'Stopped by OFF',
                    'failed' => 'Failed', 'queued' => 'Queued', 'retry' => 'Retrying',
                ]),
                SelectFilter::make('domain')->label('Area')->options([
                    'vehicle' => 'Vehicle', 'station' => 'Station request', 'support' => 'Support Services',
                ]),
                SelectFilter::make('origin')->options(['live' => 'Live', 'backlog' => 'Backlog']),
            ]));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $switch = app(AiAutomationSwitch::class);
        $today = CarbonImmutable::now((string) config('ai_automation.timezone'))->startOfDay()->utc();
        $todayRows = AiIntakeEvent::query()->where('updated_at', '>=', $today);

        return [
            'isOn' => $switch->isOn(),
            'hardDisabled' => $switch->hardDisabled(),
            'stats' => [
                'sent' => (clone $todayRows)->where('state', AiIntakeEvent::STATE_SENT)->count(),
                'flagged' => (clone $todayRows)->where('state', AiIntakeEvent::STATE_SENT)->whereIn('risk_tier', ['critical', 'elevated'])->count(),
                'skipped' => (clone $todayRows)->where('state', AiIntakeEvent::STATE_SKIPPED)->count(),
                'stopped' => (clone $todayRows)->where('state', AiIntakeEvent::STATE_CANCELLED)->count(),
                'failed' => (clone $todayRows)->where('state', AiIntakeEvent::STATE_FAILED)->count(),
                'queued' => AiIntakeEvent::query()->whereIn('state', AiIntakeEvent::OPEN_STATES)->count(),
            ],
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
