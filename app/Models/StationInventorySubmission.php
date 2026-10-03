<?php

namespace App\Models;

use App\Models\Concerns\HasArchive;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_reason
 * @property array<int, array<string, mixed>>|null $archive_history
 */
class StationInventorySubmission extends Model
{
    use HasArchive, HasFactory;

    protected $fillable = [
        'client_submission_id',
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
        'archive_history' => 'array',
        'items' => 'array',
        'submitted_at' => 'datetime',
    ];

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
