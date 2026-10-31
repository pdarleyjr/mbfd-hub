<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Why a usage refresh stopped. The message is always fixed text from the refresher,
 * so it is safe to log. It never chains the HTTP exception that caused it, whose
 * message can hold the request URL.
 */
final class CloudflareUsageRefreshFailed extends RuntimeException
{
    public function __construct(
        string $reason,
        public readonly ?string $providerCall = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $causeClass = null,
    ) {
        parent::__construct($reason);
    }
}
