<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationAccess;
use App\Services\CommandCommunications\CommandCommunicationService;
use App\Services\Security\Command300Access;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Administrator surface of the shared 300 Command / Captain 5 conversation. The 300 page tab,
 * the Captain 5 page tab and this page read and write the same records through
 * CommandCommunicationService; posts made here carry the "administration" side and choose
 * their audience (300 only, Captain 5 only or both). The feed filter narrows the view.
 *
 * @property Form $form
 */
final class CommandCommunications extends Page implements HasForms
{
    use InteractsWithForms;

    private const ATTACHMENT_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv',
    ];

    private const PAGE_STEP = 20;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = '300 Command Communications';

    protected static ?string $title = '300 Command Communications';

    protected static ?string $slug = 'command-communications';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.admin.pages.command-communications';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public string $search = '';

    public string $replyBody = '';

    #[Locked]
    public ?string $replyingTo = null;

    #[Locked]
    public string $composeToken = '';

    #[Locked]
    public string $replyToken = '';

    #[Locked]
    public int $loaded = self::PAGE_STEP;

    /** Feed filter: `all`, `command_300` or `captain_5`. */
    #[Locked]
    public string $filter = CommandCommunicationAccess::SCOPE_ALL;

    /** Public id of the message being removed (inline confirmation). */
    #[Locked]
    public ?string $deleting = null;

    public string $deleteReason = '';

    /**
     * ISO time of the administrator's previous visit per channel (null = never opened); posts
     * newer than a channel's marker are tagged "New".
     *
     * @var array<string, string|null>
     */
    #[Locked]
    public array $readCutoffs = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Inside the Admin Panel this page follows the Communications area's
        // capability like its neighbours, so scoped administrators (for example
        // Uniform & PPE managers) keep their narrow panel; they can still use
        // the 300 profile tab when eligible.
        return $user instanceof User
            && app(CommandCommunicationAccess::class)->isAdministrator($user)
            && app(Command300Access::class)->allows($user);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User || ! self::canAccess()) {
            return null;
        }
        $unread = app(CommandCommunicationService::class)->unreadCount($user, CommandCommunicationAccess::SCOPE_ALL);

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Unread 300 Command and Captain 5 messages';
    }

    public function mount(): void
    {
        $actor = $this->authorizeAccess();
        $service = $this->service();
        $this->readCutoffs = array_map(
            static fn ($readAt): ?string => $readAt?->toIso8601String(),
            $service->lastReadByChannel($actor, CommandCommunicationAccess::SCOPE_ALL),
        );
        $service->markRead($actor, CommandCommunicationAccess::SCOPE_ALL);
        $this->composeToken = (string) Str::uuid();
        $this->form->fill(['audience' => CommandCommunicationPost::AUDIENCE_COMMAND_300]);
    }

    /** Runs on every subsequent Livewire request, so property updates and uploads are re-authorized too. */
    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Radio::make('audience')->label('Send to')->inline()->inlineLabel(false)->required()
                ->options([
                    CommandCommunicationPost::AUDIENCE_COMMAND_300 => '300 Command only',
                    CommandCommunicationPost::AUDIENCE_CAPTAIN_5 => 'Captain 5 only',
                    CommandCommunicationPost::AUDIENCE_BOTH => 'Both',
                ])
                ->disableOptionWhen(fn (string $value): bool => $value !== CommandCommunicationPost::AUDIENCE_COMMAND_300
                    && ! app(CommandCommunicationAccess::class)->captain5Enabled())
                ->helperText('Captain 5 messages also alert the Captain 5 who is on duty.'),
            Textarea::make('body')->label('Message')->rows(4)->maxLength(10000)
                ->placeholder('Write a message')
                ->extraInputAttributes(['class' => 'min-h-[7rem]']),
            Section::make('Add a subject')->collapsible()->collapsed()->compact()->schema([
                TextInput::make('subject')->label('Subject')->maxLength(160),
            ]),
            FileUpload::make('attachments')->label('Attachments')->multiple()->storeFiles(false)
                ->maxFiles((int) config('command_communications.max_attachments', 5))
                ->maxSize((int) ceil((int) config('command_communications.max_attachment_bytes', 15 * 1024 * 1024) / 1024))
                ->acceptedFileTypes(self::ATTACHMENT_MIMES)
                ->helperText('Images (JPEG, PNG, WebP, GIF) and PDF, DOCX, XLSX, PPTX, TXT or CSV. Up to 5 files, 15 MB each. Files stay private.'),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $actor = $this->authorizeAccess();
        $state = $this->form->getState();
        $files = collect($state['attachments'] ?? [])
            ->filter(static fn (mixed $file): bool => $file instanceof TemporaryUploadedFile)
            ->values()->all();

        try {
            $this->service()->post(
                $actor,
                CommandCommunicationPost::CONTEXT_ADMINISTRATION,
                isset($state['subject']) ? (string) $state['subject'] : null,
                (string) ($state['body'] ?? ''),
                null,
                $this->composeToken,
                $files,
                (string) ($state['audience'] ?? CommandCommunicationPost::AUDIENCE_COMMAND_300),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(static fn (array $messages, string $key): array => ['data.'.$key => $messages])->all()
            );
        }

        $audience = (string) ($state['audience'] ?? CommandCommunicationPost::AUDIENCE_COMMAND_300);
        $this->composeToken = (string) Str::uuid();
        $this->form->fill(['audience' => $audience]); // keep the chosen audience for the next message
        $this->resetValidation();
        Notification::make()->title(match ($audience) {
            CommandCommunicationPost::AUDIENCE_CAPTAIN_5 => 'Posted to Captain 5',
            CommandCommunicationPost::AUDIENCE_BOTH => 'Posted to 300 Command and Captain 5',
            default => 'Posted to 300 Command',
        })->success()->send();
    }

    public function startReply(string $postId): void
    {
        $actor = $this->authorizeAccess();
        $post = $this->service()->findTopLevel($actor, $postId, CommandCommunicationAccess::SCOPE_ALL);
        abort_if($post === null || $post->isDeleted(), 404);
        $this->replyingTo = $postId;
        $this->replyBody = '';
        $this->replyToken = (string) Str::uuid();
        $this->resetValidation('replyBody');
    }

    public function cancelReply(): void
    {
        $this->authorizeAccess();
        $this->replyingTo = null;
        $this->replyBody = '';
        $this->resetValidation('replyBody');
    }

    public function sendReply(): void
    {
        $actor = $this->authorizeAccess();
        abort_if($this->replyingTo === null, 422);
        $this->validate(['replyBody' => ['required', 'string', 'max:'.(int) config('command_communications.body_max_length', 10000)]], [
            'replyBody.required' => 'Write a reply before sending.',
        ]);

        try {
            $this->service()->post(
                $actor,
                CommandCommunicationPost::CONTEXT_ADMINISTRATION,
                null,
                $this->replyBody,
                $this->replyingTo,
                $this->replyToken,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(static fn (array $messages, string $key): array => [$key === 'body' ? 'replyBody' : $key => $messages])->all()
            );
        }

        $this->replyingTo = null;
        $this->replyBody = '';
        Notification::make()->title('Reply posted')->success()->send();
    }

    public function togglePin(string $postId): void
    {
        $actor = $this->authorizeAccess();
        $post = $this->service()->findTopLevel($actor, $postId, CommandCommunicationAccess::SCOPE_ALL);
        abort_if($post === null, 404);
        $pinned = ! $post->isPinned();
        $this->service()->setPinned($actor, $post, $pinned, CommandCommunicationAccess::SCOPE_ALL);
        Notification::make()->title($pinned ? 'Post pinned' : 'Post unpinned')->success()->send();
    }

    public function setFilter(string $filter): void
    {
        $this->authorizeAccess();
        abort_unless(in_array($filter, [CommandCommunicationAccess::SCOPE_ALL, ...CommandCommunicationPost::CHANNELS], true), 422);
        $this->filter = $filter;
        $this->loaded = self::PAGE_STEP;
    }

    /** Opens the inline "remove this message" confirmation. */
    public function startDelete(string $postId): void
    {
        $actor = $this->authorizeAccess();
        $post = $this->service()->find($actor, $postId, CommandCommunicationAccess::SCOPE_ALL);
        abort_if($post === null || $post->isDeleted(), 404);
        abort_unless($this->service()->access()->canDelete($actor, $post), 403);
        $this->deleting = $postId;
        $this->deleteReason = '';
        $this->resetValidation('deleteReason');
    }

    public function cancelDelete(): void
    {
        $this->authorizeAccess();
        $this->deleting = null;
        $this->deleteReason = '';
        $this->resetValidation('deleteReason');
    }

    public function confirmDelete(): void
    {
        $actor = $this->authorizeAccess();
        abort_if($this->deleting === null, 422);
        $this->validate(['deleteReason' => ['required', 'string', 'min:3', 'max:500']], [
            'deleteReason.required' => 'Say why this message is being removed.',
            'deleteReason.min' => 'Say why this message is being removed.',
        ]);
        $post = $this->service()->find($actor, $this->deleting, CommandCommunicationAccess::SCOPE_ALL);
        abort_if($post === null, 404);

        try {
            $this->service()->delete($actor, $post, $this->deleteReason, CommandCommunicationAccess::SCOPE_ALL);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(static fn (array $messages, string $key): array => [$key === 'reason' ? 'deleteReason' : $key => $messages])->all()
            );
        }

        $this->deleting = null;
        $this->deleteReason = '';
        Notification::make()->title('Message removed')->success()->send();
    }

    public function loadMore(): void
    {
        $this->authorizeAccess();
        $this->loaded = min($this->loaded + self::PAGE_STEP, 500);
    }

    public function updatedSearch(): void
    {
        $this->authorizeAccess();
        $this->search = mb_substr(trim($this->search), 0, 100);
    }

    /** Poll target: re-authorizes, then records that the new posts were seen. */
    public function poll(): void
    {
        $this->service()->markRead($this->authorizeAccess(), CommandCommunicationAccess::SCOPE_ALL);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $actor = $this->authorizeAccess();
        $service = $this->service();
        // A channel can become unreadable while the page is open (e.g. the Captain 5 kill switch):
        // fall back to every readable channel instead of failing the page.
        if ($this->filter !== CommandCommunicationAccess::SCOPE_ALL && ! app(CommandCommunicationAccess::class)->canRead($actor, $this->filter)) {
            $this->filter = CommandCommunicationAccess::SCOPE_ALL;
        }
        $searching = mb_strlen(trim($this->search)) >= (int) config('command_communications.search_min_length', 2);
        $feed = $service->newest($actor, $this->loaded, $this->filter);

        return [
            'me' => $actor,
            'searching' => $searching,
            'results' => $searching ? $service->search($actor, $this->search, $this->filter) : collect(),
            'posts' => $feed['posts'],
            'pinned' => $feed['pinned'],
            'hasMore' => $feed['next_before'] !== null,
            'cutoffs' => $this->readCutoffs,
            'moderator' => $service->access()->canModerate($actor),
            'filter' => $this->filter,
            'captain5Enabled' => $service->access()->captain5Enabled(),
        ];
    }

    private function authorizeAccess(): User
    {
        abort_unless(self::canAccess(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function service(): CommandCommunicationService
    {
        return app(CommandCommunicationService::class);
    }
}
