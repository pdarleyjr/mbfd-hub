<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an imported fuel list. prompt_id is the member's Fuel PIN: encrypted at rest, always a string.
 *
 * @property int $id
 * @property int $fuel_id_source_id
 * @property int $row_number
 * @property int|null $page
 * @property string $source_employee_number
 * @property string $normalized_employee_number
 * @property string|null $last_name
 * @property string|null $first_name
 * @property string $prompt_id
 * @property string|null $status
 * @property string|null $department
 * @property string $match_status
 * @property int|null $employee_id
 * @property int|null $candidate_employee_id
 * @property int|null $current_employee_id
 * @property bool $employee_departed
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $resolution_note
 */
final class FuelIdRow extends Model
{
    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const CONFLICT = 'conflict';

    public const NAME_MISMATCH = 'name_mismatch';

    public const RESOLVED = 'resolved';

    public const SUPERSEDED = 'superseded';

    public const DISMISSED = 'dismissed';

    /** Rows an administrator still has to decide. */
    public const OPEN_EXCEPTIONS = [self::UNMATCHED, self::CONFLICT, self::NAME_MISMATCH];

    /** Rows that may hold an employee's current PIN. */
    public const ASSIGNED = [self::MATCHED, self::RESOLVED];

    protected $table = 'fuel_id_rows';

    protected $guarded = ['id'];

    /** The PIN never leaves the model through toArray()/toJson(). */
    protected $hidden = ['prompt_id'];

    protected $casts = [
        'prompt_id' => 'encrypted',
        'row_number' => 'integer',
        'page' => 'integer',
        'employee_departed' => 'boolean',
        'superseded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<FuelIdSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(FuelIdSource::class, 'fuel_id_source_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function candidateEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'candidate_employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function sourceName(): string
    {
        return trim(implode(', ', array_filter([$this->last_name, $this->first_name])));
    }

    public function isOpenException(): bool
    {
        return in_array($this->match_status, self::OPEN_EXCEPTIONS, true);
    }
}
