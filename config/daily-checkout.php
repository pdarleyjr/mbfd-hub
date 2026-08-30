<?php

declare(strict_types=1);

return [
    // A Fire Boat session may cross midnight after it was issued, but it is
    // intentionally short-lived. Operations may set a value from 1 to 24.
    'inspection_session_expiry_hours' => env('DAILY_CHECKOUT_INSPECTION_SESSION_EXPIRY_HOURS', 12),
];
