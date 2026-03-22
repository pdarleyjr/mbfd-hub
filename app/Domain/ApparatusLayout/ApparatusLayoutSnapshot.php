<?php

namespace App\Domain\ApparatusLayout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApparatusLayoutSnapshot extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'apparatus_id',
        'user_id',
        'name',
        'placements',
        'is_auto_save',
        'is_published',
        'notes',
    ];

    protected $casts = [
        'id' => 'string',
        'placements' => 'array',
        'is_auto_save' => 'boolean',
        'is_published' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the apparatus this snapshot belongs to.
     */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Apparatus::class);
    }

    /**
     * Get the user who created this snapshot.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * Scope to get auto-saves.
     */
    public function scopeAutoSave($query)
    {
        return $query->where('is_auto_save', true);
    }

    /**
     * Scope to get published snapshots.
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope to get manual saves.
     */
    public function scopeManualSave($query)
    {
        return $query->where('is_auto_save', false);
    }
}