<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChecklistTemplateResource\Pages;

use App\Filament\Resources\ChecklistTemplateResource;
use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use App\Models\User;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Services\ChecklistTemplates\ChecklistTemplateException;
use App\Support\Fleet\ChecklistTemplateAccess;
use App\Support\HubTimezone;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\StaticAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * One Daily Checkout checklist template: compartments and items as a draft form, publish with a preview of
 * the change and its impact on every unit, import / export, revert to the repository file, and the version
 * history (docs/checklist-template-editor.md section 7). Every change goes through ChecklistTemplateEditor,
 * which checks the permission again; this page only decides what to show.
 *
 * @property Form $form
 */
class EditChecklistTemplate extends Page
{
    protected static string $resource = ChecklistTemplateResource::class;

    protected static string $view = 'filament.resources.checklist-template-resource.pages.edit-checklist-template';

    public const MAX_IMPORT_BYTES = 2 * 1024 * 1024;

    public const EVENT_LABELS = [
        'draft_started' => 'Draft started',
        'draft_saved' => 'Draft saved',
        'draft_imported' => 'JSON imported into the draft',
        'draft_restored' => 'Earlier version restored as the draft',
        'draft_discarded' => 'Draft discarded',
        'published' => 'Published',
        'reverted_to_file' => 'Reverted to the repository file',
    ];

    #[Locked]
    public string $type = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The open draft the form is bound to; null while the form shows the live checklist. */
    #[Locked]
    public ?int $draftId = null;

    /** The draft's lock_version when this page last loaded or saved it. */
    #[Locked]
    public int $lockVersion = 0;

    /** 'file' or 'published': where the live checklist comes from. */
    #[Locked]
    public string $source = 'file';

    #[Locked]
    public ?string $loadError = null;

    /** @var array<string, string> compartment id => name, as saved (the Location options) */
    #[Locked]
    public array $locationOptions = [];

    /** Fingerprint of the form as loaded or last saved, to know whether it has unsaved edits. */
    #[Locked]
    public string $savedFormHash = '';

    /** @var array<string, mixed> plain-text facts for the header card */
    #[Locked]
    public array $summary = [];

    /** @var array<string, mixed>|null the publish preview the admin is confirming (its fingerprint is sent back) */
    #[Locked]
    public ?array $publishPreview = null;

    /** @var array<string, mixed>|null the revert-to-file preview the admin is confirming */
    #[Locked]
    public ?array $revertPreview = null;

    public function mount(string $type): void
    {
        abort_unless(in_array($type, $this->editor()->types(), true), 404);
        abort_unless(ChecklistTemplateAccess::canView(auth()->user()), 403);
        $this->type = $type;
        $this->loadChecklist();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ChecklistTemplateAccess::canView(auth()->user());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->editor()->label($this->type);
    }

