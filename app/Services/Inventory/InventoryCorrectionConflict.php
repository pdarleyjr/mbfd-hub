<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use RuntimeException;

/**
 * A decision was based on a stale view: the correction or an affected inventory changed
 * since the reviewer looked. Nothing was written; the reviewer must look again.
 */
final class InventoryCorrectionConflict extends RuntimeException
{
    public const STALE_CORRECTION = 'stale_correction';

    public const STALE_PREVIEW = 'stale_preview';

    public const DEPENDENT_CHANGE = 'dependent_change';

    /** @param int|null $relatedCorrectionId the correction to open to resolve this (for example the later change to reverse first) */
    public function __construct(public readonly string $reason, string $message, public readonly ?int $relatedCorrectionId = null)
    {
        parent::__construct($message);
    }
}
