<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * "Every write takes an idempotency key": the first call with a (user, scope, key) runs the work
 * inside one transaction and stores its result; a retry returns that stored result and runs
 * nothing. The key row and the work commit or roll back together, so a failed attempt can be retried.
 */
final class Idempotency
{
    public const KEY_PATTERN = '/^[A-Za-z0-9._:\-]{8,100}$/';

    /**
     * @param  Closure(): array<string, mixed>  $work  must return a JSON-serializable array
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(?User $user, string $scope, string $key, Closure $work): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new EmsException('This request is missing a valid idempotency key (8 to 100 letters, numbers or - _ . :). Reload and try again.', 'idempotency_key_invalid');
        }

        return DB::transaction(function () use ($user, $scope, $key, $work): array {
            $inserted = DB::table('ems_idempotency_keys')->insertOrIgnore([
                'user_id' => $user?->getKey(), 'scope' => $scope, 'key' => $key, 'response' => null, 'created_at' => now(),
            ]);

            if ($inserted === 0) {
                $stored = DB::table('ems_idempotency_keys')
                    ->where('user_id', $user?->getKey())->where('scope', $scope)->where('key', $key)->value('response');
                if ($stored === null) {
                    throw EmsException::conflict('That request is still being processed. Wait a moment and check again.', 'in_progress');
                }

                return ['result' => (array) json_decode((string) $stored, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true];
            }

            $result = $work();
            DB::table('ems_idempotency_keys')
                ->where('user_id', $user?->getKey())->where('scope', $scope)->where('key', $key)
                ->update(['response' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

            return ['result' => $result, 'replayed' => false];
        });
    }
}
