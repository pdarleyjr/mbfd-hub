<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Filament\Resources\ImportResource;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Models\ImportBatch;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\ImportSubmissionService;
use Mbfd\PolicyLibrary\Services\LibraryManagementService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Support\LibraryAccess;

/**
 * @property Form $form
 */
final class ManualBuilder extends Page
{
    protected static string $view = 'policy-library::admin.manual-builder';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 0;

    protected ?string $maxContentWidth = 'full';

    protected ?string $subheading = 'Organize manuals, maintain protocols and publish validated revisions.';

    public ?int $manualId = null;

    public ?int $editionId = null;

    public ?int $nodeId = null;

    public array $expanded = [];

    public ?array $data = [];

    public static function getSlug(): string
    {
        return '';
    }

    public static function getRelativeRouteName(): string
    {
        return 'manual-builder';
    }

    public static function canAccess(): bool
    {
        return LibraryAccess::canManage(auth('web')->user());
    }

    public function mount(): void
    {
        $this->authorizeManager();
        $first = Manual::query()->orderBy('sort_order')->orderBy('id')->first();
        if ($first) {
            $this->selectManual($first->id);
        }
    }

    public function selectManual(int $id): void
    {
        $this->authorizeManager();
        $manual = Manual::query()->findOrFail($id);
        $this->manualId = $manual->id;
        $this->editionId = $manual->active_edition_id ?? $manual->editions()->latest('id')->value('id');
        $this->nodeId = null;
        $this->expanded = $manual->nodes()->where('edition_id', $this->editionId)->whereNull('parent_id')->where('type', 'section')->pluck('id')->all();
        $this->form->fill($manual->only(['name', 'type', 'description', 'is_active']));
    }

    public function selectEdition(int $id): void
    {
        $this->authorizeManager();
        $edition = $this->manual()->editions()->findOrFail($id);
        $this->editionId = $edition->id;
        $this->nodeId = null;
        $this->expanded = $edition->nodes()->whereNull('parent_id')->where('type', 'section')->pluck('id')->all();
        $this->form->fill($this->manual()->only(['name', 'type', 'description', 'is_active']));
    }

    public function selectNode(int $id): void
    {
        $this->authorizeManager();
        $node = $this->edition()->nodes()->findOrFail($id);
        $this->nodeId = $node->id;
        $parent = $node->parent;
        while ($parent) {
            $this->expanded[] = $parent->id;
            $parent = $parent->parent;
        }
        $this->expanded = array_values(array_unique($this->expanded));
        $this->form->fill($node->only(['title', 'short_title', 'type', 'parent_id', 'is_active']));
    }

    public function toggleSection(int $id): void
    {
        $this->authorizeManager();
        $this->edition()->nodes()->where('type', 'section')->findOrFail($id);
        $this->expanded = in_array($id, $this->expanded, true) ? array_values(array_diff($this->expanded, [$id])) : [...$this->expanded, $id];
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema($this->nodeId ? [
            Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\TextInput::make('short_title')->maxLength(255),
            Forms\Components\Select::make('type')->options(['section' => 'Section / Subsection', 'document' => 'Document / Protocol'])->disabled()->dehydrated(false),
            Forms\Components\Select::make('parent_id')->label('Parent section')->options(fn () => $this->parentOptions())->placeholder('Manual root')->searchable()->nullable()->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')->label('Available to members')->helperText('Hidden sections hide everything beneath them.')->columnSpanFull(),
        ] : [
            Forms\Components\TextInput::make('name')->label('Manual name')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Select::make('type')->options(['sog' => 'SOGs', 'medical' => 'Medical Protocols', 'other' => 'Other manual'])->required(),
            Forms\Components\Toggle::make('is_active')->label('Available to members'),
            Forms\Components\Textarea::make('description')->maxLength(4000)->rows(3)->columnSpanFull(),
        ])->columns(2)->disabled(fn () => $this->nodeId && $this->edition()->state === 'archived');
    }

