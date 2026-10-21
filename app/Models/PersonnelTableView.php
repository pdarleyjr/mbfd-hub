<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $settings */
class PersonnelTableView extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return BelongsTo<PersonnelTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(PersonnelTable::class, 'table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
