<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $summary
 * @property list<array<string, mixed>> $rows Decrypted review evidence, never model-serialized.
 */
final class PersonnelReconciliation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['rows'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'rows' => 'encrypted:array'];
    }
}
