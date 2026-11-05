<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipmentRuleResource\Pages;

use App\Filament\Resources\SupportEquipmentRuleResource;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\User;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Services\SupportTriage\TriageProjector;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

final class ListSupportEquipmentRules extends ListRecords
{
    protected static string $resource = SupportEquipmentRuleResource::class;

    public function getTitle(): string
    {
        return 'Equipment Priorities';
    }

    public function getSubheading(): string
    {
        return 'Choose how urgent each piece of equipment is. Tap Low, Medium or High on any row to change it right away. Every change is saved with its history.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('notClassified')
                ->label('Not classified yet')
                ->icon('heroicon-o-question-mark-circle')
                ->color('warning')
                ->url(fn (): string => SupportEquipmentRuleResource::getUrl('not-classified')),
            Action::make('applyToOpenIssues')
                ->label('Update open issues')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Update open issues to match these priorities?')
                ->modalDescription(fn (): string => $this->openIssuesSummary())
                ->modalSubmitActionLabel('Update them')
                ->authorize(fn (): bool => AiAutomationAccess::allowsCurrentUser())
                ->action(function (): void {
                    abort_unless(AiAutomationAccess::allowsCurrentUser(), 403);
                    $changed = app(TriageProjector::class)->reapply();
                    Notification::make()->success()
                        ->title($changed === 0 ? 'Open issues already match.' : $changed.' open '.($changed === 1 ? 'issue was' : 'issues were').' updated.')
                        ->body('Nobody was notified.')
                        ->send();
                }),
            CreateAction::make()->label('Add equipment'),
        ];
    }

    /** What the update would do, in words. Read only; counts every open issue once. */
    private function openIssuesSummary(): string
    {
        $preview = app(TriageProjector::class)->previewReapply();

        return 'Changing a priority here does not touch issues that are already open. This checks '.number_format($preview['open_records']).' open '
            .($preview['open_records'] === 1 ? 'issue' : 'issues').' and would change '.number_format($preview['changes']).'. '
            .'Priorities that staff set by hand ('.number_format($preview['overridden_kept']).') are kept, and nobody is notified.';
    }

    /** One tap on a row's Low, Medium or High. Goes through the same service as the edit form. */
    public function setRuleLevel(int $ruleId, string $level): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        $rule = SupportEquipmentRule::query()->findOrFail($ruleId);
        if ($rule->level === $level) {
            return;
        }

        try {
            app(EquipmentRuleService::class)->setLevel($rule, $level, $user);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not changed')->body((string) collect($e->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->success()->title($rule->name.' is now '.AdminLabels::level($level))->send();
    }
}