    public function save(): void
    {
        $this->authorizeManager();
        $data = $this->form->getState();
        $service = app(LibraryManagementService::class);
        try {
            if ($this->nodeId) {
                $service->updateNode($this->node(), $data, auth('web')->id());
            } else {
                $service->updateManual($this->manual(), $data, auth('web')->id());
            }
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
        $this->notify('Changes saved');
    }

    public function reorderNode(int $moving, int $before): void
    {
        $this->authorizeManager();
        $nodes = $this->edition()->nodes();
        app(LibraryManagementService::class)->reorderNode((clone $nodes)->findOrFail($moving), (clone $nodes)->findOrFail($before), auth('web')->id());
    }

    public function moveSelected(int $direction): void
    {
        $this->authorizeManager();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $node = $this->node();
        $siblings = $node->edition->nodes()->where('parent_id', $node->parent_id)->orderBy('sort_order')->orderBy('id')->get();
        $index = $siblings->search(fn ($sibling) => $sibling->id === $node->id);
        $next = $siblings->get($index + $direction);
        if ($next) {
            app(LibraryManagementService::class)->reorderNode($direction === -1 ? $node : $next, $direction === -1 ? $next : $node, auth('web')->id());
        }
    }

    public function reorderManual(int $moving, int $before): void
    {
        $this->authorizeManager();
        app(LibraryManagementService::class)->reorderManual(Manual::query()->findOrFail($moving), Manual::query()->findOrFail($before), auth('web')->id());
    }

    public function moveManual(int $direction): void
    {
        $this->authorizeManager();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $manual = $this->manual();
        $manuals = Manual::query()->orderBy('sort_order')->orderBy('id')->get();
        $index = $manuals->search(fn ($item) => $item->id === $manual->id);
        $next = $manuals->get($index + $direction);
        if ($next) {
            app(LibraryManagementService::class)->reorderManual($direction === -1 ? $manual : $next, $direction === -1 ? $next : $manual, auth('web')->id());
        }
    }

    public function addManualAction(): Action
    {
        return Action::make('addManual')->label('Add Manual')->icon('heroicon-o-plus')->form([
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\Select::make('type')->options(['sog' => 'SOGs', 'medical' => 'Medical Protocols', 'other' => 'Other manual'])->default('other')->required(),
            Forms\Components\Textarea::make('description')->maxLength(4000),
        ])->action(function (array $data): void {
            $this->authorizeManager();
            $manual = app(LibraryManagementService::class)->createManual($data, auth('web')->id());
            $this->selectManual($manual->id);
            $this->notify('Manual created', 'Add sections and documents, then publish the working edition.');
        });
    }

    public function addSectionAction(): Action
    {
        return $this->addNodeAction('addSection', 'Add Section', 'section', false);
    }

    public function addSubsectionAction(): Action
    {
        return $this->addNodeAction('addSubsection', 'Add Subsection', 'section', true)->disabled(fn () => ! $this->nodeId || $this->node()->type !== 'section' || $this->edition()->state === 'archived');
    }

    public function addDocumentAction(): Action
    {
        return $this->addNodeAction('addDocument', 'Add Document', 'document', true);
    }

    private function addNodeAction(string $name, string $label, string $type, bool $nested): Action
    {
        return Action::make($name)->label($label)->color('gray')->icon('heroicon-o-plus')->disabled(fn () => ! $this->editionId || $this->edition()->state === 'archived')
            ->form([
                Forms\Components\TextInput::make('title')->required()->maxLength(255),
                Forms\Components\Select::make('parent_id')->label('Parent section')->options(fn () => $this->parentOptions(false))->placeholder('Manual root')->searchable()->nullable()
                    ->default(fn () => $nested && $this->nodeId ? ($this->node()->type === 'section' ? $this->nodeId : $this->node()->parent_id) : null),
            ])->action(function (array $data) use ($type): void {
                $this->authorizeManager();
                $node = app(LibraryManagementService::class)->createNode($this->edition(), $data + ['type' => $type], auth('web')->id());
                $this->selectNode($node->id);
                $this->notify($type === 'document' ? 'Document added. Upload its PDF below.' : 'Section added');
            });
    }

    public function archiveAction(): Action
    {
        return Action::make('archive')->label('Remove from library / Archive')->color('gray')->icon('heroicon-o-archive-box')->disabled(fn () => $this->nodeId && $this->edition()->state === 'archived')
            ->action(function (): void {
                $this->authorizeManager();
                $service = app(LibraryManagementService::class);
                if ($this->nodeId) {
                    $service->archiveNode($this->node(), auth('web')->id());
                    $this->selectNode($this->nodeId);
                } else {
                    $service->archiveManual($this->manual(), auth('web')->id());
                    $this->selectManual($this->manualId);
                }
                $this->notify('Archived', 'Removed from member navigation. History is preserved; enable Available to members to restore it.');
            });
    }

    public function deleteDraftAction(): Action
    {
        return Action::make('deleteDraft')->label('Permanently delete unused draft')->color('danger')->requiresConfirmation()
            ->modalDescription('Only this unused draft and its empty structure will be deleted. This cannot be undone.')
            ->visible(fn () => $this->manualId && ($this->nodeId ? app(LibraryManagementService::class)->canDeleteNode($this->node()) : app(LibraryManagementService::class)->canDeleteManual($this->manual())))
            ->action(function (): void {
                $this->authorizeManager();
                if ($this->nodeId) {
                    app(LibraryManagementService::class)->deleteNode($this->node(), auth('web')->id());
                    $this->selectManual($this->manualId);
                } else {
                    app(LibraryManagementService::class)->deleteManual($this->manual(), auth('web')->id());
                    $this->manualId = $this->editionId = $this->nodeId = null;
                    $this->mount();
                }
                $this->notify('Unused draft deleted');
            });
    }

    public function uploadPdfAction(): Action
    {
        return Action::make('uploadPdf')->label('Upload / Replace PDF')->icon('heroicon-o-arrow-up-tray')->form([
            $this->pdfUpload(), Forms\Components\TextInput::make('version_label')->maxLength(255),
            Forms\Components\DatePicker::make('revision_date'), Forms\Components\Textarea::make('revision_notes')->maxLength(4000),
        ])->action(function (array $data): void {
            $this->authorizeDocument();
            $file = $this->upload($data, 'pdf');
            app(RevisionService::class)->createDraft($this->node(), $file->getRealPath(), $file->getClientOriginalName(), auth('web')->id(), array_intersect_key($data, array_flip(['version_label', 'revision_date', 'revision_notes'])));
            $this->notify('PDF draft ready', 'Preview the draft, then publish. The current PDF is preserved.');
        });
    }

    public function replacePagesAction(): Action
    {
        return Action::make('replacePages')->label('Replace page(s)')->color('gray')->icon('heroicon-o-document-duplicate')->disabled(fn () => ! $this->nodeId || ! $this->node()->current_revision_id)
            ->form([
                Forms\Components\TextInput::make('start')->label('First page')->integer()->minValue(1)->default(1)->required(),
                Forms\Components\TextInput::make('end')->label('Last page')->integer()->minValue(1)->default(1)->required(), $this->pdfUpload(),
            ])->action(function (array $data): void {
                $this->authorizeDocument();
                $file = $this->upload($data, 'pdf');
                $revision = $this->node()->currentRevision;
                abort_unless($revision !== null, 404);
                app(RevisionService::class)->replaceRange($revision, (int) $data['start'], (int) $data['end'], $file->getRealPath(), $file->getClientOriginalName(), auth('web')->id());
                $this->notify('Page replacement draft ready', 'Preview the new PDF, then publish. The current revision is preserved.');
            });
    }

    public function publishRevisionAction(): Action
    {
        return Action::make('publishRevision')->label('Publish / Roll back')->requiresConfirmation()->modalDescription('Switch this document to the selected revision. Its other revisions remain in history.')
            ->action(function (array $arguments): void {
                $this->authorizeDocument();
                $revision = $this->node()->revisions()->findOrFail((int) ($arguments['revision'] ?? 0));
                app(RevisionService::class)->publish($revision, auth('web')->id());
                $this->notify('Current revision updated');
            });
    }

    public function publishEditionAction(): Action
    {
        return Action::make('publishEdition')->label(fn () => $this->editionId && $this->edition()->state === 'archived' ? 'Restore edition' : 'Publish manual edition')->requiresConfirmation()
            ->modalDescription('All PDFs must pass validation. This switches the complete manual and preserves the previous edition.')
            ->action(function (): void {
                $this->authorizeManager();
                app(ImportService::class)->publish($this->edition(), auth('web')->id());
                $this->selectManual($this->manualId);
                $this->notify('Manual edition published');
            });
    }

    public function uploadManualAction(): Action
    {
        return Action::make('uploadManual')->label('Upload / Replace Manual')->color('gray')->icon('heroicon-o-arrow-up-tray')->form([
            Forms\Components\Select::make('kind')->label('Upload format')->options(['moms' => 'Complete MOMS PDF — map protocols', 'sog' => 'SOG PDF — map policy headers', 'pdf' => 'Complete PDF — keep as one document', 'package' => 'Prepared import ZIP (advanced)'])->required()
                ->default(fn () => $this->manualId ? match ($this->manual()->type) {
                    'medical' => 'moms', 'sog' => 'sog', default => 'pdf'
                } : 'moms')->live(),
            Forms\Components\FileUpload::make('file')->label('Manual file')->acceptedFileTypes(fn (Forms\Get $get) => $get('kind') === 'package' ? ['application/zip', 'application/x-zip-compressed'] : ['application/pdf'])
                ->maxSize(config('policy-library.max_upload_kb'))->storeFiles(false)->required(),
            Forms\Components\Placeholder::make('import_note')->label('Review before publishing')->content(fn () => ($this->manualId ? 'PDF uploads prepare a replacement edition of '.$this->manual()->name.'. ' : 'MOMS and SOG uploads create their mapped manuals. ').'Complete PDFs retain every page in one document. Analysis runs in the background; current documents stay available.'),
        ])->action(function (array $data): void {
            $this->authorizeManager();
            app(ImportSubmissionService::class)->submit($this->upload($data, 'file'), $data['kind'], auth('web')->id(), null, $this->manualId);
            $this->notify('Manual analysis queued', 'Follow the import status below. Review the resulting edition before publishing.');
        });
    }

    public function importSectionAction(): Action
    {
        return Action::make('importSection')->label('Import Section')->color('gray')->icon('heroicon-o-folder-arrow-down')
            ->disabled(fn () => ! $this->nodeId || $this->node()->type !== 'section' || $this->editionId !== $this->manual()->active_edition_id)
            ->form([
                Forms\Components\Select::make('kind')->label('Upload format')->options(['sog' => 'SOG section PDF — map policy headers', 'pdf' => 'Section PDF — keep as one document', 'package' => 'Prepared section ZIP (advanced)'])->required()
                    ->default(fn () => $this->manual()->type === 'sog' ? 'sog' : 'pdf')->live(),
                Forms\Components\FileUpload::make('file')->label('Section file')->acceptedFileTypes(fn (Forms\Get $get) => $get('kind') === 'package' ? ['application/zip', 'application/x-zip-compressed'] : ['application/pdf'])->maxSize(config('policy-library.max_upload_kb'))->storeFiles(false)->required(),
                Forms\Components\Placeholder::make('section_note')->label('Replacement scope')->content('This replaces only the selected section in a new manual edition. Its name and bookmark remain; all other sections are preserved. Review the draft before publishing.'),
            ])
            ->action(function (array $data): void {
                $this->authorizeManager();
                $node = $this->node();
                abort_unless($node->type === 'section' && $node->edition_id === $node->manual->active_edition_id, 422);
                app(ImportSubmissionService::class)->submit($this->upload($data, 'file'), $data['kind'], auth('web')->id(), $node->id);
                $this->notify('Section analysis queued', 'The current section stays available until you publish the replacement edition.');
            });
    }

    public function reviewImport(int $editionId): void
    {
        $this->authorizeManager();
        $edition = Edition::query()->findOrFail($editionId);
        $this->selectManual($edition->manual_id);
        $this->selectEdition($edition->id);
    }

    private function pdfUpload(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('pdf')->label('PDF document')->acceptedFileTypes(['application/pdf'])->maxSize(config('policy-library.max_upload_kb'))->storeFiles(false)->required();
    }

    private function upload(array $data, string $key): UploadedFile
    {
        $file = $data[$key] ?? null;
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([$key => 'Choose a file to upload.']);
        }

        return $file;
    }

