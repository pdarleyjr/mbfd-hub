<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;

final class TrainingPaperReviewAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Paper review audits are append-only.'));
        self::deleting(fn () => throw new \LogicException('Paper review audits are append-only.'));
    }
}
