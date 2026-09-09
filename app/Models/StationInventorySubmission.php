<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StationInventorySubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'station_id',
        'employee_name',
        'shift',
        'items',
        'notes',
        'pdf_path',
        'created_by',
        'actor_employee_id',
        'submitted_at',
    ];

    protected $casts = [
        'items' => 'array',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Submitted station inventory evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Submitted station inventory evidence cannot be deleted.');
        });
    }

    /**
     * Get the station that owns the submission.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the user who created the submission.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
