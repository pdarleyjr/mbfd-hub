<?php

namespace App\Models\Training;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingTodoUpdate extends Model
{
    use SoftDeletes;

    protected $table = 'training_todo_updates';

    protected $fillable = [
        'training_todo_id',
        'user_id',
        'username',
        'comment',
    ];

    public function trainingTodo(): BelongsTo
    {
        return $this->belongsTo(TrainingTodo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
