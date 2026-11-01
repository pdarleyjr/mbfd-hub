<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A note or damage report a member sent with a Daily Checkout (docs/apparatus-issue-history.md section 3).
 * Append-only; the one allowed change is linking an issue to its defect once, with linkToDefect().
 *
 * @property int $id
 * @property int $apparatus_id the physical apparatus the checkout settled on
 * @property int $apparatus_inspection_id
 * @property int|null $reporter_user_id
 * @property string|null $reporter_name snapshot, so a later rename never rewrites history
 * @property string $client_id
 * @property string $kind note | issue
 * @property string $description
 * @property string|null $location
 * @property string|null $photo_path
 * @property int|null $apparatus_defect_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ApparatusInspectionConcern extends Model
{
    public const KIND_NOTE = 'note';

    public const KIND_ISSUE = 'issue';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Inspection concerns are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Inspection concerns are append-only.'));
    }

    /** Sets the defect once. A row that already has one keeps it. */
    public function linkToDefect(ApparatusDefect $defect): void
    {
        $linked = $this->newQuery()->whereKey($this->getKey())->whereNull('apparatus_defect_id')
            ->toBase()->update(['apparatus_defect_id' => $defect->getKey()]);
        if ($linked > 0) {
            $this->forceFill(['apparatus_defect_id' => $defect->getKey()])->syncOriginal();
        }
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<ApparatusDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }
}
