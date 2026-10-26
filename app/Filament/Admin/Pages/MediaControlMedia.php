<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\HasDatasetExportActions;
use App\Models\User;
use App\Services\Exports\ExportDataset;
use App\Services\MediaControl\MediaControlContent;
use App\Services\MediaControl\MediaControlMediaClient;
use App\Services\MediaControl\MediaControlRequestFailed;
use App\Services\Security\SecurityAuditRecorder;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Upload images/videos into Media Control and manage the ones already there.
 *
 * The Hub never handles media bytes: requestGrant() asks Media Control for a
 * one-time upload grant (server side, permission checked, audited) and the
 * browser then sends the file straight to Media Control over tus.
 */
class MediaControlMedia extends Page
{
    use HasDatasetExportActions;

    protected function getHeaderActions(): array
    {
        return [$this->datasetExportAction()];
    }

    public function canExportData(User $actor): bool
    {
        return $actor->can(self::PERMISSION);
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        abort_unless((string) auth()->id() === (string) $actor->getKey() && $this->canExportData($actor), 403);

        return ExportDataset::fromRows($actor, 'Media Control Library',
            ['Media', 'Kind', 'Width', 'Height', 'Tags', 'Display layout', 'Category', 'Status', 'Uploaded by', 'Created (Eastern)'],
            array_map(fn (array $item): array => [
                $item['title'], $item['kind'], $item['width'], $item['height'], implode(', ', $item['tags']),
                $this->layoutLabel($item['display_span']), $item['category'], $item['processing_status'] ?? 'ready',
                $item['uploaded_by'], MediaControlContent::createdAt($item['created_at']),
            ], $this->items), 'Currently loaded media metadata',
            array_values(array_filter([$this->search === '' ? null : 'Search: '.$this->search, $this->kind === '' ? null : 'Kind: '.$this->kind])));
    }

    public const PERMISSION = 'app.media_control.media';

    private const SESSION_GRANTS = 'media_control.media.grants';

    private const PAGE_SIZE = 50;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Media Control';

    protected static ?string $title = 'Media Control media';

    protected static ?string $slug = 'media-control/media';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.admin.pages.media-control-media';

    #[Locked]
    public bool $configured = false;

    #[Locked]
    public bool $reachable = false;

    #[Locked]
    public ?string $statusMessage = null;

    #[Locked]
    public ?string $mcVersion = null;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $items = [];

    #[Locked]
    public ?string $nextCursor = null;

    #[Locked]
    public ?string $libraryError = null;

    public string $search = '';

