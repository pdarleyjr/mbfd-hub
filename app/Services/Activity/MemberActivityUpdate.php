<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Carbon\CarbonImmutable;

/**
 * The newest thing a member can read about an item: who said it, what, and when.
 * `automated` marks a receipt written by the Support Services assistant; it never counts as unread.
 */
final readonly class MemberActivityUpdate
{
    public function __construct(
        public string $excerpt,
        public string $authorLabel,
        public CarbonImmutable $at,
        public bool $automated = false,
    ) {}
}
