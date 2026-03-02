<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkgroupNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_member_id',
        'workgroup_session_id',
        'title',
        'content',
    ];

    /**
     * Get the member who created this note.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(WorkgroupMember::class, 'workgroup_member_id');
    }

    /**
     * Get the session this note belongs to (if any).
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSession::class, 'workgroup_session_id');
    }

    /**
     * Get the workgroup this note belongs to through the member.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Get truncated content preview.
     */
    public function getPreviewAttribute(int $length = 100): string
    {
        return \Str::limit($this->content, $length);
    }
}
