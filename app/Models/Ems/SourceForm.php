<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One imported version of a bag inventory form (airway-bag or als-med-bag). */
class SourceForm extends Model
{
    public const AIRWAY = 'airway-bag';

    public const ALS = 'als-med-bag';

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
