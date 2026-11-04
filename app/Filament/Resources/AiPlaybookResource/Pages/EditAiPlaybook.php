<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPlaybookResource\Pages;

use App\Filament\Resources\AiPlaybookResource;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\AiAutomation\AiAutomationPlaybookRevision;
use App\Models\User;
use App\Services\AiAutomation\Admin\ResponseMessageGroups;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\PlaybookRepository;
use App\Support\HubTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

final class EditAiPlaybook extends EditRecord
{
    protected static string $resource = AiPlaybookResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof AiAutomationPlaybook ? $record->label : 'Message';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restorePrevious')
                ->label('Restore previous')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => AiAutomationAccess::allowsCurrentUser() && $this->earlierVersions() !== [])
                ->modalHeading('Go back to earlier wording')
                ->modalDescription('The earlier wording is saved as the newest version, so nothing is lost.')
                ->modalSubmitActionLabel('Restore')
                ->form([
                    Select::make('revision_id')
                        ->label('Which version?')
                        ->options(fn (): array => $this->earlierVersions())
                        ->required()
                        ->live(),
                    Placeholder::make('revision_preview')
                        ->label('How it reads')
                        ->content(function (Get $get): HtmlString {
                            $revision = $this->revisionById($get('revision_id'));

                            return new HtmlString($revision === null
                                ? '<span class="text-sm text-gray-500">Choose a version to see its wording.</span>'
                                : '<pre class="whitespace-pre-wrap break-words text-sm">'.e(ResponseMessageGroups::preview((string) $revision->body)).'</pre>');
                        }),
                ])
                ->action(fn (array $data) => $this->restore($this->revisionById($data['revision_id'] ?? null))),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save');
    }

    /** @return array<int, string> revision id => plain label, newest first, without the version that is live */
    private function earlierVersions(): array
    {
        $playbook = $this->playbook();
        $options = [];
        foreach ($playbook->revisions()->get() as $revision) {
            if ((int) $revision->version === (int) $playbook->version) {
                continue;
            }
            $when = $revision->created_at?->timezone(HubTimezone::NAME)->format('M j, Y g:i A');
            $options[(int) $revision->getKey()] = ((int) $revision->version === 1 ? 'Original wording' : 'Saved '.$when).' by '.$revision->edited_by_name;
        }

        return $options;
    }

    private function revisionById(mixed $id): ?AiAutomationPlaybookRevision
    {
        if (! is_numeric($id)) {
            return null;
        }

        return AiAutomationPlaybookRevision::query()->where('playbook_id', $this->playbook()->getKey())->whereKey((int) $id)->first();
    }

    private function restore(?AiAutomationPlaybookRevision $revision): void
    {
        $editor = auth()->user();
        abort_unless($editor instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);
        if ($revision === null) {
            Notification::make()->warning()->title('Choose a version to go back to.')->send();

            return;
        }

        try {
            app(PlaybookRepository::class)->update($this->playbook(), (string) $revision->body, $editor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not restored')->body($e->getMessage())->send();

            return;
        }

        $this->playbook()->refresh();
        $this->fillForm();
        Notification::make()->success()->title('Restored. The next reply uses this wording.')->send();
    }

    private function playbook(): AiAutomationPlaybook
    {
        $record = $this->getRecord();
        abort_unless($record instanceof AiAutomationPlaybook, 404);

        return $record;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $editor = auth()->user();
        abort_unless($editor instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);
        abort_unless($record instanceof AiAutomationPlaybook, 404);

        try {
            return app(PlaybookRepository::class)->update($record, (string) ($data['body'] ?? ''), $editor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            $this->halt();

            return $record;
        }
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Saved. The next reply uses this wording.';
    }
}
