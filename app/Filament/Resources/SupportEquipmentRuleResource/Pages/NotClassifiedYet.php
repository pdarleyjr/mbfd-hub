<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipmentRuleResource\Pages;

use App\Filament\Resources\SupportEquipmentRuleResource;
use App\Models\User;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\SupportTriage\EquipmentRuleService;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Checklist items nobody has given a priority yet, each with a one-tap Low, Medium or High.
 */
final class NotClassifiedYet extends Page
{
    protected static string $resource = SupportEquipmentRuleResource::class;

    protected static string $view = 'filament.admin.ai-automation.not-classified-yet';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(AiAutomationAccess::allowsCurrentUser(), 403);
    }

    public function getTitle(): string
    {
        return 'Not classified yet';
    }

    public function getSubheading(): string
    {
        return 'These checklist items have no priority. Until one is chosen they are treated as Medium so nobody misses them. Tap Low, Medium or High; you can fine-tune any of them afterwards.';
    }

    /** @return list<array{name: string, apparatus_types: list<string>, occurrences: int}> */
    public function items(): array
    {
        return app(EquipmentRuleService::class)->unclassifiedChecklistItems($this->search !== '' ? $this->search : null, 200);
    }

    public function classify(string $name, string $level): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        try {
            $rule = app(EquipmentRuleService::class)->classifyChecklistItem($name, $level, $user);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not saved')->body((string) collect($e->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->success()
            ->title($rule->name.' is now '.AdminLabels::level($level))
            ->actions([
                NotificationAction::make('open')->label('Fine-tune it')->url(SupportEquipmentRuleResource::getUrl('edit', ['record' => $rule]))->button(),
            ])
            ->send();
    }

    /** @return array<string, string> */
    public function truckLabels(): array
    {
        return AdminLabels::trucks();
    }
}
