<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use Closure;

/**
 * Re-entrancy flags for the apparatus <-> master vehicle sync.
 *
 * propagating(): a counterpart write made by the sync itself. The observers on both
 * models ignore these writes so a change never bounces back to the side it came from.
 * recording(): the change recorder is writing the master row and logs the change
 * itself, so the master observer must not log it a second time.
 *
 * Both flags are restored in a finally block, so an exception never leaves them set.
 */
final class MasterVehicleSyncGuard
{
    private static int $propagating = 0;

    private static int $recording = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function propagating(Closure $callback): mixed
    {
        self::$propagating++;
        try {
            return $callback();
        } finally {
            self::$propagating--;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function recording(Closure $callback): mixed
    {
        self::$recording++;
        try {
            return $callback();
        } finally {
            self::$recording--;
        }
    }

    public static function isPropagating(): bool
    {
        return self::$propagating > 0;
    }

    public static function isRecording(): bool
    {
        return self::$recording > 0;
    }
}
