<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApparatusDefect extends Model
{
    use HasFactory;

    protected $fillable = [
        'apparatus_id',
        'compartment',
        'item',
        'status',
        'notes',
        'resolved',
        'resolved_at',
        'resolution_notes',
        'defect_history',
    ];

    protected $casts = [
        'defect_history' => 'array',
        'resolved' => 'boolean',
    ];

    public function apparatus()
    {
        return $this->belongsTo(Apparatus::class);
    }

    /**
     * Get recommendations for this defect
     */
    public function recommendations()
    {
        return $this->hasMany(ApparatusDefectRecommendation::class, 'apparatus_defect_id');
    }

    /**
     * Get allocations for this defect
     */
    public function allocations()
    {
        return $this->hasMany(ApparatusInventoryAllocation::class, 'apparatus_defect_id');
    }

    public static function recordDefect($apparatusId, $compartment, $item, $status, $notes)
    {
        $existing = self::where('apparatus_id', $apparatusId)
            ->where('compartment', $compartment)
            ->where('item', $item)
            ->where('resolved', false)
            ->first();

        if ($existing) {
            // Append current data to history
            $history = $existing->defect_history ?? [];
            $history[] = [
                'status' => $existing->status,
                'notes' => $existing->notes,
                'reported_at' => $existing->created_at->toISOString(),
            ];
            $existing->update([
                'status' => $status,
                'notes' => $notes,
                'defect_history' => $history,
            ]);
            return $existing;
        } else {
            return self::create([
                'apparatus_id' => $apparatusId,
                'compartment' => $compartment,
                'item' => $item,
                'status' => $status,
                'notes' => $notes,
            ]);
        }
    }
}