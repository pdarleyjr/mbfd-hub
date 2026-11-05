<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserSupportAlertLevels;
use Illuminate\Support\Collection;

/**
 * Which Support Services priority levels notify a staff member.
 *
 * Defaults to all three ON for everyone (no stored row). A row is written only when someone turns a level off,
 * and is removed again when all three are back on, so a later default can still improve. This only narrows the
 * optional triage alerts (SupportAlertLevelFilter); it never grants anyone an alert and never changes channels.
 */
final class SupportAlertLevelPreferences
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    /** @var list<string> */
    public const LEVELS = [self::LOW, self::MEDIUM, self::HIGH];

    /** Registry categories whose optional alerts are filtered by level; being eligible for one shows the settings. */
    private const GATED_CATEGORIES = [
        User::NOTIFICATION_PREFERENCE_STATION_REQUESTS,
        User::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS,
    ];

    public function __construct(private readonly NotificationRecipientEvaluator $evaluator) {}

    /** Anything that is not low, medium or high reads as medium, so an odd value never drops an alert. */
    public static function normalize(?string $level): string
    {
        $level = strtolower(trim((string) $level));

        return in_array($level, self::LEVELS, true) ? $level : self::MEDIUM;
    }

    /** Whether the settings section applies: the member can receive at least one level-filtered alert. */
    public function isAvailableTo(User $user): bool
    {
        foreach (self::GATED_CATEGORIES as $category) {
            if ($this->evaluator->isEligible($user, $category)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{low: bool, medium: bool, high: bool} */
    public function for(User $user): array
    {
        return $this->fromRow(UserSupportAlertLevels::query()->where('user_id', $user->getKey())->first());
    }

    public function allows(User $user, string $level): bool
    {
        return $this->for($user)[self::normalize($level)];
    }

    /**
     * Members who still want an alert at this level. Read in one query; a member with no row keeps all levels.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function whoWant(Collection $users, string $level): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }
        $level = self::normalize($level);
        $rows = UserSupportAlertLevels::query()
            ->whereIn('user_id', $users->map(fn (User $user): int => (int) $user->getKey())->all())
            ->get()
            ->keyBy('user_id');

        return $users
            ->filter(fn (User $user): bool => $this->fromRow($rows->get($user->getKey()))[$level])
            ->values();
    }

    /**
     * Store a member's choices. Missing keys count as on. All three on means no row.
     *
     * @param  array<string, mixed>  $choices  keys low, medium, high
     */
    public function save(User $user, array $choices): void
    {
        $desired = [];
        foreach (self::LEVELS as $level) {
            $desired[$level] = (bool) ($choices[$level] ?? true);
        }

        if (! in_array(false, $desired, true)) {
            UserSupportAlertLevels::query()->where('user_id', $user->getKey())->delete();

            return;
        }

        UserSupportAlertLevels::query()->updateOrCreate(['user_id' => $user->getKey()], [
            'low_enabled' => $desired[self::LOW],
            'medium_enabled' => $desired[self::MEDIUM],
            'high_enabled' => $desired[self::HIGH],
        ]);
    }

    /** @return array{low: bool, medium: bool, high: bool} */
    private function fromRow(?UserSupportAlertLevels $row): array
    {
        return [
            self::LOW => $row === null || $row->low_enabled,
            self::MEDIUM => $row === null || $row->medium_enabled,
            self::HIGH => $row === null || $row->high_enabled,
        ];
    }
}
