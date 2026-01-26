<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'todo_id',
        'user_id',
        'username',
        'comment',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}