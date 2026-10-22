<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $post_id
 * @property string $disk
 * @property string $storage_path
 * @property string $generated_filename
 * @property string $original_filename
 * @property string $mime_type
 * @property int $byte_size
 * @property string $sha256
 * @property int $uploaded_by_user_id
 * @property string $kind
 */
final class CommandCommunicationAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    protected $table = 'command_communication_attachments';

    protected $fillable = [
        'public_id', 'post_id', 'disk', 'storage_path', 'generated_filename', 'original_filename',
        'mime_type', 'byte_size', 'sha256', 'uploaded_by_user_id', 'kind',
    ];

    protected $hidden = ['disk', 'storage_path', 'generated_filename', 'sha256', 'uploaded_by_user_id'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<CommandCommunicationPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(CommandCommunicationPost::class, 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }
}
