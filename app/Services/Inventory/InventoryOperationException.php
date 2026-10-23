<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use RuntimeException;

/** An inventory operation cannot be applied to a checklist; `reason` is a stable code. */
final class InventoryOperationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
