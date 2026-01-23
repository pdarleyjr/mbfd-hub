<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApparatusInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'apparatus_id',
        'operator_name',
        'rank',
        'shift',
        'unit_number',
        'completed_at',
    ];

    public function apparatus()
    {
        return $this->belongsTo(Apparatus::class);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(ApparatusDefect::class);
    }
}
