<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'category_id',
        'comment',
    ];

    /**
     * Get the submission this comment belongs to.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(EvaluationSubmission::class, 'submission_id');
    }

    /**
     * Get the category this comment is for (if any).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EvaluationCategory::class, 'category_id');
    }
}
