<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Single definition of "who may review and act on daily inspection findings".
 *
 * The rule is the same one ApparatusInspectionPolicy::approve and
 * ApparatusDefectPolicy::manageCorrectiveAction use: an administrative role OR
 * the Fleet manage capability (admin.access + admin.fleet.manage).
 */
final class InspectionReviewers
{
    /** @var list<string> */
    public const ROLES = ['super_admin', 'admin', 'logistics_admin'];

    /**
     * Mirrors the effective decision for the approve policy once AdminCapabilityGate has run:
     * super administrators always; anyone holding the direct admin.access permission only with
     * the direct admin.fleet.manage capability (their roles alone do not count); everyone else
     * by administrative role. The account must be active.
     */
    public static function canReview(User $user): bool
    {
        if (! $user->isAuthenticationAllowed()) {
            return false;
        }
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if ($user->hasDirectWebPermission('admin.access')) {
            return $user->hasDirectWebPermission('admin.fleet.manage');
        }

        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Every active account that may review, lowest id first.
     *
     * @return Collection<int, User>
     */
    public static function all(): Collection
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', self::ROLES))
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'admin.fleet.manage'));
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->isAuthenticationAllowed() && self::canReview($user))
            ->values();
    }

    /**
     * Reviewers who already took part in a thread (wrote a message or acted on
     * the issue); every reviewer when nobody has yet.
     *
     * @return Collection<int, User>
     */
    public static function forThread(?ApparatusDefect $defect, ?ApparatusInspection $inspection): Collection
    {
        $reviewers = self::all();
        if ($reviewers->isEmpty()) {
            return $reviewers;
        }

        $authorIds = ApparatusIssueMessage::query()
            ->when($defect !== null, fn ($query) => $query->where('apparatus_defect_id', $defect?->getKey()))
            ->when($defect === null && $inspection !== null, fn ($query) => $query
                ->where('apparatus_inspection_id', $inspection?->getKey())
                ->whereNull('apparatus_defect_id'))
            ->whereNotNull('author_user_id')
            ->pluck('author_user_id');

        $actorIds = $defect === null
            ? collect()
            : ApparatusDefectEvent::query()
                ->where('apparatus_defect_id', $defect->getKey())
                ->whereNotNull('actor_user_id')
                ->pluck('actor_user_id');

        $involved = $authorIds->merge($actorIds)->map(fn ($id): int => (int) $id)->unique()->all();
        $participating = $reviewers->filter(fn (User $user): bool => in_array((int) $user->getKey(), $involved, true))->values();

        return $participating->isNotEmpty() ? $participating : $reviewers;
    }
}
