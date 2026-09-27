<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class EmailBudgetExhausted extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
