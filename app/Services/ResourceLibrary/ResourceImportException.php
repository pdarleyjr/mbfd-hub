<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use RuntimeException;

/** A library import or replacement was refused. The message is written for the person running it. */
final class ResourceImportException extends RuntimeException {}
