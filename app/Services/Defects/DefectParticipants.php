<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enrollment of members into an issue's follow list. Idempotent: the
 * (defect, user) pair is unique and an existing row only has its
 * last_* bookkeeping advanced, never its original enrollment source.
 */
final class DefectParticipants
{
    public function enroll(
        ApparatusDefect $defect,
        User $user,
        string $source,
        ?ApparatusInspection $inspection = null,
    ): ApparatusDefectParticipant {
        $inspection?->assertOperational();
        $now = now();

        // insertOrIgnore keeps two concurrent enrollments from aborting a PostgreSQL transaction
        // on the (defect, user) unique index; the loser just advances the bookkeeping below.
        DB::table('apparatus_defect_participants')->insertOrIgnore([
            'apparatus_defect_id' => $defect->getKey(),
            'user_id' => $user->getKey(),
            'employee_id' => $user->employee_profile_id,
            'enrollment_source' => $source,
            'first_inspection_id' => $inspection?->getKey(),
            'last_inspection_id' => $inspection?->getKey(),
            'first_participated_at' => $now,
            'last_participated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $participant = ApparatusDefectParticipant::query()
            ->where('apparatus_defect_id', $defect->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        $participant->forceFill(array_filter([
            'last_inspection_id' => $inspection?->getKey(),
            'last_participated_at' => $now,
            'employee_id' => $participant->employee_id ?? $user->employee_profile_id,
        ], static fn ($value): bool => $value !== null))->save();

        return $participant;
    }

    public function isParticipant(ApparatusDefect $defect, User $user): bool
    {
        return ApparatusDefectParticipant::query()
            ->where('apparatus_defect_id', $defect->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
