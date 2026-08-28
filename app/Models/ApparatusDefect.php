<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApparatusDefect extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $defect): void {
            $statusChanged = $defect->isDirty('status');
            $resolvedChanged = $defect->isDirty('resolved');

            if (! $statusChanged && ! $resolvedChanged) {
                return;
            }

            if ($statusChanged) {
                $defect->resolved = $defect->status === 'resolved';
            } else {
                $defect->status = $defect->resolved ? 'resolved' : 'open';
            }

            if ($defect->resolved) {
                $defect->resolved_at ??= now();
            } else {
                $defect->resolved_at = null;
            }
        });
    }

    protected $fillable = [
        'apparatus_id',
        'apparatus_inspection_id',
        'compartment',
        'item',
        'status',
        'issue_type',
        'reported_date',
        'notes',
        'photo', // base64 encoded image
        'photo_path',
        'resolved',
        'resolved_at',
        'resolution_notes',
        'defect_history',
    ];

    protected $casts = [
        'defect_history' => 'array',
        'resolved' => 'boolean',
        'reported_date' => 'date',
    ];

    public function apparatus()
    {
        return $this->belongsTo(Apparatus::class);
    }

    /**
     * Get the inspection this defect was reported in
     */
    public function inspection()
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
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

    public static function recordDefect(
        $apparatusId,
        $compartment,
        $item,
        $status,
        $notes,
        $photoPath = null,
        $inspectionId = null,
    ) {
        $issueType = strtolower((string) $status);

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
                'photo' => $existing->photo,
                'reported_at' => $existing->created_at->toISOString(),
            ];
            $existing->update([
                'status' => in_array($existing->status, ['open', 'in_progress'], true) ? $existing->status : 'open',
                'issue_type' => $issueType,
                'notes' => $notes,
                'photo_path' => $photoPath,
                'apparatus_inspection_id' => $inspectionId,
                'reported_date' => now()->toDateString(),
                'defect_history' => $history,
            ]);

            return $existing;
        } else {
            return self::create([
                'apparatus_id' => $apparatusId,
                'compartment' => $compartment,
                'item' => $item,
                'status' => 'open',
                'issue_type' => $issueType,
                'notes' => $notes,
                'photo_path' => $photoPath,
                'apparatus_inspection_id' => $inspectionId,
                'reported_date' => now()->toDateString(),
            ]);
        }
    }
}
