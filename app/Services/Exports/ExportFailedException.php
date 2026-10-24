<?php

declare(strict_types=1);

namespace App\Services\Exports;

use RuntimeException;

/** A user-facing reason an export was refused or could not be built (row cap, unreadable column). */
final class ExportFailedException extends RuntimeException {}
