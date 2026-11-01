<?php

declare(strict_types=1);

namespace App\Services\DepartmentUpdates;

use App\Enums\DepartmentUpdateAudience;
use App\Enums\DepartmentUpdateCategory;
use App\Enums\DepartmentUpdatePriority;
use App\Enums\DepartmentUpdateStatus;
use App\Models\CommandCommunicationAttachment;
use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationAttachmentStore;
use App\Services\Security\Captain5Access;
use App\Services\Security\Command300Access;
use App\Services\Security\FleetOperationsManagerAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Posts a Department Update (the home page notices) from the 300 or Captain 5 page. It creates an
 * ordinary DepartmentUpdate (same table, same private file storage, same home page and archive,
 * same admin moderation in the Admin Panel) marked with its `origin`. Unlike the private 300 /
 * Captain 5 Messages, these are visible department-wide.
 *
 * Who may post (the same from either page): anyone who can use the Captain 5 page or the 300 page,
 * any Chief or Division Chief, a Fleet Manager, and any member with Admin Panel rights. Every post
 * stores the poster's rank and name from the signed-in account (DepartmentUpdate::booted); they
 * are never typed in and never edited. Posts are published immediately to everyone, without a
 * push/in-app broadcast; administrators can still edit, pin, notify or archive them in the Admin
 * Panel. An author can take their own post down (archived, kept as history).
 */
final class CommandDepartmentUpdatePublisher
{
    public const ORIGIN_COMMAND_300 = 'command_300';

    public const ORIGIN_CAPTAIN_5 = 'captain_5';

    /** Sent from the Training Crew Planner. Same posting rule as the two pages; Training access alone grants nothing. */
    public const ORIGIN_TRAINING = 'training';

    public const ORIGINS = [self::ORIGIN_COMMAND_300, self::ORIGIN_CAPTAIN_5, self::ORIGIN_TRAINING];

    private const CATEGORIES = ['general', 'operations', 'training'];

    private const IMAGE_EXTENSIONS = ['jpg', 'png', 'webp'];

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    private const ATTACHMENT_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv'];

    private const DISK = 'local'; // DepartmentUpdateController streams from the local (private) disk

    public function __construct(
        private readonly Command300Access $command300,
        private readonly Captain5Access $captain5,
        private readonly CommandCommunicationAttachmentStore $files,
    ) {}

    /** Ranks that may always post, whatever their page access. */
    private const CHIEF_RANKS = ['Division Chief', 'Deputy Fire Chief', 'Fire Chief'];

    public function canPost(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed() || (bool) $user->must_change_password) {
            return false;
        }

