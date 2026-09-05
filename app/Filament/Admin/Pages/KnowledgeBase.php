<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\RagDocument;
use App\Services\KnowledgeBaseService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Admin Knowledge Base — lets admins upload documents (PDF/DOCX/TXT/MD/CSV)
 * that are ingested into the support chatbot's RAG index, plus list and
 * remove them. No need to ask an engineer to ingest files anymore.
 */
class KnowledgeBase extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'AI Knowledge Base';

    protected static ?string $title = 'AI Knowledge Base';

    protected static ?string $slug = 'knowledge-base';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.admin.pages.knowledge-base';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public bool $ingesting = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.system.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('document')
                    ->label('Upload a document')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain',
                        'text/markdown',
                        'text/csv',
                    ])
                    ->maxSize(20480) // 20 MB
                    ->storeFiles(false) // keep as a temporary upload; we ingest then discard
                    ->required()
                    ->helperText('PDF, DOCX, TXT, MD, or CSV (max 20 MB). The chatbot cites documents by filename. Re-uploading the same filename replaces it.'),
            ])
            ->statePath('data');
    }

    public function upload(): void
    {
        $this->ingesting = true;

        try {
            $state = $this->form->getState();
            $file = $state['document'] ?? null;
            // Filament FileUpload can return an array of uploaded files even for
            // a single upload — normalize to the first file.
            if (is_array($file)) {
                $file = reset($file) ?: null;
            }

            if (! $file) {
                Notification::make()->title('No file selected')->warning()->send();

                return;
            }

            $doc = app(KnowledgeBaseService::class)->ingest($file, auth()->id());

            Notification::make()
                ->title('Document added to the knowledge base')
                ->body("\"{$doc->filename}\" — {$doc->chunk_count} chunk(s) indexed. The chatbot can use it within a few seconds.")
                ->success()
                ->send();

            $this->form->fill();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Ingest failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->ingesting = false;
        }
    }

    public function deleteDocument(int $id): void
    {
        $doc = RagDocument::find($id);
        if (! $doc) {
            return;
        }

        $name = $doc->filename;
        try {
            app(KnowledgeBaseService::class)->delete($doc);
            Notification::make()
                ->title('Removed from knowledge base')
                ->body("\"{$name}\" and its indexed content were removed.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Removal failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return Collection<int, RagDocument>
     */
    public function getDocumentsProperty(): Collection
    {
        return RagDocument::with('uploader')->latest()->get();
    }
}
