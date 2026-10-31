<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Mail\MailDraft;
use RuntimeException;

/**
 * Thrown when a draft was saved by someone else (usually the same author in another tab) after the version
 * the caller loaded. The current row travels with it so the page can offer to reload instead of losing work.
 */
final class MailDraftConflict extends RuntimeException
{
    public function __construct(public readonly MailDraft $current, public readonly int $expectedVersion)
    {
        parent::__construct('This draft was changed somewhere else. Reload it to see the latest version.');
    }
}
