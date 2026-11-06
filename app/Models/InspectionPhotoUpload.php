<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Daily Checkout photo uploaded on its own request (see InspectionPhotoUploads).
 *
 * @property string $uuid
 * @property int $user_id
 * @property int|null $apparatus_inspection_id
 * @property string $status
 * @property string $disk
 * @property string $path
 * @property string|null $source_path
 * @property int|null $bytes
 * @property string|null $sha256
 */
class InspectionPhotoUpload extends Model
{
    /** Named by a checkout; the phone has not delivered the bytes yet. */
    public const STATUS_AWAITING_UPLOAD = 'awaiting_upload';

    /** The original is held on the private disk until the queued conversion writes the JPEG. */
    public const STATUS_PROCESSING = 'processing';

    /** The JPEG is at `path`. */
    public const STATUS_STORED = 'stored';

    /** The bytes could not be read as a photo. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'user_id',
        'apparatus_inspection_id',
        'status',
        'disk',
        'path',
        'source_path',
        'bytes',
        'width',
        'height',
        'sha256',
        'attached_at',
        'stored_at',
        'failure',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'apparatus_inspection_id' => 'integer',
        'bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'attached_at' => 'datetime',
        'stored_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** True once the phone delivered the bytes (stored, or held for conversion). */
    public function hasBytes(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_STORED], true);
    }
}
