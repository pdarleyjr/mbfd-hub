<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\User;

/**
 * Who may see and change Daily Checkout checklist templates (docs/checklist-template-editor.md
 * section 6). Viewing follows inventory-correction review; editing follows propagation authority,
 * because a template change reaches every apparatus on that checklist at once.
 */
final class ChecklistTemplateAccess
{
    public static function canView(?User $user): bool
    {
        return InventoryCorrectionAccess::canReview($user);
    }

    public static function canManage(?User $user): bool
    {
        return InventoryCorrectionAccess::canPropagate($user);
    }
}
