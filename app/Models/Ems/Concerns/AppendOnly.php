<?php

declare(strict_types=1);

namespace App\Models\Ems\Concerns;

use LogicException;

/**
 * History rows (events, messages, stock movements, supply item audit) are written once.
 * Corrections are new rows that reference the old one.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        $name = class_basename(static::class);
        static::updating(static function () use ($name): never {
            throw new LogicException("{$name} records are append-only and cannot be changed.");
        });
        static::deleting(static function () use ($name): never {
            throw new LogicException("{$name} records are append-only and cannot be deleted.");
        });
    }
}
