<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Support;

use RuntimeException;

/** Thrown inside a dashboard action's transaction when the locked record no longer matches the panel's version. */
final class StaleVersion extends RuntimeException {}
