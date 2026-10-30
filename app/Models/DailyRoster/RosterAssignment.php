<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One source row of the roster, kept as TeleStaff printed it plus its normalized meaning. */
final class RosterAssignment extends Model
{
    public const KIND_EMPLOYEE = 'employee';

    public const KIND_VACANCY_OPEN = 'vacancy_open';

    public const KIND_VACANCY_SUPPRESSED = 'vacancy_suppressed';

    public const AVAILABILITY_WORKING = 'working';

    public const AVAILABILITY_NOT_WORKING = 'not_working';

    public const AVAILABILITY_ANNOTATION = 'annotation';

    public const AVAILABILITY_UNKNOWN = 'unknown';

    public const AVAILABILITY_VACANT = 'vacant';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'is_continuation' => 'boolean',
        'certifications' => 'array',
        'reported_hours' => 'float',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'review_reasons' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Roster assignments are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Roster assignments are immutable.'));
    }

    /** @return BelongsTo<RosterSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(RosterSection::class, 'roster_section_id');
    }
}
