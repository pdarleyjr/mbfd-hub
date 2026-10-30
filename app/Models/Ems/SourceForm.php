<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported version of a bag inventory form (airway-bag, als-med-bag or als-transport-vehicle).
 *
 * @property int $id
 * @property string $key
 * @property string|null $version_label
 * @property string $title
 * @property string|null $file_name
 * @property string $sha256
 * @property array<int|string, mixed>|null $notes
 * @property \Illuminate\Support\Carbon $imported_at
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SourceForm extends Model
{
    public const AIRWAY = 'airway-bag';

    public const ALS = 'als-med-bag';

    public const TRANSPORT = 'als-transport-vehicle';

    protected $table = 'ems_source_forms';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['notes' => 'array', 'imported_at' => 'datetime', 'active' => 'boolean'];
    }

    /** @return HasMany<SourceFormItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SourceFormItem::class, 'source_form_id')->orderBy('sort')->orderBy('id');
    }
}
