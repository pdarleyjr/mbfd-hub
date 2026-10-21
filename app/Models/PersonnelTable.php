<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @phpstan-type PersonnelTrackingColumn array{id: string, label: string, type: string, options: list<string>, hidden: bool, color: string|null, highlight_when?: string, highlight_value?: string|null}
 *
 * @property array<string, mixed> $criteria
 * @property list<string> $source_columns
 * @property list<PersonnelTrackingColumn> $columns
 * @property list<PersonnelTrackingColumn> $retired_columns
 * @property int $version
 * @property Carbon|null $archived_at
 */
class PersonnelTable extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'criteria' => 'array', 'source_columns' => 'array', 'columns' => 'array',
            'retired_columns' => 'array', 'version' => 'integer', 'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<PersonnelTableEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PersonnelTableEntry::class, 'table_id');
    }

    /** @return HasMany<PersonnelTableView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(PersonnelTableView::class, 'table_id');
    }

    /** @return HasMany<PersonnelTableEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PersonnelTableEvent::class, 'table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
