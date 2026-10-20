<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use DomainException;

/** A vehicle swap decision that cannot be applied; the message is safe to show a Fleet administrator. */
final class VehicleSwapDecisionException extends DomainException {}
