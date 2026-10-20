<?php

declare(strict_types=1);

namespace App\Services\CommandBoard;

use RuntimeException;

/**
 * A Command Board PIN API call that did not succeed. The message is written
 * for the acting administrator and never contains the service token, the PIN,
 * or a PIN hash.
 */
final class CommandBoardRequestFailed extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const TOKEN_REJECTED = 'token_rejected';

    public const BOARD_TOKEN_MISSING = 'board_token_missing';

    public const UNAVAILABLE = 'unavailable';

    public const HASH_REJECTED = 'hash_rejected';

    public const RATE_LIMITED = 'rate_limited';

    public const TIMED_OUT = 'timed_out';

    public const UNREACHABLE = 'unreachable';

    public const INVALID_RESPONSE = 'invalid_response';

    public const UNEXPECTED = 'unexpected';

    private const OUTCOME_UNKNOWN = ' Refresh the status to see whether the PIN changed before trying again.';

    private const NOT_CHANGED = ' The PIN was not changed.';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'The Command Board link isn\'t configured. Set an https COMMAND_BOARD_URL and COMMAND_BOARD_SERVICE_TOKEN in the Hub environment.',
            self::NOT_CONFIGURED,
        );
    }

    public static function forStatus(int $status, ?string $errorCode, bool $write): self
    {
        $notChanged = $write ? self::NOT_CHANGED : '';

        return match (true) {
            $status === 401 => new self(
                'The Command Board did not accept the Hub\'s service token. COMMAND_BOARD_SERVICE_TOKEN must be the same on the Hub and on the Command Board.'.$notChanged,
                self::TOKEN_REJECTED,
                $status,
            ),
            $status === 503 && $errorCode === 'service_token_not_configured' => new self(
                'The Command Board has no service token set yet, so it cannot accept requests from the Hub. Set COMMAND_BOARD_SERVICE_TOKEN on the Command Board.'.$notChanged,
                self::BOARD_TOKEN_MISSING,
                $status,
            ),
            $status === 422 => new self(
                'The Command Board rejected the PIN hash the Hub sent.'.self::NOT_CHANGED,
                self::HASH_REJECTED,
                $status,
            ),
            $status === 429 => new self(
                'The Command Board is limiting requests right now. Wait a minute and try again.'.$notChanged,
                self::RATE_LIMITED,
                $status,
            ),
            $status === 503 => new self(
                'The Command Board is temporarily unavailable. Try again in a few minutes.'.$notChanged,
                self::UNAVAILABLE,
                $status,
            ),
            default => new self(
                'The Command Board returned an unexpected error (HTTP '.$status.').'.($write ? self::OUTCOME_UNKNOWN : ''),
                self::UNEXPECTED,
                $status,
            ),
        };
    }

    public static function timedOut(int $seconds, bool $write): self
    {
        return new self(
            'The Command Board did not respond within '.$seconds.' seconds.'.($write ? self::OUTCOME_UNKNOWN : ' Try again in a minute.'),
            self::TIMED_OUT,
        );
    }

    public static function unreachable(bool $write): self
    {
        return new self(
            'The Hub could not connect to the Command Board. Check that COMMAND_BOARD_URL is correct and the board is online.'.($write ? self::NOT_CHANGED : ''),
            self::UNREACHABLE,
        );
    }

    public static function invalidResponse(bool $write): self
    {
        return new self(
            'The Command Board sent a response the Hub could not read.'.($write ? self::OUTCOME_UNKNOWN : ''),
            self::INVALID_RESPONSE,
        );
    }
}
