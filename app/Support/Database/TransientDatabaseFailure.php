<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Tells a failure the surrounding transaction must see (deadlock, serialization failure, lock
 * timeout, lost connection) from an ordinary error a best-effort side effect may swallow.
 *
 * Inside a nested transaction Laravel does not roll back to the savepoint on a concurrency error:
 * it leaves the whole outer transaction aborted. Swallowing such an error would hide that and the
 * outer commit would fail later, so these must always be rethrown to the outer retry.
 */
final class TransientDatabaseFailure
{
    public static function isTransient(Throwable $exception): bool
    {
        $concurrency = new ConcurrencyErrorDetector;
        $connection = new LostConnectionDetector;

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException
                || $concurrency->causedByConcurrencyError($current)
                || $connection->causedByLostConnection($current)) {
                return true;
            }
            // SQLSTATE class 40: transaction rollback (40001 serialization failure, 40P01 deadlock).
            if ($current instanceof QueryException && str_starts_with((string) ($current->errorInfo[0] ?? ''), '40')) {
                return true;
            }
        }

        return false;
    }
}
