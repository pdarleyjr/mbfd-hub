<?php

declare(strict_types=1);

namespace App\Models\Narcotics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo 2, 3, ... of a narcotics check. Photo 1 lives on the check row itself (docs/narcotics-check.md
 * section 4). Written once with its check and never edited; the files go when the check's files are purged.
 *
 * @property int $id
 * @property int $narcotics_check_id
 * @property int $position 2 and up
 * @property string $image_disk
 * @property string $image_path
 * @property string $preview_path
 * @property string $thumb_path
 * @property string $image_mime
 * @property int $image_width
 * @property int $image_height
 * @property int $image_bytes
 * @property string $image_sha256
 */
class NarcoticsCheckPhoto extends Model
{
    protected $table = 'narcotics_check_photos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'image_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<NarcoticsCheck, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(NarcoticsCheck::class, 'narcotics_check_id');
    }
}
