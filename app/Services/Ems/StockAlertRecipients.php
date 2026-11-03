<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\StockAlertRecipient;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The EMS low-stock alert list: who is told when supply room items go red. Being on it grants no access;
 * the alert category also asks whether each person can read the supply room inventory.
 */
final class StockAlertRecipients
{
    /** @return Collection<int, StockAlertRecipient> */
    public function all(): Collection
    {
        return StockAlertRecipient::query()->with('user:id,name,account_status')->orderBy('id')->get();
    }

    /**
     * Make the list exactly these people.
     *
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function sync(array $userIds, ?User $actor, string $actorName): array
    {
        $wanted = User::query()->whereIn('id', array_map('intval', $userIds))->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return DB::transaction(function () use ($wanted, $actor, $actorName): array {
            $current = StockAlertRecipient::query()->lockForUpdate()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));
            foreach ($added as $userId) {
                StockAlertRecipient::query()->create([
                    'user_id' => $userId, 'added_by_user_id' => $actor?->getKey(), 'added_by_name' => mb_substr($actorName, 0, 160), 'created_at' => now(),
                ]);
            }
            StockAlertRecipient::query()->whereIn('user_id', $removed)->delete();
            Log::info('EMS low-stock alert list changed', ['by' => $actorName, 'added' => $added, 'removed' => $removed]);

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /**
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function add(array $userIds, ?User $actor, string $actorName): array
    {
        $current = StockAlertRecipient::query()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return $this->sync(array_values(array_unique([...$current, ...array_map('intval', $userIds)])), $actor, $actorName);
    }

    /**
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function remove(array $userIds, ?User $actor, string $actorName): array
    {
        $current = StockAlertRecipient::query()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return $this->sync(array_values(array_diff($current, array_map('intval', $userIds))), $actor, $actorName);
    }
}
