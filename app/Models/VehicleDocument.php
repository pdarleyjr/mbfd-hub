<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Fleet\VehicleDocumentAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/** @property-read User|null $uploader */
class VehicleDocument extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_UNMATCHED = 'unmatched';

    public const CATEGORY_LABELS = [
        'accidents' => 'Accidents & Incidents',
        'damage' => 'Damage Reports & Photos',
        'insurance' => 'Insurance & Claims',
        'repairs' => 'Repairs & Estimates',
        'maintenance' => 'Maintenance & Service',
        'inspections' => 'Inspections',
        'registration' => 'Registration & Vehicle Records',
        'other' => 'Other Documents',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'document_date' => 'date',
        'is_sensitive' => 'boolean',
        'legal_hold' => 'boolean',
        'size_bytes' => 'integer',
        'classification_evidence' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            foreach (['disk', 'path', 'sha256', 'size_bytes', 'mime_type', 'original_filename', 'uploaded_by', 'source_type'] as $field) {
                if ($document->isDirty($field)) {
                    throw new LogicException('Original document bytes and provenance cannot be replaced. Add a separate document instead.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Vehicle documents are retained municipal records and cannot be deleted.'));
    }

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(UnitMasterVehicle::class, 'vehicle_document_unit_master_vehicle', 'document_id', 'vehicle_id')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(VehicleDocumentSource::class, 'document_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VehicleDocumentEvent::class, 'document_id')->latest('id');
    }

    public static function categoryOptions(): array
    {
        $categories = self::CATEGORY_LABELS;
        $query = static::query();
        if (! VehicleDocumentAccess::canSensitive(auth()->user())) {
            $query->where('is_sensitive', false);
        }
        foreach ($query->distinct()->pluck('category') as $category) {
            $categories[$category] ??= Str::headline($category);
        }

        return $categories;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? Str::headline($this->category);
    }

    public function canPreview(): bool
    {
        return in_array($this->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }
}
