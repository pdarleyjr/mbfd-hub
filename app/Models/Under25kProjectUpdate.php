<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Under25kProjectUpdate extends Model
{
    use HasFactory;

    protected $table = 'under_25k_project_updates';

    protected $fillable = [
        'under_25k_project_id',
        'user_id',
        'title',
        'body',
        'percent_complete_snapshot',
    ];

    protected $casts = [
        'percent_complete_snapshot' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function project()
    {
        return $this->belongsTo(Under25kProject::class, 'under_25k_project_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
