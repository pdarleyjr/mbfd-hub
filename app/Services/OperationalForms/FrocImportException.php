<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

use RuntimeException;

/**
 * Domain exception for F-ROC source import failures.
 *
 * Carries a stable, machine-readable failure code (never anything derived from
 * the uploaded content) so logs and audit metadata can be reasoned about
 * without ever recording raw source text, model prompts, or model responses.
 */
final class FrocImportException extends RuntimeException
{
    /**
     * @param  string  $reason  Stable failure code, e.g. "zip_too_many_entries".
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
