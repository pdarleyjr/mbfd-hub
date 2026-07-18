<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class OperationalFormRecord extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'form_type',
        'form_version',
        'title',
        'status',
        'data',
        'revision',
        'latest_pdf_version',
        'last_autosaved_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'revision' => 'integer',
            'latest_pdf_version' => 'integer',
            'last_autosaved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OperationalFormDocument::class, 'form_record_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationalFormEvent::class, 'form_record_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(OperationalFormGeneration::class, 'form_record_id');
    }

    public function latestDocument(): HasOne
    {
        return $this->hasOne(OperationalFormDocument::class, 'form_record_id')->latestOfMany('version_number');
    }

    public function getHasChangesSinceLatestPdfAttribute(): bool
    {
        if ($this->latest_pdf_version === null) {
            return false;
        }

        $sourceRevision = $this->documents()
            ->where('version_number', $this->latest_pdf_version)
            ->value('source_revision');

        return $sourceRevision !== null && (int) $sourceRevision !== $this->revision;
    }
}
