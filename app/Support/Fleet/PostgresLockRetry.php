<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Retries a unit of work when PostgreSQL reports lock_not_available (55P03). */
final class PostgresLockRetry
{
    private const ATTEMPTS = 4;

    private const BACKOFF_MICROSECONDS = 150_000;

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $exception) {
                if (! self::isLockTimeout($exception) || $attempt === self::ATTEMPTS) {
                    throw $exception;
                }

                usleep(self::BACKOFF_MICROSECONDS * $attempt);
            }
        }

        throw new \LogicException('The PostgreSQL lock retry loop exited unexpectedly.');
    }

    private static function isLockTimeout(QueryException $exception): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && (string) $exception->getCode() === '55P03';
    }
}
