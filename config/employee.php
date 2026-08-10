<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Temporary Password
    |--------------------------------------------------------------------------
    |
    | Retained only for backward-compatible environment parsing. Current
    | onboarding generates a unique temporary password per new employee, and
    | single-account resets require an explicit unique value.
    */
    'default_temp_password' => env('EMPLOYEE_DEFAULT_TEMP_PASSWORD', ''),
];
