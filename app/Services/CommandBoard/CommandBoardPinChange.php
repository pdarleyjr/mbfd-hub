<?php

declare(strict_types=1);

namespace App\Services\CommandBoard;

/**
 * The outcome of a PIN change the Command Board accepted. $audited is false
 * when the Hub audit row could not be written afterwards; the PIN still changed.
 */
final class CommandBoardPinChange
{
    public function __construct(
        public readonly CommandBoardPinStatus $status,
        public readonly bool $audited,
    ) {}
}
