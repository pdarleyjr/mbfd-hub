<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One entry in the station cleaning schedule. Read at request time; see the 2026_11_06_093000 migration for the
 * scope and date rules. Rows are disabled, never deleted, so their revision history stays attached.
 *
 * @property int $id
 * @property string|null $station_number
 * @property string $kind
 * @property int|null $weekday
 * @property \Illuminate\Support\Carbon|null $on_date
 * @property string $title
 * @property string|null $instructions
 * @property bool $is_enabled
 * @property int $sort_order
 * @property string|null $source
 * @property int|null $updated_by_user_id
 */
final class StationCleaningTask extends Model
{
    public const KIND_BASELINE = 'baseline';

    public const KIND_FOCUS = 'focus';

    public const KIND_EXTRA = 'extra';

    public const KINDS = [self::KIND_BASELINE, self::KIND_FOCUS, self::KIND_EXTRA];

    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public const TITLE_MAX = 160;

    public const INSTRUCTIONS_MAX = 4000;

    /** Fields an admin can change; these are what the revision trail records. */
    public const AUDITED_FIELDS = ['station_number', 'kind', 'weekday', 'on_date', 'title', 'instructions', 'is_enabled', 'sort_order'];

    protected $fillable = [
        'station_number',
        'kind',
        'weekday',
        'on_date',
        'title',
        'instructions',
        'is_enabled',
        'sort_order',
        'source',
        'updated_by_user_id',
    ];

    protected $casts = [
        'weekday' => 'integer',
        'on_date' => 'date',
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        self::deleting(static function (): void {
            throw new LogicException('Cleaning tasks are disabled, not deleted, so their history is kept.');
        });
    }

    /** "02", " 2 " and "2" all mean Station 2; non-numeric labels compare as typed, ignoring case. */
    public static function normalizeStationNumber(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }

        return ctype_digit($text) ? (string) (int) $text : $text;
    }

    /** @return list<string> one line per instruction, blank lines dropped */
    public function instructionLines(): array
    {
        $lines = preg_split('/\R/u', (string) $this->instructions) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /** @return array<string, mixed> the audited fields as stored, for the revision trail */
    public function auditSnapshot(): array
    {
        $snapshot = [];
        foreach (self::AUDITED_FIELDS as $field) {
            $snapshot[$field] = $field === 'on_date' ? $this->on_date?->format('Y-m-d') : $this->getAttribute($field);
        }

        return $snapshot;
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<StationCleaningTaskRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(StationCleaningTaskRevision::class);
    }
}