        return $user->hasCurrentAdminPanelEntitlement()
            || $user->canManageFleetSwaps()
            || FleetOperationsManagerAssignment::isAssigned($user)
            || $this->isChief($user)
            || $this->captain5->canView($user)
            || $this->command300->allows($user);
    }

    private function isChief(User $user): bool
    {
        $rank = Command300Access::normalizeRank($user->employeeProfile?->rank);

        return $rank !== '' && in_array($rank, array_map(static fn (string $chief): string => Command300Access::normalizeRank($chief), self::CHIEF_RANKS), true);
    }

    /**
     * @param  array<string, mixed>  $data  title, body, category?, expires_at?, client_request_id?
     *
     * @throws AuthorizationException|ValidationException
     */
    public function publish(User $author, string $origin, array $data, ?UploadedFile $image = null, ?UploadedFile $attachment = null): DepartmentUpdate
    {
        if (! $this->canPost($author) || ! in_array($origin, self::ORIGINS, true)) {
            throw new AuthorizationException('You cannot post Department Updates.');
        }
        $valid = Validator::make($data, [
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'expires_at' => ['nullable', 'date', 'after:now', 'before:+120 days'],
            'client_request_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/D'],
        ])->validate();
        $title = trim((string) $valid['title']);
        $body = trim((string) $valid['body']);
        if ($title === '' || $body === '') {
            throw ValidationException::withMessages([$title === '' ? 'title' : 'body' => 'This field cannot be blank.']);
        }

        $existing = $this->existing($author, $valid['client_request_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        $stored = [];
        try {
            $imageFile = $image !== null ? $this->store($image, 'image', $stored) : null;
            $attachmentFile = $attachment !== null ? $this->store($attachment, 'attachment', $stored) : null;
            $now = CarbonImmutable::now();

            $update = DepartmentUpdate::query()->create([
                'title' => $title,
                'body' => self::paragraphs($body),
                'category' => DepartmentUpdateCategory::from($valid['category'] ?? 'operations'),
                'priority' => DepartmentUpdatePriority::Normal,
                'status' => DepartmentUpdateStatus::Published,
                'audience' => DepartmentUpdateAudience::Everyone,
                'is_pinned' => false,
                'publish_at' => $now,
                'first_published_at' => $now,
                'expires_at' => isset($valid['expires_at']) ? CarbonImmutable::parse((string) $valid['expires_at'], \App\Support\HubTimezone::NAME)->utc() : null,
                'send_in_app' => false,
                'send_web_push' => false,
                'author_id' => $author->getKey(),
                'origin' => $origin,
                'client_request_id' => $valid['client_request_id'] ?? null,
                'image_path' => $imageFile['path'] ?? null,
                'image_name' => $imageFile['name'] ?? null,
                'attachment_path' => $attachmentFile['path'] ?? null,
                'attachment_name' => $attachmentFile['name'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $this->discard($stored);

            return $this->existing($author, $valid['client_request_id'] ?? null) ?? throw $exception;
        } catch (\Throwable $exception) {
            $this->discard($stored);
            throw $exception;
        }

        Log::info('department_updates.posted_from_page', ['update_id' => $update->getKey(), 'origin' => $origin, 'author_user_id' => $author->getKey()]);

        return $update;
    }

    /** The author takes their own post off the home page; it stays in the archive as history. */
    public function takeDown(User $author, int $updateId, string $origin): DepartmentUpdate
    {
        $update = DepartmentUpdate::query()->where('origin', $origin)->findOrFail($updateId);
        if ((int) $update->author_id !== (int) $author->getKey() || ! $this->canPost($author)) {
            throw new AuthorizationException('Only the author can take this update down. Administrators manage updates in the Admin Panel.');
        }
        if ($update->status === DepartmentUpdateStatus::Published) {
            $update->archiveAsPublishedHistory();
        }

        return $update->refresh();
    }

    /** @return Collection<int, DepartmentUpdate> recent updates posted from this page, newest first */
    public function recent(string $origin): Collection
    {
        return DepartmentUpdate::query()->where('origin', $origin)
            ->whereIn('status', [DepartmentUpdateStatus::Published->value, DepartmentUpdateStatus::Archived->value])
            ->with('author:id,name')->orderByDesc('id')->limit(15)->get();
    }

    /** Plain text from the page becomes safe paragraphs (the home page renders sanitized HTML). */
    public static function paragraphs(string $text): string
    {
        $blocks = preg_split('/\R{2,}/u', str_replace("\r\n", "\n", $text)) ?: [];

        return collect($blocks)->map(fn (string $block): string => trim($block))->filter()
            ->map(fn (string $block): string => '<p>'.nl2br(e($block), false).'</p>')
            ->implode('');
    }

    /**
     * @param  'image'|'attachment'  $slot
     * @param  list<string>  $stored
     * @return array{path: string, name: string}
     */
    private function store(UploadedFile $file, string $slot, array &$stored): array
    {
        $inspected = $this->files->inspect([$file])[0];
        $allowed = $slot === 'image' ? self::IMAGE_EXTENSIONS : self::ATTACHMENT_EXTENSIONS;
        if (! in_array($inspected['extension'], $allowed, true)
            || ($slot === 'image' && ($inspected['kind'] !== CommandCommunicationAttachment::KIND_IMAGE || $inspected['size'] > self::IMAGE_MAX_BYTES))) {
            throw ValidationException::withMessages([$slot => $slot === 'image'
                ? 'The picture must be a JPEG, PNG or WebP image up to 5 MB.'
                : 'The file must be a PDF, DOCX, XLSX, PPTX, TXT or CSV document up to 15 MB.']);
        }
        $directory = $slot === 'image' ? 'department-updates/images' : 'department-updates/attachments';
        $generated = Str::ulid().'.'.$inspected['extension'];
        if (Storage::disk(self::DISK)->putFileAs($directory, $file, $generated, ['visibility' => 'private']) === false) {
            throw ValidationException::withMessages([$slot => 'The file could not be stored. Please try again.']);
        }
        $stored[] = $directory.'/'.$generated;

        return ['path' => $directory.'/'.$generated, 'name' => $inspected['original']];
    }

    /** @param list<string> $paths */
    private function discard(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(self::DISK)->delete($path);
            } catch (\Throwable) {
                // An orphaned private file is not reachable by any route.
            }
        }
    }

    private function existing(User $author, ?string $clientRequestId): ?DepartmentUpdate
    {
        if ($clientRequestId === null || $clientRequestId === '') {
            return null;
        }
        $update = DepartmentUpdate::query()->where('client_request_id', $clientRequestId)->first();
        if ($update !== null && (int) $update->author_id !== (int) $author->getKey()) {
            throw new AuthorizationException('That request id belongs to another person.');
        }

        return $update;
    }
}
