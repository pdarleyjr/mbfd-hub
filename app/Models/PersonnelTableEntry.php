<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $values
 * @property int $version
 * @property Carbon|null $removed_at
 */
class PersonnelTableEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'removed_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<PersonnelTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(PersonnelTable::class, 'table_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
