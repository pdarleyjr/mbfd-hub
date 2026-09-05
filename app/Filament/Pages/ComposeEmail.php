<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/** @property Form $form */
final class ComposeEmail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Compose';

    protected static string $view = 'filament.pages.compose-email';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill([
            'to' => request()->query('to'),
            'subject' => request()->query('subject'),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.communications.send') ?? false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('to')
                ->label('To')
                ->helperText('Comma-separated addresses; each unique destination consumes one budget unit.')
                ->required(),
            Forms\Components\TextInput::make('reply_to')->email(),
            Forms\Components\TextInput::make('subject')->required()->maxLength(998),
            Forms\Components\Textarea::make('text')->label('Message')->required()->rows(14),
            Forms\Components\FileUpload::make('attachments')
                ->multiple()
                ->storeFiles(false)
                ->maxFiles((int) config('communications.cloudflare.max_attachments', 5))
                ->maxSize((int) ceil((int) config('communications.cloudflare.max_attachment_bytes', 3500000) / 1024))
                ->acceptedFileTypes((array) config('communications.allowed_attachment_mime_types', []))
                ->helperText('Up to 3.5 MiB total. Files stay private and only validated types are accepted.'),
        ])->statePath('data');
    }

    public function send(CloudflareEmailDispatcher $dispatcher): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $state['to']))));
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $attachments = collect($state['attachments'] ?? [])->map(function (mixed $file): array {
                if (! $file instanceof TemporaryUploadedFile) {
                    throw new RuntimeException('An uploaded attachment is unavailable.');
                }
                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    throw new RuntimeException('An uploaded attachment could not be read.');
                }

                return [
                    'filename' => $file->getClientOriginalName(),
                    'type' => $file->getMimeType(),
                    'content' => base64_encode($content),
                ];
            })->values()->all();
            $dispatcher->send(
                to: $recipients,
                subject: (string) $state['subject'],
                text: (string) $state['text'],
                html: null,
                sourceType: 'admin_compose',
                actor: $actor,
                replyTo: filled($state['reply_to'] ?? null) ? (string) $state['reply_to'] : null,
                attachments: $attachments,
            );
        } catch (Throwable) {
            Notification::make()->danger()->title('Email not sent')
                ->body('The request failed closed. Review Communications Usage and the Sent ledger.')->send();

            return;
        }

        $this->form->fill();
        Notification::make()->success()->title('Email accepted by Cloudflare')->send();
    }
}
