<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** @property list<string> $classifications */
final class TrainingCourse extends Model
{
    public const CLASSIFICATIONS = ['Task Level/Company', 'Officer Training', 'ICS / NIMS / FFOG', 'Driver Training', 'Special Ops', 'RIT/MD/Survival', 'Hazmat Training', 'Extrication', 'Marine Training', 'Water Rescue', 'Fire Boat Operation', 'Night Drill', 'SIMM Training', 'EMS Training', 'Pre-Fire/Prevention', 'Equipment Inservice'];

    /** @return list<string> */
    public static function classificationSuggestions(): array
    {
        return collect([...self::CLASSIFICATIONS, 'STC'])
            ->merge(self::query()->orderBy('id')->pluck('classifications')->flatten(1))
            ->merge(TrainingSession::query()->orderBy('id')->pluck('classifications')->flatten(1))
            ->filter(fn ($label): bool => is_string($label) && trim($label) !== '')
            ->map(fn ($label): string => Str::squish((string) $label))
            ->unique(fn (string $label): string => mb_strtolower($label))
            ->values()->all();
    }

    /** @param array<array-key, mixed> $labels
     * @return list<mixed>
     */
    public static function normalizeClassifications(array $labels): array
    {
        $suggestions = self::classificationSuggestions();
        $canonical = array_combine(array_map(mb_strtolower(...), $suggestions), $suggestions);

        return array_values(array_map(function ($label) use ($canonical) {
            if (! is_string($label)) {
                return $label;
            }
            $label = Str::squish($label);

            return $canonical[mb_strtolower($label)] ?? $label;
        }, $labels));
    }

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['classifications' => 'array'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training courses cannot be deleted.'));
    }

    /** @return HasMany<TrainingSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