    public function getSubheading(): ?string
    {
        if ($this->draftId !== null) {
            return 'You are editing the draft. Members keep the published checklist until you publish.';
        }

        return $this->canManage()
            ? 'Showing the live checklist. Your first save starts a draft; members see nothing until you publish.'
            : 'Showing the live checklist (read only).';
    }

    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ChecklistTemplateResource::getUrl('index') => 'Checklist templates',
            $this->editor()->label($this->type),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canManage() || $this->loadError !== null)
            ->schema([
                Forms\Components\Repeater::make('compartments')
                    ->label('Compartments')
                    ->hiddenLabel()
                    ->addActionLabel('Add compartment')
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->collapsed()
                    ->cloneable(false)
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): string => self::compartmentLabel($state))
                    // Deleting a compartment takes its items with it: confirm first. Nothing reaches members
                    // until the draft is published, and the publish preview lists every removed item.
                    ->deleteAction(fn (Forms\Components\Actions\Action $action): Forms\Components\Actions\Action => $action
                        ->requiresConfirmation()
                        ->modalHeading('Delete this compartment?')
                        ->modalDescription('Its items are removed from the draft too. To keep an item, set its Location to another compartment and save the draft before deleting this one.')
                        ->modalSubmitActionLabel('Delete compartment'))
                    ->schema([
                        Forms\Components\Hidden::make('id'),
                        Forms\Components\TextInput::make('name')
                            ->label('Compartment name')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\Repeater::make('items')
                            ->label('Items')
                            ->addActionLabel('Add item')
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->cloneable(false)
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): string => filled($state['name'] ?? null) ? (string) $state['name'] : 'New item')
                            ->columns(['default' => 1, 'md' => 12])
                            ->schema([
                                Forms\Components\Hidden::make('id'),
                                Forms\Components\TextInput::make('name')
                                    ->label('Item name')
                                    ->required()
                                    ->maxLength(160)
                                    ->columnSpan(['md' => 6]),
                                Forms\Components\TextInput::make('expectedQuantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(999)
                                    ->nullable()
                                    ->columnSpan(['md' => 2]),
                                Forms\Components\Select::make('compartment_id')
                                    ->label('Location')
                                    ->options(fn (): array => $this->locationOptions)
                                    ->default(fn (Get $get): ?string => filled($get('../../id')) ? (string) $get('../../id') : null)
                                    ->placeholder('This compartment')
                                    ->helperText('To move an item into a new compartment, save the draft first.')
                                    ->columnSpan(['md' => 4]),
                                Forms\Components\TextInput::make('manufacturer')
                                    ->label('Brand / manufacturer')
                                    ->maxLength(120)
                                    ->columnSpan(['md' => 4]),
                                Forms\Components\TextInput::make('model')
                                    ->label('Model')
                                    ->maxLength(120)
                                    ->columnSpan(['md' => 4]),
                                Forms\Components\TextInput::make('description')
                                    ->label('Description')
                                    ->maxLength(500)
                                    ->columnSpan(['md' => 4]),
                                Forms\Components\TextInput::make('note')
                                    ->label('Note shown to members')
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label('Save draft')
                ->icon('heroicon-o-document-check')
                ->keyBindings(['mod+s'])
                ->visible(fn (): bool => $this->canEdit())
                ->action(fn () => $this->saveDraft()),
            $this->publishAction(),
            ActionGroup::make([
                Action::make('exportJson')
                    ->label('Export JSON')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => $this->loadError === null)
                    ->action(fn (): ?StreamedResponse => $this->exportJson()),
                $this->importAction(),
                $this->revertAction(),
                Action::make('discardDraft')
                    ->label('Discard draft')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canManage() && $this->draftId !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Discard the draft?')
                    ->modalDescription('Every change saved in the draft is deleted. Members keep the published checklist. The audit trail keeps a record that the draft was discarded.')
                    ->modalSubmitActionLabel('Discard draft')
                    ->action(function (): void {
                        $draft = $this->currentDraft();
                        if ($draft === null) {
                            $this->loadChecklist();

                            return;
                        }
                        if ($this->attempt(fn (User $user) => $this->editor()->discardDraft($draft, $user))) {
                            $this->loadChecklist();
                            Notification::make()->success()->title('Draft discarded')->body('The form shows the live checklist again.')->send();
                        }
                    }),
            ])->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Preview & publish')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(fn (): bool => $this->canEdit())
            ->modalHeading(fn (): string => 'Publish '.$this->editor()->label($this->type))
            ->modalDescription('Check every change and the units it reaches. Publishing updates the next Daily Checkout checklist for these units. Past inspections never change.')
            ->modalWidth(MaxWidth::FiveExtraLarge)
            ->mountUsing(function (Forms\ComponentContainer $form, Action $action): void {
                $this->publishPreview = null;
                if (! $this->preparePublishPreview()) {
                    $action->cancel();
                }
                $form->fill();
            })
            ->modalContent(fn (): View => view('filament.resources.checklist-template-resource.partials.preview', [
                'preview' => $this->publishPreview,
                'mode' => 'publish',
            ]))
            ->form([
                Forms\Components\Textarea::make('summary')
                    ->label('What changed and why')
                    ->helperText('Shown in the version history.')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(3),
                Forms\Components\Checkbox::make('confirm')
                    ->label(fn (): string => $this->publishConfirmation(count($this->publishPreview['units'] ?? [])))
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm that you checked the impact before publishing.']),
            ])
            ->modalSubmitActionLabel('Publish')
            ->modalSubmitAction(fn (StaticAction $action): StaticAction => $action->disabled(! ($this->publishPreview['publishable'] ?? false)))
            ->action(function (array $data, Action $action): void {
                $preview = $this->publishPreview;
                $draft = $this->currentDraft();
                if ($preview === null || ! ($preview['publishable'] ?? false) || $draft === null) {
                    Notification::make()->danger()->title('This draft cannot be published')
                        ->body('Fix the problems listed in the preview, save the draft and open the preview again.')->send();
                    $action->halt();
                }
                $version = null;
                $this->attempt(function (User $user) use ($draft, $data, $preview, &$version): void {
                    $version = $this->editor()->publish($draft, $user, trim((string) $data['summary']), (string) $preview['fingerprint'], $this->lockVersion);
                });
                $this->publishPreview = null;
                $this->loadChecklist();
                if ($version instanceof Version) {
                    $units = count($preview['units'] ?? []);
                    Notification::make()->success()
                        ->title('Published v'.$version->version_number)
                        ->body('The next Daily Checkout checklist for '.$units.' '.($units === 1 ? 'unit' : 'units').' uses this version.')
                        ->send();
                }
            });
    }

    private function importAction(): Action
    {
        return Action::make('importJson')
            ->label('Import JSON')
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading('Import a checklist into the draft')
            ->modalDescription('The draft is replaced by the imported checklist after it is validated. Unsaved edits in the form are lost. Members see nothing until you publish.')
            ->modalSubmitActionLabel('Import into draft')
            ->form([
                Forms\Components\FileUpload::make('file')
                    ->label('JSON file')
                    ->helperText('A .json file of up to 2 MB, for example one exported from this page.')
                    ->acceptedFileTypes(['application/json', 'text/json', 'text/plain'])
                    ->maxSize(2048)
                    ->storeFiles(false),
                Forms\Components\Textarea::make('json')
                    ->label('Or paste the JSON')
                    ->rows(8)
                    ->maxLength(self::MAX_IMPORT_BYTES),
            ])
            ->action(function (array $data, Action $action): void {
                $json = $this->importedJson($data);
                if ($json === null) {
                    Notification::make()->danger()->title('Nothing to import')->body('Choose a JSON file or paste the JSON.')->send();
                    $action->halt();
                }
                if (strlen($json) > self::MAX_IMPORT_BYTES) {
                    Notification::make()->danger()->title('The file is too large')->body('Import files are limited to 2 MB.')->send();
                    $action->halt();
                }
                $imported = $this->attempt(fn (User $user) => $this->editor()->importIntoDraft($this->type, $json, $user));
                if (! $imported) {
                    $action->halt();
                }
                $this->loadChecklist();
                Notification::make()->success()->title('Imported into the draft')->body('Review the draft, then publish it when it is right.')->send();
            });
    }

    private function revertAction(): Action
    {
        return Action::make('revertToFile')
            ->label('Revert to repository file')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (): bool => $this->canManage() && $this->source === 'published' && $this->loadError === null)
            ->modalHeading('Revert to the repository file')
            ->modalDescription('Members get the checklist from the repository file again. The published versions stay in the history and can be restored as a draft.')
            ->modalWidth(MaxWidth::FiveExtraLarge)
            ->mountUsing(function (Forms\ComponentContainer $form, Action $action): void {
                $this->revertPreview = null;
                try {
                    $this->revertPreview = $this->editor()->previewRevertToFile($this->type);
                } catch (ChecklistTemplateException $exception) {
                    Notification::make()->danger()->title('Cannot revert')->body($exception->getMessage())->persistent()->send();
                    $action->cancel();
                }
                $form->fill();
            })
            ->modalContent(fn (): View => view('filament.resources.checklist-template-resource.partials.preview', [
                'preview' => $this->revertPreview,
                'mode' => 'revert',
            ]))
            ->form([
                Forms\Components\Textarea::make('summary')
                    ->label('Why revert')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(3),
                Forms\Components\Checkbox::make('confirm')
                    ->label(fn (): string => $this->publishConfirmation(count($this->revertPreview['units'] ?? [])))
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm that you checked the impact before reverting.']),
            ])
            ->modalSubmitActionLabel('Revert to the file')
            ->modalSubmitAction(fn (StaticAction $action): StaticAction => $action->disabled(! ($this->revertPreview['publishable'] ?? false)))
            ->action(function (array $data, Action $action): void {
                $preview = $this->revertPreview;
                if ($preview === null || ! ($preview['publishable'] ?? false)) {
                    Notification::make()->danger()->title('The repository file cannot be published')->body('Fix the problems listed in the preview first.')->send();
                    $action->halt();
                }
                $done = $this->attempt(fn (User $user) => $this->editor()->revertToFile($this->type, $user, trim((string) $data['summary']), (string) $preview['fingerprint']));
                $this->revertPreview = null;
                $this->loadChecklist();
                if ($done) {
                    Notification::make()->success()->title('Reverted to the repository file')->body('Members get the repository checklist from their next Daily Checkout.')->send();
                }
            });
    }

    public function compareVersionAction(): Action
    {
        return Action::make('compareVersion')
            ->label('Compare with previous')
            ->icon('heroicon-o-arrows-right-left')
            ->link()
            ->modalHeading(fn (array $arguments): string => 'Changes in v'.($this->historyVersion($arguments)->version_number ?? '?'))
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments): View {
                $version = $this->historyVersion($arguments);
                $previous = $version !== null ? $this->previousVersion($version) : null;

                return view('filament.resources.checklist-template-resource.partials.diff', [
                    'diff' => $version !== null && $previous !== null ? $this->editor()->diff((array) $previous->checklist, (array) $version->checklist) : null,
                    'caption' => $previous !== null ? 'Compared with v'.$previous->version_number.'.' : 'No earlier published version to compare with.',
                ]);
            });
    }

    public function viewJsonAction(): Action
    {
        return Action::make('viewJson')
            ->label('View JSON')
            ->icon('heroicon-o-code-bracket')
            ->link()
            ->modalHeading(fn (array $arguments): string => 'v'.($this->historyVersion($arguments)->version_number ?? '?').' JSON')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments): View {
                $version = $this->historyVersion($arguments);

                return view('filament.resources.checklist-template-resource.partials.json', [
                    'json' => $version !== null ? $this->editor()->exportJson((array) $version->checklist) : '',
                ]);
            });
    }

    public function restoreVersionAction(): Action
    {
        return Action::make('restoreVersion')
            ->label('Restore as draft')
            ->icon('heroicon-o-arrow-path')
            ->link()
            ->color('warning')
            ->visible(fn (): bool => $this->canManage())
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Restore v'.($this->historyVersion($arguments)->version_number ?? '?').' as the draft?')
            ->modalDescription(fn (): string => ($this->draftId !== null ? 'The open draft is replaced by this version\'s checklist. ' : 'This version\'s checklist becomes the draft. ')
                .'Nothing changes for members until you publish it.')
            ->modalSubmitActionLabel('Restore as draft')
            ->action(function (array $arguments): void {
                $version = $this->historyVersion($arguments);
                if ($version === null) {
                    Notification::make()->danger()->title('That version was not found')->send();

                    return;
                }
                if ($this->attempt(fn (User $user) => $this->editor()->restoreAsDraft($version, $user))) {
                    $this->loadChecklist();
                    Notification::make()->success()->title('v'.$version->version_number.' restored as the draft')->body('Review it, then publish it when it is right.')->send();
                }
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'history' => $this->loadError === null ? $this->safely(fn (): Collection => $this->editor()->history($this->type), collect()) : collect(),
            'events' => $this->safely(fn (): Collection => $this->editor()->events($this->type, 25), collect()),
            'eventLabels' => self::EVENT_LABELS,
            'canManage' => $this->canManage(),
        ];
    }

    /** Header action and Ctrl/Cmd+S: validate, start a draft when needed, save, reload the saved draft. */
    public function saveDraft(): void
    {
        if ($this->persistDraft(throwFieldErrors: true)) {
            Notification::make()->success()->title('Draft saved')->body('Members see nothing until you publish.')->send();
        }
    }

    public function isDirty(): bool
    {
        return $this->formHash() !== $this->savedFormHash;
    }

    private function preparePublishPreview(): bool
    {
        if ($this->draftId === null && ! $this->isDirty()) {
            Notification::make()->warning()->title('Nothing to publish')
                ->body('There is no draft. Edit the checklist and save a draft first.')->send();

            return false;
        }
        if (($this->draftId === null || $this->isDirty()) && ! $this->persistDraft(throwFieldErrors: false)) {
            return false;
        }
        $draft = $this->currentDraft();
        if ($draft === null) {
            return false;
        }
        try {
            $this->publishPreview = $this->editor()->preview($draft);
        } catch (ChecklistTemplateException $exception) {
            Notification::make()->danger()->title('Cannot preview this draft')->body($exception->getMessage())->persistent()->send();

            return false;
        }

        return true;
    }

    private function persistDraft(bool $throwFieldErrors): bool
    {
        $user = $this->manager();
        try {
            $this->form->getState();
        } catch (ValidationException $exception) {
            $this->notifyInvalid(collect($exception->errors())->flatten()->map(fn ($message): string => (string) $message)->all());
            if ($throwFieldErrors) {
                throw $exception;
            }

            return false;
        }
        $raw = (array) $this->form->getRawState();
        $payload = self::formPayload($raw);

        try {
            if ($this->draftId !== null) {
                $draft = $this->currentDraft();
                if ($draft === null) {
                    Notification::make()->danger()->title('The draft was published or discarded by someone else')
                        ->body('Nothing was saved. Reload the page to see the current checklist; copy any edits you want to keep first.')
                        ->persistent()->send();

                    return false;
                }
                $lockVersion = $this->lockVersion;
            } else {
                $draft = $this->editor()->startDraft($this->type, $user);
                if (! $draft->wasRecentlyCreated && (int) $draft->created_by_user_id !== (int) $user->getKey()) {
                    Notification::make()->danger()->title('Someone else started a draft of this checklist')
                        ->body('Nothing was saved, so their draft is not overwritten. Reload the page to see their draft.')
                        ->persistent()->send();

                    return false;
                }
                $lockVersion = (int) $draft->lock_version;
            }
            $saved = $this->editor()->saveDraft($draft, $payload, $user, $lockVersion);
        } catch (ValidationException $exception) {
            $this->reportServerErrors($exception, $raw, $payload);

            return false;
        } catch (ChecklistTemplateException $exception) {
            Notification::make()->danger()->title('The draft was not saved')->body($exception->getMessage())->persistent()->send();

            return false;
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('You do not have permission to do this.')->body($exception->getMessage() ?: null)->send();

            return false;
        }

        $this->showDraft($saved);
        $this->summary = $this->summaryFor($this->type);

        return true;
    }

    private function exportJson(): ?StreamedResponse
    {
        try {
            $draft = $this->currentDraft();
            $checklist = $draft !== null ? (array) $draft->checklist : (array) $this->editor()->live($this->type)['checklist'];
            $json = $this->editor()->exportJson($checklist);
        } catch (ChecklistTemplateException $exception) {
            Notification::make()->danger()->title('Nothing to export')->body($exception->getMessage())->send();

            return null;
        }
        $name = $this->type.'-checklist-'.now(HubTimezone::NAME)->format('Y-m-d').'.json';

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $name, ['Content-Type' => 'application/json']);
    }

    /** @param array<string, mixed> $data */
    private function importedJson(array $data): ?string
    {
        $files = $data['file'] ?? null;
        foreach (is_array($files) ? $files : [$files] as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                if ($file->getSize() > self::MAX_IMPORT_BYTES) {
                    return str_repeat(' ', self::MAX_IMPORT_BYTES + 1);
                }
                $contents = $file->get();

                return is_string($contents) && trim($contents) !== '' ? $contents : null;
            }
        }
        $pasted = $data['json'] ?? null;

        return is_string($pasted) && trim($pasted) !== '' ? $pasted : null;
    }

    /** Load the open draft, or the live checklist when there is none, into the form. */
    public function loadChecklist(): void
    {
        $this->loadError = null;
        $this->publishPreview = null;
        $this->revertPreview = null;
        $this->summary = $this->summaryFor($this->type);
        $this->source = (string) ($this->summary['source'] ?? 'file');

        $draft = $this->editor()->draft($this->type);
        if ($draft !== null) {
            $this->showDraft($draft);

            return;
        }
        $this->draftId = null;
        $this->lockVersion = 0;
        try {
            $live = $this->editor()->live($this->type);
            $this->source = (string) $live['source'];
            $this->fillFrom((array) $live['checklist']);
        } catch (ChecklistTemplateException $exception) {
            $this->loadError = $exception->getMessage();
            $this->locationOptions = [];
            $this->form->fill(['compartments' => []]);
            $this->savedFormHash = $this->formHash();
        }
    }

    private function showDraft(Version $draft): void
    {
        $this->draftId = (int) $draft->getKey();
        $this->lockVersion = (int) $draft->lock_version;
        $this->fillFrom((array) $draft->checklist);
    }

    /** @param array<string, mixed> $checklist */
    private function fillFrom(array $checklist): void
    {
        $form = $this->editor()->toForm($checklist);
        $this->locationOptions = collect($form['compartments'])
            ->mapWithKeys(fn (array $compartment): array => [(string) $compartment['id'] => (string) $compartment['name']])
            ->all();
        $this->form->fill($form);
        $this->savedFormHash = $this->formHash();
    }

    /**
     * The toForm() shape from the form state: repeater keys dropped, blanks as null, a blank Location
     * meaning "the compartment it is listed in".
     *
     * @param  array<string, mixed>  $state
     * @return array{compartments: list<array{id: string, name: string, items: list<array<string, mixed>>}>}
     */
    public static function formPayload(array $state): array
    {
        $text = static fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        $compartments = [];
        foreach (array_values((array) ($state['compartments'] ?? [])) as $compartment) {
            $compartment = (array) $compartment;
            $compartmentId = (string) ($text($compartment['id'] ?? null) ?? '');
            $items = [];
            foreach (array_values((array) ($compartment['items'] ?? [])) as $item) {
                $item = (array) $item;
                $quantity = $item['expectedQuantity'] ?? null;
                $items[] = [
                    'id' => (string) ($text($item['id'] ?? null) ?? ''),
                    'name' => (string) ($text($item['name'] ?? null) ?? ''),
                    'expectedQuantity' => $quantity === null || $quantity === '' ? null : (is_numeric($quantity) ? (int) $quantity : $quantity),
                    'manufacturer' => $text($item['manufacturer'] ?? null),
                    'model' => $text($item['model'] ?? null),
                    'description' => $text($item['description'] ?? null),
                    'note' => $text($item['note'] ?? null),
                    'compartment_id' => $text($item['compartment_id'] ?? null) ?? $compartmentId,
                ];
            }
            $compartments[] = ['id' => $compartmentId, 'name' => (string) ($text($compartment['name'] ?? null) ?? ''), 'items' => $items];
        }

        return ['compartments' => $compartments];
    }

    private function formHash(): string
    {
        return hash('sha256', (string) json_encode(self::formPayload((array) $this->form->getRawState())));
    }

    /**
     * Put the server's index-based errors (compartments.2.items.5.name) on the fields they belong to, and
     * name them in a notification: a field inside a collapsed compartment is otherwise easy to miss.
     *
     * @param  array<string, mixed>  $raw
     * @param  array{compartments: list<array<string, mixed>>}  $payload
     */
    private function reportServerErrors(ValidationException $exception, array $raw, array $payload): void
    {
        $compartmentKeys = array_keys((array) ($raw['compartments'] ?? []));
        $messages = [];
        foreach ($exception->errors() as $key => $errors) {
            $message = (string) (collect($errors)->first() ?? 'Check this value.');
            $where = null;
            if (preg_match('/^compartments\.(\d+)(?:\.items\.(\d+))?(?:\.(\w+))?$/', (string) $key, $match) === 1) {
                $c = (int) $match[1];
                $i = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null;
                // Ids are hidden: an id problem ("listed twice") shows on the name.
                $field = ($match[3] ?? '') === '' || $match[3] === 'id' ? 'name' : $match[3];
                $compartmentKey = $compartmentKeys[$c] ?? null;
                $compartmentName = (string) ($payload['compartments'][$c]['name'] ?? '') ?: 'Compartment '.($c + 1);
                $where = $compartmentName;
                if ($i !== null) {
                    $itemKeys = $compartmentKey !== null ? array_keys((array) ($raw['compartments'][$compartmentKey]['items'] ?? [])) : [];
                    $itemName = (string) ($payload['compartments'][$c]['items'][$i]['name'] ?? '');
                    $where .= ' › item '.($i + 1).($itemName !== '' ? ' ('.$itemName.')' : '');
                    if ($compartmentKey !== null && isset($itemKeys[$i])) {
                        $this->addError('data.compartments.'.$compartmentKey.'.items.'.$itemKeys[$i].'.'.$field, $message);
                    }
                } elseif ($compartmentKey !== null) {
                    $this->addError('data.compartments.'.$compartmentKey.'.'.$field, $message);
                }
            }
            $messages[] = ($where !== null ? $where.': ' : '').$message;
        }
        $this->notifyInvalid($messages);
    }

    /** @param list<string> $messages */
    private function notifyInvalid(array $messages): void
    {
        $shown = array_slice(array_values(array_unique($messages)), 0, 6);
        $more = count($messages) - count($shown);
        Notification::make()->danger()->title('The draft was not saved')
            ->body(implode("\n", $shown).($more > 0 ? "\n…and {$more} more." : ''))
            ->persistent()
            ->send();
    }

    /** @param Closure(User): mixed $work */
    private function attempt(Closure $work): bool
    {
        $user = $this->manager();
        try {
            $work($user);
        } catch (ValidationException $exception) {
            $this->notifyFailure(collect($exception->errors())->flatten()->map(fn ($message): string => (string) $message)->all());

            return false;
        } catch (ChecklistTemplateException $exception) {
            Notification::make()->danger()->title('Nothing was changed')->body($exception->getMessage())->persistent()->send();

            return false;
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('You do not have permission to do this.')->body($exception->getMessage() ?: null)->send();

            return false;
        }

        return true;
    }

    /** @param list<string> $messages */
    private function notifyFailure(array $messages): void
    {
        $shown = array_slice(array_values(array_unique($messages)), 0, 6);
        Notification::make()->danger()->title('Nothing was changed')
            ->body($shown !== [] ? implode("\n", $shown) : 'Check the checklist and try again.')
            ->persistent()
            ->send();
    }

    private function publishConfirmation(int $units): string
    {
        return 'Publishing changes the checklist for '.$units.' '.($units === 1 ? 'unit' : 'units')
            .' immediately. Members with an inspection in progress keep their saved draft for officer review.';
    }

    /** @return array<string, mixed> plain strings for the header card, from the editor's overview */
    private function summaryFor(string $type): array
    {
        $entry = $this->safely(fn (): ?array => collect($this->editor()->overview())->firstWhere('type', $type), null);
        if (! is_array($entry)) {
            return [];
        }
        $published = $entry['published'];
        $draft = $entry['draft'];

        return [
            'source' => $entry['source'],
            'compartment_count' => $entry['compartment_count'],
            'item_count' => $entry['item_count'],
            'units' => array_map(static fn (array $unit): array => [
                'label' => $unit['label'],
                'daily_checkout' => $unit['daily_checkout'],
            ], $entry['units']),
            'published' => $published === null ? null : [
                'version_number' => $published['version_number'],
                'published_at' => ChecklistTemplateResource::when($published['published_at']),
                'published_by' => $published['published_by'],
                'change_summary' => $published['change_summary'],
            ],
            'draft' => $draft === null ? null : [
                'updated_at' => ChecklistTemplateResource::when($draft['updated_at']),
                'updated_by' => $draft['updated_by'],
                'has_changes' => $draft['has_changes'],
            ],
            'file_drift' => $entry['file_drift'],
            'file_error' => $entry['file_error'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function historyVersion(array $arguments): ?Version
    {
        $id = $arguments['version'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }

        return Version::query()
            ->where('checklist_type', $this->type)
            ->whereIn('status', ['published', 'retired'])
            ->find((int) $id);
    }

    private function previousVersion(Version $version): ?Version
    {
        return Version::query()
            ->where('checklist_type', $this->type)
            ->whereIn('status', ['published', 'retired'])
            ->whereNotNull('version_number')
            ->where('version_number', '<', (int) $version->version_number)
            ->orderByDesc('version_number')
            ->first();
    }

    private function currentDraft(): ?Version
    {
        if ($this->draftId === null) {
            return null;
        }

        return Version::query()
            ->where('checklist_type', $this->type)
            ->where('status', 'draft')
            ->find($this->draftId);
    }

    /** Compartment header: "Front Cab · 6 items". */
    public static function compartmentLabel(array $state): string
    {
        $name = filled($state['name'] ?? null) ? (string) $state['name'] : 'New compartment';
        $count = count((array) ($state['items'] ?? []));

        return $name.' · '.$count.' '.($count === 1 ? 'item' : 'items');
    }

    public function canManage(): bool
    {
        return ChecklistTemplateAccess::canManage(auth()->user());
    }

    private function canEdit(): bool
    {
        return $this->canManage() && $this->loadError === null;
    }

    private function manager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && ChecklistTemplateAccess::canManage($user), 403);

        return $user;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @param  T  $fallback
     * @return T
     */
    private function safely(Closure $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            report($exception);

            return $fallback;
        }
    }

    private function editor(): ChecklistTemplateEditor
    {
        return app(ChecklistTemplateEditor::class);
    }
}
