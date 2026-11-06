<?php

declare(strict_types=1);

namespace App\Services\Photos;

use RuntimeException;

/** A member photo whose bytes could not be decoded or converted. */
final class PhotoUnreadable extends RuntimeException {}
