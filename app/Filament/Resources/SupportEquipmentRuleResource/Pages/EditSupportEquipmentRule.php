<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipmentRuleResource\Pages;

use App\Filament\Resources\SupportEquipmentRuleResource;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\SupportTriage\SupportEquipmentRuleRevision;
use App\Models\User;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Support\HubTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

final class EditSupportEquipmentRule extends EditRecord
{
    protected static string $resource = SupportEquipmentRuleResource::class;

    public function getTitle(): string
    {
        return $this->rule()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restorePrevious')
                ->label('Restore previous')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => AiAutomationAccess::allowsCurrentUser() && $this->earlierVersions() !== [])
                ->modalHeading('Go back to an earlier version')
                ->modalDescription('The earlier settings are saved as the newest version, so nothing is lost.')
                ->modalSubmitActionLabel('Restore')
                ->form([
                    Select::make('version')
                        ->label('Which version?')
                        ->options(fn (): array => $this->earlierVersions())
                        ->required()
                        ->live(),
                    Placeholder::make('version_preview')
                        ->label('What it said')
                        ->content(fn (Get $get): HtmlString => new HtmlString(e($this->summaryOf($get('version'))))),
                ])
                ->action(fn (array $data) => $this->restore((int) ($data['version'] ?? 0))),
        ];
    }

    /** @return array<int, string> version number => plain label, newest first, without the current version */
    private function earlierVersions(): array
    {
        $rule = $this->rule();
        $options = [];
        foreach ($rule->revisions()->get() as $revision) {
            if ((int) $revision->version === (int) $rule->version) {
                continue;
            }
            $options[(int) $revision->version] = 'Saved '.$revision->created_at?->timezone(HubTimezone::NAME)->format('M j, Y g:i A').' by '.$revision->edited_by_name;
        }

        return $options;
    }

    private function summaryOf(mixed $version): string
    {
        if (! is_numeric($version)) {
            return 'Choose a version to see its settings.';
        }
        $revision = SupportEquipmentRuleRevision::query()->where('rule_id', $this->rule()->getKey())->where('version', (int) $version)->first();
        if ($revision === null) {
            return 'Choose a version to see its settings.';
        }
        $s = $revision->snapshot;

        return 'Priority '.AdminLabels::level($s['level'] ?? null).'; handled by '.AdminLabels::handler($s['handler'] ?? null)
            .'; contact 300: '.mb_strtolower(AdminLabels::contact300($s['contact_300'] ?? null))
            .(filled($s['member_response'] ?? null) ? '; reply: "'.$s['member_response'].'"' : '; standard reply').'.';
    }

    private function restore(int $version): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        try {
            app(EquipmentRuleService::class)->restore($this->rule(), $version, $user);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not restored')->body((string) collect($e->errors())->flatten()->first())->send();

            return;
        }

        $this->rule()->refresh();
        $this->fillForm();
        Notification::make()->success()->title('Restored. It is used for the next report.')->send();
    }

    private function rule(): SupportEquipmentRule
    {
        $record = $this->getRecord();
        abort_unless($record instanceof SupportEquipmentRule, 404);

        return $record;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);
        abort_unless($record instanceof SupportEquipmentRule, 404);

        try {
            return app(EquipmentRuleService::class)->update($record, $data, $user);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
        }
    }

    protected function afterSave(): void
    {
        $this->rule()->refresh();
        $this->fillForm();
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Saved. It is used for the next report.';
    }
}
