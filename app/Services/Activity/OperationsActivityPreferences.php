<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One staff member's chosen order for the Admin Overview lanes (issue_sort) and the Latest activity list
 * (activity_sort), in user_dashboard_preferences.
 *
 * Same idea as SupportAlertLevelPreferences: no stored row means the default for both. A row is written only for
 * a non-default choice and is deleted again when both are back on the default, so a later default can still improve.
 * An unknown stored value reads as the default, so a bad value can never hide anything.
 */
final class OperationsActivityPreferences
{
    public const TABLE = 'user_dashboard_preferences';

    public const ACTIVITY_LATEST = 'latest_update';

    public const ACTIVITY_NEEDS_ACTION_FIRST = 'needs_action_first';

    /** @var array<string, string> */
    public const ACTIVITY_OPTIONS = [
        self::ACTIVITY_LATEST => 'Newest first',
        self::ACTIVITY_NEEDS_ACTION_FIRST => 'Needs action first',
    ];

    public static function normalizeActivity(?string $sort): string
    {
        return $sort !== null && array_key_exists($sort, self::ACTIVITY_OPTIONS) ? $sort : self::ACTIVITY_LATEST;
    }

    public function issueSort(User $user): string
    {
        return OperationsIssueSort::normalize($this->row($user)->issue_sort ?? null);
    }

    public function activitySort(User $user): string
    {
        return self::normalizeActivity($this->row($user)->activity_sort ?? null);
    }

    public function saveIssueSort(User $user, string $sort): void
    {
        $sort = OperationsIssueSort::normalize($sort);
        $this->store($user, $sort === OperationsIssueSort::DEFAULT ? null : $sort, $this->stored($user, 'activity_sort'));
    }

    public function saveActivitySort(User $user, string $sort): void
    {
        $sort = self::normalizeActivity($sort);
        $this->store($user, $this->stored($user, 'issue_sort'), $sort === self::ACTIVITY_LATEST ? null : $sort);
    }

    private function row(User $user): ?object
    {
        return DB::table(self::TABLE)->where('user_id', $user->getKey())->first();
    }

    /** The non-default value kept in a column, or null when it is unset or unknown. */
    private function stored(User $user, string $column): ?string
    {
        $value = $this->row($user)->{$column} ?? null;
        $known = $column === 'issue_sort' ? OperationsIssueSort::OPTIONS : self::ACTIVITY_OPTIONS;
        $default = $column === 'issue_sort' ? OperationsIssueSort::DEFAULT : self::ACTIVITY_LATEST;

        return is_string($value) && $value !== $default && array_key_exists($value, $known) ? $value : null;
    }

    private function store(User $user, ?string $issue, ?string $activity): void
    {
        $query = DB::table(self::TABLE)->where('user_id', $user->getKey());
        if ($issue === null && $activity === null) {
            $query->delete();

            return;
        }
        $now = now();
        if ($query->exists()) {
            $query->update(['issue_sort' => $issue, 'activity_sort' => $activity, 'updated_at' => $now]);

            return;
        }
        DB::table(self::TABLE)->insert([
            'user_id' => $user->getKey(), 'issue_sort' => $issue, 'activity_sort' => $activity,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
