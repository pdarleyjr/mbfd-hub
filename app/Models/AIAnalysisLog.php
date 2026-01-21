<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AIAnalysisLog extends Model
{
    use HasFactory;

    protected $table = 'ai_analysis_logs';

    protected $fillable = [
        'type',
        'projects_analyzed',
        'result',
        'executed_at',
    ];

    protected $casts = [
        'result' => 'array',
        'executed_at' => 'datetime',
        'projects_analyzed' => 'integer',
    ];

    // Scopes
    public function scopeRecent($query)
    {
        return $query->orderBy('executed_at', 'desc')->limit(10);
    }
}
