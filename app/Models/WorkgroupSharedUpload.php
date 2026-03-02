<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkgroupSharedUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_id',
        'workgroup_session_id',
        'user_id',
        'workgroup_member_id',
        'filename',
        'filepath',
        'file_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Get the workgroup this upload belongs to.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Get the session this upload belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSession::class, 'workgroup_session_id');
    }

    /**
     * Get the user who uploaded this file.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the member who uploaded this file.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(WorkgroupMember::class, 'workgroup_member_id');
    }

    /**
     * Get human-readable file size.
     */
    public function getFormattedSizeAttribute(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
