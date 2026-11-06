<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported fuel list file, kept on the private disk as the source record.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property string|null $data_sha256
 * @property string $original_name
 * @property string $mime_type
 * @property int $file_size
 * @property string $format
 * @property int|null $uploaded_by_user_id
 * @property int $row_count
 * @property int $matched_count
 * @property int $exception_count
 * @property \Illuminate\Support\Carbon $imported_at
 */
final class FuelIdSource extends Model
{
    protected $table = 'fuel_id_sources';

    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path'];

    protected $casts = [
        'file_size' => 'integer',
        'row_count' => 'integer',
        'matched_count' => 'integer',
        'exception_count' => 'integer',
        'imported_at' => 'datetime',
    ];

    /** @return HasMany<FuelIdRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(FuelIdRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
