<?php

declare(strict_types=1);

namespace App\Services\ChecklistTemplates;

use RuntimeException;

/**
 * A checklist template action that cannot go ahead (stale draft, stale preview, live checklist
 * changed, unusable baseline). The message is plain English and shown to the administrator as is.
 */
final class ChecklistTemplateException extends RuntimeException {}
