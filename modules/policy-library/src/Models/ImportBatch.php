<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportBatch extends Model
{
    protected $table = 'policy_import_batches';

    protected $guarded = ['id'];

    protected $casts = ['edition_ids' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
