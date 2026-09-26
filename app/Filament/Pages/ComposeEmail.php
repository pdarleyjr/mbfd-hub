<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Communications\EmailConversation;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
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

    public ?array $data = [];

    #[Locked]
    public ?int $sourceId = null;

    #[Locked]
    public ?string $sourceType = null;

    #[Locked]
    public string $mode = 'new';

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $initial = ['to' => [], 'cc' => [], 'bcc' => []];
        if (request()->filled('source')) {
            $this->sourceType = (string) request()->query('source');
            $this->sourceId = (int) request()->query('message');
            $this->mode = (string) request()->query('mode', 'reply');
            $initial = app(EmailConversation::class)->prefill($this->source(), $this->mode);
        }
        $this->form->fill($initial);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.communications.send') ?? false;
    }

    public function source(): InboundEmail|OutboundEmail|null
    {
        return $this->sourceId === null ? null : app(EmailConversation::class)->source((string) $this->sourceType, $this->sourceId, auth()->user());
    }

    public function recipientCount(): int
    {
        return count(app(EmailConversation::class)->addresses([...($this->data['to'] ?? []), ...($this->data['cc'] ?? []), ...($this->data['bcc'] ?? [])]));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TagsInput::make('to')->label('To')->placeholder('Email address')->required()->nestedRecursiveRules(['email:rfc'])->live()->helperText('Enter an address, then press Enter. Each unique recipient consumes one budget unit.'),
            Forms\Components\Section::make('Additional recipients')->collapsed()->schema([
                Forms\Components\TagsInput::make('cc')->label('CC')->placeholder('Email address')->nestedRecursiveRules(['email:rfc'])->live(),
                Forms\Components\TagsInput::make('bcc')->label('BCC')->placeholder('Email address')->nestedRecursiveRules(['email:rfc'])->live()->helperText('Hidden from other recipients and normal message views.'),
                Forms\Components\TextInput::make('reply_to')->label('Reply-To')->email(),
            ]),
            Forms\Components\TextInput::make('subject')->required()->maxLength(998),
            Forms\Components\Textarea::make('text')->label('Message')->required()->rows(14),
            Forms\Components\CheckboxList::make('forward_attachments')->label('Include original attachments')
                ->options(fn (): array => $this->source() ? app(EmailConversation::class)->attachmentOptions($this->source()) : [])
                ->visible(fn (): bool => $this->mode === 'forward')
                ->helperText('Only selected files are forwarded. Older sent attachments may need to be uploaded again.'),
            Forms\Components\FileUpload::make('attachments')->multiple()->storeFiles(false)
                ->maxFiles((int) config('communications.cloudflare.max_attachments', 5))
                ->maxSize((int) ceil((int) config('communications.cloudflare.max_attachment_bytes', 3500000) / 1024))
                ->acceptedFileTypes((array) config('communications.allowed_attachment_mime_types', []))
                ->helperText('Up to 3.5 MiB total, including selected original attachments. Files stay private.'),
            Forms\Components\Checkbox::make('confirm_large_send')->label('I have reviewed every recipient and want to send to this group.')
                ->visible(fn (): bool => $this->recipientCount() >= 5)->accepted(fn (): bool => $this->recipientCount() >= 5),
        ])->statePath('data');
    }

    public function send(CloudflareEmailDispatcher $dispatcher): void
    {
        abort_unless(self::canAccess(), 403);
        $source = $this->source(); // Reauthorize on every send, including direct Livewire requests.
        $state = $this->form->getState();
        if ($this->recipientCount() >= 5 && ! ($state['confirm_large_send'] ?? false)) {
            throw ValidationException::withMessages(['data.confirm_large_send' => 'Review and confirm the selected recipients.']);
        }
        /** @var User $actor */
        $actor = auth()->user();
        $conversation = app(EmailConversation::class);
        try {
            $attachments = collect($state['attachments'] ?? [])->map(function (mixed $file): array {
                if (! $file instanceof TemporaryUploadedFile || ($content = file_get_contents($file->getRealPath())) === false) {
                    throw new RuntimeException('An uploaded attachment is unavailable.');
                }

                return ['filename' => $file->getClientOriginalName(), 'type' => $file->getMimeType(), 'content' => base64_encode($content)];
            })->values()->all();
            if ($source && $this->mode === 'forward') {
                $attachments = [...$attachments, ...$conversation->attachments($source, $state['forward_attachments'] ?? [])];
            }
            $email = $dispatcher->send(
                to: $state['to'], subject: (string) $state['subject'], text: (string) $state['text'], html: null,
                sourceType: $source ? ($this->mode === 'forward' ? 'admin_forward' : 'admin_reply') : 'admin_compose',
                actor: $actor, cc: $state['cc'] ?? [], bcc: $state['bcc'] ?? [],
                replyTo: filled($state['reply_to'] ?? null) ? strtolower(trim($state['reply_to'])) : null,
                attachments: $attachments, headers: $source ? $conversation->headers($source, $this->mode) : [],
                parentOutboundEmailId: $source instanceof OutboundEmail ? $source->getKey() : null,
                parentInboundEmailId: $source instanceof InboundEmail ? $source->getKey() : null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            Notification::make()->danger()->title('Delivery could not be confirmed')
                ->body('Check Sent and Communications Usage before trying again; the provider may have accepted this message.')->send();

            return;
        }
        Notification::make()->success()->title($email->deliveryStatusLabel())->body('Open Sent for per-recipient delivery confirmation.')->send();
        $this->redirect(\App\Filament\Resources\OutboundEmailResource::getUrl('view', ['record' => $email]));
    }
}
