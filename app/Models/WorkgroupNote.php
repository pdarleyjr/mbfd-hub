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
        'is_shared',
        'shared_with_user_id',
    ];

    protected $casts = [
        'is_shared' => 'boolean',
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
     * Get the user this note is shared with.
     */
    public function sharedWith(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    /**
     * Get truncated content preview.
     * Note: Eloquent accessor system passes null as first arg.
     */
    public function getPreviewAttribute($length = null): string
    {
        return \Str::limit(strip_tags($this->content ?? ''), (int) ($length ?? 100));
    }
}
