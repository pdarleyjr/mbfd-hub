<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported version of a bag inventory list (airway-bag, als-med-bag, als-transport-vehicle, handtevy,
 * als-non-transport, fire-boat-6-als or bls-detail-bag).
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

    public const HANDTEVY = 'handtevy';

    public const ALS_NON_TRANSPORT = 'als-non-transport';

    public const FIRE_BOAT_6_ALS = 'fire-boat-6-als';

    public const BLS_DETAIL_BAG = 'bls-detail-bag';

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
