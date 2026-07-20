<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

use RuntimeException;

final class FrocAiOutputException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The AI response failed controlled F-ROC validation.');
    }
}
