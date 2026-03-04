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

    public function member(): BelongsTo
    {
        return $this->belongsTo(WorkgroupMember::class, 'workgroup_member_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSession::class, 'workgroup_session_id');
    }

    public function sharedWithUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    public function getPreviewAttribute(): string
    {
        return \Str::limit(strip_tags($this->content), 100);
    }
}
