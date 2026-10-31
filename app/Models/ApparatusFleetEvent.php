<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history for operational alerts and changeouts. Rows are never updated or deleted by the application.
 *
 * @property int $id
 * @property int|null $alert_id
 * @property int|null $changeout_id
 * @property string $event
 * @property int|null $actor_user_id
 * @property string|null $actor_name
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable $created_at
 */
class ApparatusFleetEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['alert_id', 'changeout_id', 'event', 'actor_user_id', 'actor_name', 'data'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Fleet event history is append-only.');
        });
    }

    /** @param array<string, mixed> $data */
    public static function record(string $event, ?ApparatusOperationalAlert $alert, ?ApparatusChangeout $changeout, ?User $actor, array $data = [], ?string $actorName = null): self
    {
        return self::query()->create([
            'alert_id' => $alert?->getKey(),
            'changeout_id' => $changeout?->getKey(),
            'event' => $event,
            'actor_user_id' => $actor?->getKey(),
            'actor_name' => $actor->name ?? $actorName,
            'data' => $data === [] ? null : $data,
        ]);
    }

    /** @return BelongsTo<ApparatusOperationalAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(ApparatusOperationalAlert::class, 'alert_id');
    }

    /** @return BelongsTo<ApparatusChangeout, $this> */
    public function changeout(): BelongsTo
    {
        return $this->belongsTo(ApparatusChangeout::class, 'changeout_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