    private function manual(): Manual
    {
        return Manual::query()->findOrFail($this->manualId);
    }

    private function edition(): Edition
    {
        return $this->manual()->editions()->findOrFail($this->editionId);
    }

    private function node(): ManualNode
    {
        return $this->edition()->nodes()->findOrFail($this->nodeId);
    }

    private function authorizeManager(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    private function authorizeDocument(): void
    {
        $this->authorizeManager();
        abort_unless($this->node()->type === 'document' && $this->edition()->state !== 'archived', 403);
    }

    private function parentOptions(bool $excludeSelected = true): array
    {
        if (! $this->editionId) {
            return [];
        }
        $nodes = $this->edition()->nodes()->with('parent')->get()->keyBy('id');
        $options = [];
        foreach ($nodes->where('type', 'section') as $node) {
            $path = [$node->title];
            $parent = $node;
            while ($parent) {
                if ($excludeSelected && $parent->id === $this->nodeId) {
                    continue 2;
                }
                $parent = $parent->parent_id ? $nodes->get($parent->parent_id) : null;
                if ($parent) {
                    array_unshift($path, $parent->title);
                }
            }
            $options[$node->id] = implode(' / ', $path);
        }

        return $options;
    }

    private function notify(string $title, ?string $body = null): void
    {
        Notification::make()->title($title)->body($body)->success()->send();
    }

    protected function getViewData(): array
    {
        $manual = $this->manualId ? $this->manual() : null;
        $edition = $this->editionId ? $this->edition() : null;
        $nodes = $edition?->nodes()->with('currentRevision')->orderBy('sort_order')->orderBy('id')->get() ?? collect();

        return ['manuals' => Manual::query()->orderBy('sort_order')->orderBy('id')->get(), 'manual' => $manual, 'edition' => $edition,
            'editions' => $manual?->editions()->latest('id')->get() ?? collect(), 'tree' => $nodes->groupBy('parent_id'),
            'selected' => $this->nodeId ? $this->node() : null,
            'revisions' => $this->nodeId ? $this->node()->revisions()->latest('id')->get() : collect(),
            'batches' => ImportBatch::query()->latest('id')->limit(5)->get(), 'importUrl' => ImportResource::getUrl('index', panel: 'policy-library')];
    }
}
