<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use RuntimeException;

/** A fuel list that cannot be read or fails validation. Messages are safe to show and never contain a PIN. */
final class FuelIdImportException extends RuntimeException {}
