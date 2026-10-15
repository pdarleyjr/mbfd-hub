<?php

declare(strict_types=1);

namespace App\Services\MediaControl;

use RuntimeException;

/**
 * A Media Control ingest call that did not succeed. The message is always safe
 * to show to the acting administrator; it never contains the ingest token.
 */
final class MediaControlRequestFailed extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const REJECTED = 'rejected';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'Media Control uploads are not configured. Set MEDIA_CONTROL_BASE_URL and MEDIA_CONTROL_INGEST_TOKEN.',
            self::NOT_CONFIGURED,
        );
    }

    public static function unavailable(?int $status = null): self
    {
        return new self(
            'Media Control is not responding right now. Wait a minute and try again.',
            self::UNAVAILABLE,
            $status,
        );
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }
}
