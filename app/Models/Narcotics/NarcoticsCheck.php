<?php

declare(strict_types=1);

namespace App\Models\Narcotics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One narcotics check a member sent to Captain 5.
 *
 * State machine (docs/narcotics-check.md): pending -> approved | rejected. Both decisions are final
 * and archive the check (archived_at); a rejected check is answered by a NEW row (resubmission_of_id),
 * never by editing the old one. Mutations go through NarcoticsCheckService.
 *
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property int|null $submitted_by_user_id
 * @property int|null $submitted_by_employee_id
 * @property string $submitter_name
 * @property string|null $submitter_rank
 * @property \Illuminate\Support\Carbon $submitted_at
 * @property string|null $member_note
 * @property string $client_submission_id
 * @property int|null $resubmission_of_id
 * @property string $image_disk
 * @property string $image_path
 * @property string $preview_path
 * @property string $thumb_path
 * @property string $image_mime
 * @property int $image_width
 * @property int $image_height
 * @property int $image_bytes
 * @property string $image_sha256
 * @property \Illuminate\Support\Carbon|null $images_purged_at
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property int|null $decided_by_user_id
 * @property string|null $decided_by_name
 * @property string|null $decided_by_rank
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon|null $archived_at
 */
class NarcoticsCheck extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    protected $table = 'narcotics_checks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'images_purged_at' => 'datetime',
            'decided_at' => 'datetime',
            'archived_at' => 'datetime',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'image_bytes' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isDecided(): bool
    {
        return $this->status === self::APPROVED || $this->status === self::REJECTED;
    }

    public function imagesAvailable(): bool
    {
        return $this->images_purged_at === null;
    }

    /** @param  Builder<self>  $query */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->whereNull('archived_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** @return HasMany<NarcoticsCheckEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(NarcoticsCheckEvent::class, 'narcotics_check_id')->orderBy('id');
    }

    /** @return BelongsTo<self, $this> */
    public function resubmissionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmission_of_id');
    }

    /** @return HasOne<self, $this> */
    public function resubmission(): HasOne
    {
        return $this->hasOne(self::class, 'resubmission_of_id');
    }
}
