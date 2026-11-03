<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/** The member-visible record an adapter appended (for the audit trail and Activity tab). */
final readonly class ReplyRecord
{
    public function __construct(
        public string $type,
        public int $id,
    ) {}
}