    public string $kind = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->checkHealth();
        if ($this->reachable) {
            $this->loadLibrary();
        }
    }

    public function refreshStatus(): void
    {
        $this->authorizeAccess();
        $this->checkHealth();
        if ($this->reachable) {
            $this->loadLibrary();
        }
    }

    public function refreshLibrary(): void
    {
        $this->authorizeAccess();
        $this->loadLibrary();
    }

    public function loadMore(): void
    {
        $this->authorizeAccess();
        if ($this->nextCursor !== null) {
            $this->loadLibrary(append: true);
        }
    }

    public function updatedSearch(): void
    {
        $this->authorizeAccess();
        $this->search = mb_substr(trim($this->search), 0, 100);
        $this->loadLibrary();
    }

    public function updatedKind(): void
    {
        $this->authorizeAccess();
        if (! in_array($this->kind, ['', 'image', 'video'], true)) {
            $this->kind = '';
        }
        $this->loadLibrary();
    }

    /** @return list<string> */
    #[Computed]
    public function categories(): array
    {
        $categories = array_values(array_unique(array_filter(array_map(
            static fn (array $item): ?string => $item['category'] ?? null,
            $this->items,
        ))));
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

        return $categories;
    }

    /**
     * Called from the uploader (Alpine) for each file. Returns the grant the
     * browser needs to upload directly to Media Control, or a readable error.
     *
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    public function requestGrant(array $file): array
    {
        $actor = $this->authorizeAccess();
        $validator = Validator::make($file, [
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'mime' => ['required', 'string', Rule::in([...MediaControlContent::IMAGE_MIMES, ...MediaControlContent::VIDEO_MIMES])],
            'title' => ['nullable', 'string', 'max:120'],
            'display_span' => ['nullable', 'integer', 'between:1,5'],
            'category' => ['nullable', 'string', 'max:60'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
        ], [
            'mime.in' => 'Only JPEG, PNG, WebP or GIF images and MP4, WebM or MOV videos can be sent to Media Control.',
        ]);
        if ($validator->fails()) {
            return ['ok' => false, 'error' => $validator->errors()->first()];
        }
        $valid = $validator->validated();
        if (in_array($valid['mime'], MediaControlContent::IMAGE_MIMES, true) && (int) $valid['size'] > MediaControlContent::IMAGE_MAX_BYTES) {
            return ['ok' => false, 'error' => 'Images can be at most 200 MB.'];
        }

        $request = [
            'filename' => (string) $valid['filename'],
            'size' => (int) $valid['size'],
            'mime' => (string) $valid['mime'],
            'title' => $this->nullableText($valid['title'] ?? null),
            'display_span' => MediaControlContent::span($valid['display_span'] ?? null),
            'category' => $this->nullableText($valid['category'] ?? null),
            'tags' => MediaControlContent::tags($valid['tags'] ?? []),
        ];
        $auditMetadata = array_diff_key($request, ['tags' => true]);

        try {
            $grant = $this->client()->createGrant($actor, $request);
        } catch (MediaControlRequestFailed $exception) {
            $this->audit($actor, 'media_control.media.upload_granted', 'rejected', $auditMetadata + ['error_code' => $exception->errorCode, 'status' => $exception->status]);

            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        $grantId = is_string($grant['grant_id'] ?? null) ? $grant['grant_id'] : '';
        $uploadUrl = MediaControlContent::sameOriginUrl($grant['upload_url'] ?? null, $this->client()->origin());
        $uploadToken = is_string($grant['upload_token'] ?? null) ? $grant['upload_token'] : '';
        if (! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $grantId) || $uploadUrl === null || $uploadToken === '') {
            $this->audit($actor, 'media_control.media.upload_granted', 'rejected', $auditMetadata + ['error_code' => 'invalid_grant_response']);

            return ['ok' => false, 'error' => 'Media Control returned an unusable upload grant. Try again or report this issue.'];
        }

        $this->audit($actor, 'media_control.media.upload_granted', 'allowed', $auditMetadata + ['grant_id' => $grantId]);
        $grants = array_slice([...(array) session()->get(self::SESSION_GRANTS, []), $grantId], -50);
        session()->put(self::SESSION_GRANTS, $grants);

        $chunk = is_int($grant['chunk_size'] ?? null) ? $grant['chunk_size'] : 52428800;

        return [
            'ok' => true,
            'grant_id' => $grantId,
            'upload_url' => $uploadUrl,
            'upload_token' => $uploadToken,
            'chunk_size' => max(1024 * 1024, min($chunk, 100 * 1024 * 1024)),
            'max_bytes' => is_int($grant['max_bytes'] ?? null) ? $grant['max_bytes'] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function grantStatus(string $grantId): array
    {
        $actor = $this->authorizeAccess();
        $issued = (array) session()->get(self::SESSION_GRANTS, []);
        if (! in_array($grantId, $issued, true)) {
            return ['ok' => false, 'status' => 'unknown', 'error' => 'This upload was not started from this page.'];
        }

        try {
            $status = $this->client()->grantStatus($actor, $grantId);
        } catch (MediaControlRequestFailed $exception) {
            return ['ok' => false, 'status' => 'unknown', 'error' => $exception->getMessage(), 'retry' => $exception->kind === MediaControlRequestFailed::UNAVAILABLE];
        }

        $state = is_string($status['status'] ?? null) ? $status['status'] : 'unknown';
        $error = is_string($status['error'] ?? null) ? mb_substr($status['error'], 0, 300) : null;
        $contentId = is_string($status['content_id'] ?? null) ? $status['content_id'] : null;
        if (in_array($state, ['complete', 'failed', 'expired'], true)) {
            session()->put(self::SESSION_GRANTS, array_values(array_diff($issued, [$grantId])));
            $this->audit($actor, 'media_control.media.uploaded', $state === 'complete' ? 'allowed' : 'failed', [
                'grant_id' => $grantId, 'status' => $state, 'content_id' => $contentId, 'error' => $error,
            ]);
        }

        return ['ok' => true, 'status' => $state, 'error' => $error, 'content_id' => $contentId];
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->color('gray')
            ->authorize(fn (): bool => static::canAccess())
            ->modalHeading('Edit media details')
            ->modalSubmitActionLabel('Save in Media Control')
            ->fillForm(function (array $arguments): array {
                $item = $this->findItem((string) ($arguments['id'] ?? ''));

                return [
                    'title' => $item['title'] ?? '',
                    'display_span' => $item['display_span'] ?? null,
                    'category' => $item['category'] ?? null,
                    'tags' => $item['tags'] ?? [],
                ];
            })
            ->form([
                TextInput::make('title')->label('Title')->required()->maxLength(120),
                Select::make('display_span')->label('Display layout')
                    ->options(MediaControlContent::DISPLAY_LAYOUTS)
                    ->placeholder('Let Media Control decide')
                    ->helperText('How many side-by-side room displays this item is made for.'),
                TextInput::make('category')->label('Category')->maxLength(60)
                    ->datalist(fn (): array => $this->categories()),
                TagsInput::make('tags')->label('Tags')->placeholder('Add a tag')
                    ->nestedRecursiveRules(['max:40'])->rules(['array', 'max:10']),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->updateItem((string) ($arguments['id'] ?? ''), $data);
            });
    }

    public function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->iconButton()
            ->color('danger')
            ->authorize(fn (): bool => static::canAccess())
            ->requiresConfirmation()
            ->modalHeading('Archive this media?')
            ->modalDescription('It disappears from the Media Control library. Items in use on a display cannot be archived until they are removed there.')
            ->modalSubmitActionLabel('Archive')
            ->action(function (array $arguments): void {
                $this->archiveItem((string) ($arguments['id'] ?? ''));
            });
    }

    /** @return array<string, mixed> */
    public function uploaderConfig(): array
    {
        return [
            'imageMimes' => MediaControlContent::IMAGE_MIMES,
            'videoMimes' => MediaControlContent::VIDEO_MIMES,
            'imageMaxBytes' => MediaControlContent::IMAGE_MAX_BYTES,
            'layouts' => MediaControlContent::DISPLAY_LAYOUTS,
        ];
    }

    public function layoutLabel(?int $span): string
    {
        return MediaControlContent::layoutLabel($span);
    }

    /** @param array<string, mixed> $data */
    private function updateItem(string $contentId, array $data): void
    {
        $actor = $this->authorizeAccess();
        $before = $this->findItem($contentId);
        abort_if($before === null, 404);

        $changes = [
            'title' => trim((string) ($data['title'] ?? '')),
            'display_span' => MediaControlContent::span($data['display_span'] ?? null),
            'category' => $this->nullableText($data['category'] ?? null),
            'tags' => MediaControlContent::tags($data['tags'] ?? []),
        ];
        $auditBefore = array_intersect_key($before, $changes);

        try {
            $updated = $this->client()->update($actor, $contentId, $changes);
        } catch (MediaControlRequestFailed $exception) {
            $this->audit($actor, 'media_control.media.updated', 'rejected', ['content_id' => $contentId, 'error_code' => $exception->errorCode, 'status' => $exception->status]);
            Notification::make()->danger()->title('Media Control did not save the changes')->body($exception->getMessage())->send();

            return;
        }

        $this->audit($actor, 'media_control.media.updated', 'allowed', ['content_id' => $contentId, 'before' => $auditBefore, 'after' => $changes]);
        $normalized = MediaControlContent::fromSummary($updated, $this->client()->origin());
        $this->items = array_map(
            static fn (array $item): array => $item['id'] === $contentId ? ($normalized ?? array_merge($item, $changes)) : $item,
            $this->items,
        );
        Notification::make()->success()->title('Saved in Media Control')->send();
    }

    private function archiveItem(string $contentId): void
    {
        $actor = $this->authorizeAccess();
        $item = $this->findItem($contentId);
        abort_if($item === null, 404);

        try {
            $this->client()->archive($actor, $contentId);
        } catch (MediaControlRequestFailed $exception) {
            $this->audit($actor, 'media_control.media.archived', 'rejected', ['content_id' => $contentId, 'title' => $item['title'], 'error_code' => $exception->errorCode, 'status' => $exception->status]);
            Notification::make()->danger()
                ->title($exception->isConflict() ? 'This media is in use' : 'Media Control did not archive it')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->audit($actor, 'media_control.media.archived', 'allowed', ['content_id' => $contentId, 'title' => $item['title']]);
        $this->items = array_values(array_filter($this->items, static fn (array $row): bool => $row['id'] !== $contentId));
        Notification::make()->success()->title('Archived in Media Control')->body($item['title'])->send();
    }

    private function checkHealth(): void
    {
        $client = $this->client();
        $this->configured = $client->isConfigured();
        $this->reachable = false;
        $this->mcVersion = null;
        if (! $this->configured) {
            $this->statusMessage = MediaControlRequestFailed::notConfigured()->getMessage();

            return;
        }

        try {
            $health = $client->health($this->actor());
            $this->reachable = ($health['ok'] ?? false) === true;
            $this->mcVersion = is_string($health['version'] ?? null) ? mb_substr($health['version'], 0, 40) : null;
            $this->statusMessage = $this->reachable ? null : 'Media Control answered but did not report itself healthy.';
        } catch (MediaControlRequestFailed $exception) {
            $this->configured = $exception->kind !== MediaControlRequestFailed::NOT_CONFIGURED;
            $this->statusMessage = $exception->getMessage();
        }
    }

    private function loadLibrary(bool $append = false): void
    {
        if (! $this->client()->isConfigured()) {
            $this->items = [];
            $this->nextCursor = null;

            return;
        }

        try {
            $page = $this->client()->list($this->actor(), [
                'kind' => $this->kind === '' ? null : $this->kind,
                'q' => $this->search === '' ? null : $this->search,
                'limit' => self::PAGE_SIZE,
                'cursor' => $append ? $this->nextCursor : null,
            ]);
        } catch (MediaControlRequestFailed $exception) {
            $this->libraryError = $exception->getMessage();

            return;
        }

        $origin = $this->client()->origin();
        $rows = [];
        foreach (is_array($page['items'] ?? null) ? $page['items'] : [] as $item) {
            if (is_array($item) && ($normalized = MediaControlContent::fromSummary($item, $origin)) !== null) {
                $rows[] = $normalized;
            }
        }
        $this->libraryError = null;
        $this->items = $append ? [...$this->items, ...$rows] : $rows;
        $this->nextCursor = is_string($page['next_cursor'] ?? null) && $page['next_cursor'] !== '' ? $page['next_cursor'] : null;
    }

    /** @return array<string, mixed>|null */
    private function findItem(string $contentId): ?array
    {
        foreach ($this->items as $item) {
            if ($item['id'] === $contentId) {
                return $item;
            }
        }

        return null;
    }

    private function authorizeAccess(): User
    {
        abort_unless(static::canAccess(), 403);

        return $this->actor();
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, string $action, string $result, array $metadata): void
    {
        app(SecurityAuditRecorder::class)->record($actor, $actor, $action, $result, null, $metadata);
    }

    private function client(): MediaControlMediaClient
    {
        return app(MediaControlMediaClient::class);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
