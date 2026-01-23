<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
        'sort',
        'assigned_to',
        'created_by',
        'due_at',
    ];

    protected $casts = [
        'status' => TaskStatus::class,
        'due_at' => 'datetime',
        'sort' => 'integer',
        'assigned_to' => 'array',
    ];
}
