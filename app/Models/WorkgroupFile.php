<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkgroupFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_id',
        'workgroup_session_id',
        'filename',
        'filepath',
        'file_type',
        'file_size',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (WorkgroupFile $file): void {
            if ($file->isDirty('filepath') && $file->filepath) {
                $disk = Storage::disk(config('filesystems.private', 'local'));
                if ($disk->exists($file->filepath)) {
                    $file->file_size = $disk->size($file->filepath);
                    $file->file_type = pathinfo($file->filepath, PATHINFO_EXTENSION);
                }
            }
        });

        static::creating(function (WorkgroupFile $file) {
            if (empty($file->uploaded_by) && auth()->check()) {
                $file->uploaded_by = auth()->id();
            }
            if ($file->filepath && empty($file->file_type)) {
                $file->file_type = pathinfo($file->filepath, PATHINFO_EXTENSION);
            }
            if ($file->filepath && empty($file->filename)) {
                $file->filename = basename($file->filepath);
            }
        });
    }

    /**
     * Get the workgroup this file belongs to.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Get the session this file belongs to (if any).
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSession::class, 'workgroup_session_id');
    }

    /**
     * Get the user who uploaded this file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get human-readable file size.
     */
    public function getFormattedSizeAttribute(): string
    {
        if (! $this->file_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2).' '.$units[$unitIndex];
    }
}
