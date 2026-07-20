<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalFormImport extends Model
{
    use HasUlids;

    protected $fillable = [
        'form_record_id',
        'employee_id',
        'idempotency_key',
        'identity_hash',
        'parser_version',
        'unit_id',
        'source_sha256',
        'source_type',
        'engine',
        'fallback_used',
        'fallback_reason',
        'matched_message_count',
        'status',
        'result',
        'before_data',
        'applied_revision',
        'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'fallback_used' => 'boolean',
            'matched_message_count' => 'integer',
            'result' => 'array',
            'before_data' => 'array',
            'applied_revision' => 'integer',
            'undone_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(OperationalFormRecord::class, 'form_record_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
